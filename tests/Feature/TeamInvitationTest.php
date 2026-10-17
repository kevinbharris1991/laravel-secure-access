<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\TeamInvitation;
use SecureAccess\Models\TeamMember;
use SecureAccess\Notifications\TeamInvitationNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Services\TeamInvitationService;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class TeamInvitationTest extends TestCase
{
    protected function teamUser(string $username, string $role, ?string $email = null): User
    {
        $user = $this->createUser([
            'username' => $username,
            'email' => $email ?? $username.'@example.com',
            'name' => ucfirst($username),
            'registration_role' => $role,
        ]);
        $this->actingAs($user)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->getKey(),
        ]);

        return $user;
    }

    public function test_self_advocate_can_invite_a_new_professional_by_email_only(): void
    {
        Notification::fake();
        $selfAdvocate = $this->teamUser('advocate', 'self_advocate');

        $this->get(route('secure-access.team.index'))
            ->assertOk()
            ->assertSee('type="email"', false)
            ->assertSee('name="invitee_role"', false)
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="date_of_birth"', false);

        $this->post(route('secure-access.team.store'), [
            'email' => ' NEW.PROFESSIONAL@example.com ',
            'invitee_role' => 'professional',
        ])->assertRedirect(route('secure-access.team.index'));

        $invitation = TeamInvitation::sole();
        $this->assertSame((string) $selfAdvocate->getKey(), $invitation->inviter_id);
        $this->assertSame('new.professional@example.com', $invitation->invitee_email);
        $this->assertNull($invitation->invitee_id);
        $this->assertSame('professional', $invitation->invitee_role);
        $this->assertSame(TeamInvitation::STATUS_PENDING, $invitation->status);
        Notification::assertSentOnDemand(TeamInvitationNotification::class, function ($notification, $channels, $notifiable) use ($invitation) {
            return $notification->invitation->is($invitation)
                && $notifiable->routes['mail'] === 'new.professional@example.com';
        });
    }

    public function test_registered_invitation_is_accepted_and_each_member_can_remove_the_relationship(): void
    {
        Notification::fake();
        $professional = $this->teamUser('professional', 'professional');
        $selfAdvocate = $this->createUser([
            'username' => 'client',
            'email' => 'client@example.com',
            'name' => 'Client Name',
            'registration_role' => 'self_advocate',
        ]);

        $this->post(route('secure-access.team.store'), ['email' => $selfAdvocate->email])
            ->assertRedirect(route('secure-access.team.index'));
        $invitation = TeamInvitation::sole();
        $this->assertSame((string) $selfAdvocate->getKey(), $invitation->invitee_id);
        Notification::assertSentOnDemand(TeamInvitationNotification::class, fn ($notification, $channels, $notifiable) =>
            $notifiable->routes['mail'] === $selfAdvocate->email);

        $this->actingAs($selfAdvocate)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $selfAdvocate->getKey(),
        ]);
        $this->get(route('secure-access.team.index'))->assertOk()->assertSee('invitations/'.$invitation->id.'/accept');
        $this->post(route('secure-access.team.invitations.accept', $invitation))
            ->assertRedirect(route('secure-access.team.index'));

        $relationship = TeamMember::sole();
        $this->assertSame((string) $selfAdvocate->getKey(), $relationship->self_advocate_id);
        $this->assertSame((string) $professional->getKey(), $relationship->team_member_id);
        $this->assertSame('professional', $relationship->team_member_role);

        $this->actingAs($professional)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $professional->getKey(),
        ]);
        $this->get(route('secure-access.team.index'))->assertOk()->assertSee('Client: Client Name');
        $this->delete(route('secure-access.team.relationships.destroy', $relationship))
            ->assertRedirect(route('secure-access.team.index'));
        $this->assertSame(0, TeamMember::count());
        $this->assertSame(TeamInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
    }

    public function test_unregistered_invitation_creates_a_team_link_after_registration_role_selection(): void
    {
        Notification::fake();
        $selfAdvocate = $this->teamUser('advocate', 'self_advocate');
        $this->post(route('secure-access.team.store'), [
            'email' => 'caregiver@example.com',
            'invitee_role' => 'family_caregiver',
        ])->assertRedirect(route('secure-access.team.index'));
        $invitation = TeamInvitation::sole();
        $caregiver = $this->createUser([
            'username' => 'caregiver',
            'email' => 'caregiver@example.com',
            'registration_role' => 'family_caregiver',
        ]);

        app(TeamInvitationService::class)->resolvePendingInvitations($caregiver, 'family_caregiver');

        $this->assertSame((string) $caregiver->getKey(), $invitation->fresh()->invitee_id);
        $this->assertSame(TeamInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame((string) $selfAdvocate->getKey(), TeamMember::sole()->self_advocate_id);
        $this->assertSame((string) $caregiver->getKey(), TeamMember::sole()->team_member_id);
        Notification::assertSentOnDemand(TeamInvitationNotification::class, fn ($notification, $channels, $notifiable) =>
            $notifiable->routes['mail'] === $caregiver->email);
    }

    public function test_users_cannot_invite_an_incompatible_account_or_accept_another_users_invitation(): void
    {
        Notification::fake();
        $professional = $this->teamUser('professional', 'professional');
        $otherProfessional = $this->createUser([
            'username' => 'other-professional',
            'email' => 'other@example.com',
            'registration_role' => 'professional',
        ]);

        $this->post(route('secure-access.team.store'), ['email' => $otherProfessional->email])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, TeamInvitation::count());

        $selfAdvocate = $this->createUser([
            'username' => 'client',
            'email' => 'client@example.com',
            'registration_role' => 'self_advocate',
        ]);
        $this->post(route('secure-access.team.store'), ['email' => $selfAdvocate->email])
            ->assertSessionHasNoErrors();
        $invitation = TeamInvitation::sole();

        $this->actingAs($otherProfessional)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $otherProfessional->getKey(),
        ]);
        $this->post(route('secure-access.team.invitations.accept', $invitation))->assertNotFound();
        $this->assertSame(TeamInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertSame((string) $professional->getKey(), $invitation->inviter_id);
        $this->assertSame(0, TeamMember::count());

        $this->actingAs($selfAdvocate)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $selfAdvocate->getKey(),
        ]);
        $this->delete(route('secure-access.team.invitations.decline', $invitation))
            ->assertRedirect(route('secure-access.team.index'));
        $this->assertSame(TeamInvitation::STATUS_DECLINED, $invitation->fresh()->status);
        $this->assertSame(0, TeamMember::count());
    }

    public function test_only_supported_registration_roles_can_access_the_team_page(): void
    {
        $user = $this->teamUser('other-user', 'other');

        $this->get(route('secure-access.dashboard'))->assertOk()->assertDontSee(route('secure-access.team.index'));
        $this->get(route('secure-access.team.index'))->assertForbidden();
        $this->assertSame('other', $user->registration_role);
    }
}
