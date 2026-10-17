<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use SecureAccess\Models\TeamAuthorityDocument;
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
            ->with('authorityDocuments')
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
                    'can_attach_authority' => ! $isSelfAdvocate
                        && in_array($role, ['family_caregiver', 'professional'], true)
                        && $member->team_member_role === $role,
                    'authority_documents' => $member->authorityDocuments
                        ->map(fn (TeamAuthorityDocument $document) => [
                            'id' => $document->getKey(),
                            'type' => $document->type,
                            'name' => $document->file_name,
                        ])
                        ->all(),
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
                ->get()
                ->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->getKey(),
                    'inviter_name' => $invitations->user($invitation->inviter_id)?->getAttribute('name') ?: __('A team member'),
                ]),
            'outgoing' => TeamInvitation::query()
                ->where('inviter_id', (string) $user->getKey())
                ->where('status', TeamInvitation::STATUS_PENDING)
                ->latest()
                ->get(),
            'authorityDocumentTypes' => TeamAuthorityDocument::TYPES,
        ]);
    }

    public function storeAuthorityDocument(Request $request, TeamMember $relationship): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        $this->authorizeAuthorityUpload($user, $relationship);

        $validated = $request->validate([
            'type' => ['required', Rule::in(TeamAuthorityDocument::TYPES)],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('document');
        $path = $file->store('secure-access/authority-documents', 'local');
        $fileName = Str::ascii(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $fileName = Str::slug($fileName) ?: 'authority-document';
        $fileName .= '.'.$file->extension();

        try {
            TeamAuthorityDocument::query()->create([
                'team_member_id' => $relationship->getKey(),
                'type' => $validated['type'],
                'path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('secure-access.team.index')->with('status', __('Authority document uploaded.'));
    }

    public function downloadAuthorityDocument(
        TeamMember $relationship,
        TeamAuthorityDocument $document
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $user = $this->authorizeTeamAccess();
        $this->authorizeAuthorityDocumentAccess($user, $relationship, $document);

        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->file_name);
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
        DB::transaction(function () use ($invitation, $user) {
            $invitation = TeamInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(
                $invitation->status === TeamInvitation::STATUS_PENDING
                    && $invitation->invitee_id === (string) $user->getKey(),
                404
            );

            $invitation->forceFill(['status' => TeamInvitation::STATUS_DECLINED])->save();
        });

        return redirect()->route('secure-access.team.index')->with('status', __('Invitation declined.'));
    }

    public function destroy(TeamMember $relationship): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        abort_unless(in_array((string) $user->getKey(), [
            $relationship->self_advocate_id,
            $relationship->team_member_id,
        ], true), 404);

        DB::transaction(function () use ($relationship) {
            $documents = TeamAuthorityDocument::query()->where('team_member_id', $relationship->getKey())->get();
            foreach ($documents as $document) {
                Storage::disk('local')->delete($document->path);
            }
            $relationship->delete();
        });

        return redirect()->route('secure-access.team.index')->with('status', __('Team member removed.'));
    }

    protected function authorizeTeamAccess(): Model
    {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model
            && in_array($user->getAttribute('registration_role'), TeamInvitationService::ROLES, true), 403);

        return $user;
    }

    protected function authorizeAuthorityUpload(Model $user, TeamMember $relationship): void
    {
        $role = $user->getAttribute('registration_role');
        abort_unless(
            in_array($role, ['family_caregiver', 'professional'], true)
                && $relationship->team_member_id === (string) $user->getKey()
                && $relationship->team_member_role === $role,
            404
        );
    }

    protected function authorizeAuthorityDocumentAccess(
        Model $user,
        TeamMember $relationship,
        TeamAuthorityDocument $document
    ): void {
        $userId = (string) $user->getKey();
        $role = $user->getAttribute('registration_role');
        $isSelfAdvocate = $userId === $relationship->self_advocate_id && $role === 'self_advocate';
        $isTeamMember = $userId === $relationship->team_member_id
            && $role === $relationship->team_member_role
            && in_array($role, ['family_caregiver', 'professional'], true);

        abort_unless(
            (string) $document->team_member_id === (string) $relationship->getKey()
                && ($isSelfAdvocate || $isTeamMember),
            404
        );
    }
}
