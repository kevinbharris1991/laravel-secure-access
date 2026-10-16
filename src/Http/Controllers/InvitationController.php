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
use SecureAccess\Models\RegistrationInvitation;
use SecureAccess\Notifications\RegistrationInvitation as InvitationNotification;
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
        ]);

        $code = Str::random(64);
        RegistrationInvitation::create([
            'email' => $validated['email'],
            'token' => PendingRegistration::hashToken($code),
            'group_ids' => array_map('intval', $validated['groups']),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $validated['email'])->notify(new InvitationNotification($code));

        return redirect()->route('secure-access.invitations.index')
            ->with('status', __('Invitation sent.'));
    }

    protected function authorizeInvitations(): void
    {
        abort_unless(app(AccessControl::class)->allows(SecureAccess::guard()->user(), 'invitations.create_all'), 403);
    }
}
