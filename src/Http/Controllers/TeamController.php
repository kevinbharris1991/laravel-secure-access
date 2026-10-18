<?php

namespace SecureAccess\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use SecureAccess\Models\TeamAuthorityDocument;
use SecureAccess\Models\TeamInvitation;
use SecureAccess\Models\TeamMember;
use SecureAccess\SecureAccess;
use SecureAccess\Services\TeamInvitationService;
use SecureAccess\Services\AuthorityReviewService;

class TeamController extends Controller
{
    public function index(TeamInvitationService $invitations): Response
    {
        $user = $this->authorizeTeamAccess();
        $role = $user->getAttribute('registration_role');
        $team = TeamMember::query()
            ->whereNull('ended_at')
            ->where(fn ($query) => $query->where('self_advocate_id', (string) $user->getKey())
                ->orWhere('team_member_id', (string) $user->getKey()))
            ->with('authorityDocuments')
            ->get()
            ->map(function (TeamMember $member) use ($user, $role, $invitations) {
                app(AuthorityReviewService::class)->expire($member, $user);
                $member->load('authorityDocuments');
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
                            'status' => $document->status,
                            'status_label' => $document->statusLabel(),
                        ])
                        ->all(),
                ];
            })
            ->filter(fn (array $member) => $member['name'] !== __('Team member'))
            ->values();

        return response()->view('secure-access::team.index', [
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
            'endedAuthorityDocuments' => TeamAuthorityDocument::query()
                ->whereHas('relationship', fn ($query) => $query->whereNotNull('ended_at')
                    ->where(fn ($parties) => $parties->where('self_advocate_id', (string) $user->getKey())
                        ->orWhere('team_member_id', (string) $user->getKey())))
                ->latest()->paginate(25, ['*'], 'authority_history_page')->withQueryString(),
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function storeAuthorityDocument(Request $request, TeamMember $relationship): RedirectResponse
    {
        $user = $this->authorizeTeamAccess();
        $this->authorizeAuthorityUpload($user, $relationship);

        $validated = $request->validate([
            'type' => ['required', Rule::in(TeamAuthorityDocument::TYPES)],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'previous_document_id' => ['nullable', 'integer', 'min:1'],
        ]);

        app(AuthorityReviewService::class)->submit($user, $relationship, $validated, $request->file('document'));

        return redirect()->route('secure-access.team.index')->with('status', __('Authority document uploaded.'));
    }

    public function downloadAuthorityDocument(
        TeamMember $relationship,
        TeamAuthorityDocument $document
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $user = SecureAccess::guard()->user();
        abort_unless($user instanceof Model, 403);
        $reviews = app(AuthorityReviewService::class);
        abort_unless((string) $document->team_member_id === (string) $relationship->getKey()
            && $reviews->canView($user, $document), 404);
        $reviews->expire($relationship, $user);

        abort_unless(Storage::disk('local')->exists($document->path), 404);

        $reviews->audit($document, $user, 'downloaded');

        return Storage::disk('local')->download($document->path, 'authority-document.'.pathinfo($document->file_name, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
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

            $relationship = TeamMember::query()->firstOrCreate(
                [
                    'self_advocate_id' => (string) $selfAdvocate->getKey(),
                    'team_member_id' => (string) $teamMember->getKey(),
                ],
                ['team_member_role' => $teamMember->getAttribute('registration_role')]
            );
            $relationship = TeamMember::query()->whereKey($relationship->getKey())->lockForUpdate()->firstOrFail();
            $relationship->forceFill([
                'ended_at' => null, 'team_member_role' => $teamMember->getAttribute('registration_role'),
            ])->save();

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

        app(AuthorityReviewService::class)->endRelationship($user, $relationship);

        return redirect()->route('secure-access.team.index')
            ->with('status', __('Website relationship ended. Legal authority has not been changed.'));
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
            $relationship->ended_at === null
                && in_array($role, ['family_caregiver', 'professional'], true)
                && $relationship->team_member_id === (string) $user->getKey()
                && $relationship->team_member_role === $role,
            404
        );
    }

}
