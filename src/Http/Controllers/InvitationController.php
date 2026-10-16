<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use SecureAccess\Authorization\AccessControl;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\SecureAccess;
use SecureAccess\Support\UserResolver;

class InvitationController extends Controller
{
    public function index(): View
    {
        $this->authorizeInvitations();

        return view('secure-access::invitations.index', [
            'groups' => AccessGroup::query()->orderBy('name')->get(),
            'genders' => (array) config('secure-access.registration.genders', []),
            'minimumAge' => $this->minimumAge(),
            'latestBirthDate' => $this->latestBirthDate()->toDateString(),
        ]);
    }

    public function store(Request $request, UserResolver $users): RedirectResponse
    {
        $this->authorizeInvitations();

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'max:1'],
            'last_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) use ($request) {
                $name = implode(' ', array_filter([
                    $request->input('first_name'),
                    $request->input('middle_initial'),
                    $value,
                ], fn ($part) => is_string($part) && $part !== ''));

                if (mb_strlen($name) > 255) {
                    $fail(__('The full name may not be greater than 255 characters.'));
                }
            }],
            'date_of_birth' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', function ($attribute, $value, $fail) {
                if (Carbon::createFromFormat('!Y-m-d', $value)->greaterThan($this->latestBirthDate())) {
                    $fail(__('You must be at least :age years old to register.', ['age' => $this->minimumAge()]));
                }
            }],
            'gender' => ['required', 'string', Rule::in(array_map('strval', array_keys((array) config('secure-access.registration.genders', []))))],
            'email' => ['required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) use ($users) {
                if ($users->emailInUse($value) || $users->find($value)) {
                    $fail(__('This email address is already in use.'));
                }
            }],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['required', 'integer', 'distinct', Rule::exists('secure_access_groups', 'id')],
            'expires_in' => ['required', Rule::in(['5_minutes', '15_minutes', '1_day', '2_days', '2_weeks'])],
            'delivery' => ['required', Rule::in(['email', 'manual'])],
        ]);

        $token = Str::random(64);
        $expiresAt = match ($validated['expires_in']) {
            '5_minutes' => now()->addMinutes(5),
            '15_minutes' => now()->addMinutes(15),
            '1_day' => now()->addDay(),
            '2_days' => now()->addDays(2),
            '2_weeks' => now()->addWeeks(2),
        };
        $registration = PendingRegistration::query()->updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => implode(' ', array_filter([
                    $validated['first_name'],
                    $validated['middle_initial'] ?? null,
                    $validated['last_name'],
                ], fn ($part) => $part !== null && $part !== '')),
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'token' => PendingRegistration::hashToken($token),
                'terms_accepted_at' => null,
                'privacy_policy_accepted_at' => null,
                'expires_at' => $expiresAt,
                'group_ids' => array_map('intval', $validated['groups']),
            ]
        );

        $notification = new ActivateAccount($registration, $token);

        if ($validated['delivery'] === 'manual') {
            return redirect()->route('secure-access.invitations.index')
                ->with('status', __('Registration link created. Share it securely with the person being invited.'))
                ->with('activation_url', $notification->activationUrl());
        }

        Notification::route('mail', $registration->email)->notify($notification);

        return redirect()->route('secure-access.invitations.index')
            ->with('status', __('Registration link sent.'));
    }

    protected function authorizeInvitations(): void
    {
        abort_unless(app(AccessControl::class)->allows(SecureAccess::guard()->user(), 'invitations.create_all'), 403);
    }

    protected function minimumAge(): int
    {
        return max(0, (int) config('coppa.minimum_age', 13));
    }

    protected function latestBirthDate(): Carbon
    {
        return Carbon::today()->subYearsNoOverflow($this->minimumAge());
    }
}
