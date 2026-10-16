<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        ]);
    }

    public function store(Request $request, UserResolver $users): RedirectResponse
    {
        $this->authorizeInvitations();

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) use ($users) {
                if ($users->emailInUse($value) || $users->find($value)) {
                    $fail(__('This email address is already in use.'));
                }
            }],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['required', 'integer', 'distinct', Rule::exists('secure_access_groups', 'id')],
            'expires_in' => ['required', Rule::in(['5_minutes', '15_minutes', '1_day', '2_weeks'])],
        ]);

        $token = Str::random(64);
        $expiresAt = match ($validated['expires_in']) {
            '5_minutes' => now()->addMinutes(5),
            '15_minutes' => now()->addMinutes(15),
            '1_day' => now()->addDay(),
            '2_weeks' => now()->addWeeks(2),
        };
        $registration = PendingRegistration::query()->updateOrCreate(
            ['email' => $validated['email']],
            [
                'name' => null,
                'date_of_birth' => null,
                'gender' => null,
                'token' => PendingRegistration::hashToken($token),
                'terms_accepted_at' => null,
                'privacy_policy_accepted_at' => null,
                'expires_at' => $expiresAt,
                'group_ids' => array_map('intval', $validated['groups']),
            ]
        );

        Notification::route('mail', $registration->email)->notify(
            new ActivateAccount($registration, $token)
        );

        return redirect()->route('secure-access.invitations.index')
            ->with('status', __('Registration link sent.'));
    }

    protected function authorizeInvitations(): void
    {
        abort_unless(app(AccessControl::class)->allows(SecureAccess::guard()->user(), 'invitations.create_all'), 403);
    }
}
