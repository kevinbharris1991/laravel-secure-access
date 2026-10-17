<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use SecureAccess\Models\TeamInvitation;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Services\TeamInvitationService;

class TeamController extends Controller
{
    public function index(TeamInvitationService $invitations): View
    {
        $user = $this->authorizeTeamAccess();
        $role = $user->getAttribute('registration_role');
        $team = TeamMember::query()
            ->where('self_advocate_id', (string) $user->getKey())
            ->orWhere('team_member_id', (string) $user->getKey())
            ->get()
            ->map(function (TeamMember $member) use ($user, $role, $invitations) {
                $isSelfAdvocate = $member->self_advocate_id === (string) $user->getKey();
                $otherUser = $invitations->user($isSelfAdvocate ? $member->team_member_id : $member->self_advocate_id);
                $otherRole = $isSelfAdvocate ? $member->team_member_role : 'self_advocate';
                $label = $role === 'self_advocate'
                    ? ($otherRole === 'professional' ? __('Staff member') : __('Loved one'))
                    : ($role === 'professional' ? __('Client') : __('Loved one'));

                return [
                    'id' => $member->getKey(),
                    'name' => $otherUser?->getAttribute('name') ?: __('Team member'),
                    'role' => $otherRole,
                    'label' => $label,
                ];
            })
            ->filter(fn (array $member) => $member['name'] !== __('Team member'))
            ->values();

        return view('secure-access::team.index', [
            'role' => $role,
            'inviteeRoles' => $invitations->allowedInviteeRoles($role),
            'team' => $team,
            'incoming' => TeamInvitation::query()
                ->where('invitee_id', (string) $user->getKey())
                ->where('status', TeamInvitation::STATUS_PENDING)
                ->latest()
                ->get(),
            'outgoing' => TeamInvitation::query()
                ->where('inviter_id', (string) $user->getKey())
                ->where('status', TeamInvitation::STATUS_PENDING)
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, TeamInvitationService $invitations): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        $isSelfAdvocate = $user->getAttribute('registration_role') === 'self_advocate';

        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'invitee_role' => $isSelfAdvocate
                ? ['required', Rule::in($invitations->allowedInviteeRoles('self_advocate'))]
                : ['prohibited'],
        ]);

        $inviteeRole = $isSelfAdvocate
            ? ($validated['invitee_role'] ?? '')
            : 'self_advocate';

        try {
            $invitations->invite($user, $validated['email'], $inviteeRole);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['email' => __($exception->getMessage())])->withInput();
        }

        return redirect()->route('secure-access.team.index')->with('status', __('Invitation sent.'));
    }

    public function accept(TeamInvitation $invitation, TeamInvitationService $invitations): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();

        DB::transaction(function () use ($invitation, $user, $invitations) {
            $invitation = TeamInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(
                $invitation->status === TeamInvitation::STATUS_PENDING
                    && $invitation->invitee_id === (string) $user->getKey(),
                404
            );

            $inviter = $invitations->user($invitation->inviter_id);
            abort_unless(
                $inviter
                    && $user->getAttribute('registration_role') === $invitation->invitee_role
                    && $inviter->getAttribute('registration_role') === $invitation->inviter_role
                    && in_array($invitation->invitee_role, $invitations->allowedInviteeRoles($invitation->inviter_role), true),
                403
            );

            $selfAdvocate = $invitation->inviter_role === 'self_advocate' ? $inviter : $user;
            $teamMember = $invitation->inviter_role === 'self_advocate' ? $user : $inviter;

            TeamMember::query()->firstOrCreate(
                [
                    'self_advocate_id' => (string) $selfAdvocate->getKey(),
                    'team_member_id' => (string) $teamMember->getKey(),
                ],
                ['team_member_role' => $teamMember->getAttribute('registration_role')]
            );

            $invitation->forceFill(['status' => TeamInvitation::STATUS_ACCEPTED])->save();
        });

        return redirect()->route('secure-access.team.index')->with('status', __('Invitation accepted.'));
    }

    public function decline(TeamInvitation $invitation): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        abort_unless(
            $invitation->status === TeamInvitation::STATUS_PENDING
                && $invitation->invitee_id === (string) $user->getKey(),
            404
        );

        $invitation->forceFill(['status' => TeamInvitation::STATUS_DECLINED])->save();

        return redirect()->route('secure-access.team.index')->with('status', __('Invitation declined.'));
    }

    public function destroy(TeamMember $relationship): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        abort_unless(in_array((string) $user->getKey(), [
            $relationship->self_advocate_id,
            $relationship->team_member_id,
        ], true), 404);

        $relationship->delete();

        return redirect()->route('secure-access.team.index')->with('status', __('Team member removed.'));
    }

    protected function authorizeTeamAccess(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model
            && in_array($user->getAttribute('registration_role'), TeamInvitationService::ROLES, true), 403);

        return $user;
    }
}
