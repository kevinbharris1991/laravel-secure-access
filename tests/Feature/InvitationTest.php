<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Models\RegistrationInvitation;
use SecureAccess\Notifications\RegistrationInvitation as InvitationNotification;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\TestCase;

class InvitationTest extends TestCase
{
    protected function administrator(string $key = 'administrative-director')
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $user->accessGroups()->attach(AccessGroup::where('key', $key)->sole());
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);

        return $user;
    }

    public function test_administrative_director_can_email_an_invitation_with_multiple_groups(): void
    {
        Notification::fake();
        $this->administrator();
        $groups = AccessGroup::whereIn('key', ['medical-nursing', 'behavioral-support'])->pluck('id')->all();
        $this->get(route('secure-access.invitations.index'))->assertOk()->assertSee('Medical');
        $this->post(route('secure-access.invitations.store'), [
            'email' => ' Staff@Example.com ',
            'groups' => $groups,
        ])->assertRedirect(route('secure-access.invitations.index'));

        $invitation = RegistrationInvitation::sole();
        $this->assertSame('staff@example.com', $invitation->email);
        $this->assertSame($groups, $invitation->group_ids);
        $this->assertNull($invitation->accepted_at);
        Notification::assertSentOnDemand(InvitationNotification::class, function ($notification, $channels, $notifiable) use ($invitation) {
            $this->assertSame($invitation->token, PendingRegistration::hashToken($notification->code));
            $this->assertNotSame($invitation->token, $notification->code);
            $this->assertStringContainsString(route('secure-access.register'), $notification->toMail($notifiable)->actionUrl);

            return $notifiable->routes['mail'] === 'staff@example.com';
        });
    }

    public function test_guests_unverified_sessions_and_guest_members_cannot_invite(): void
    {
        Notification::fake();
        $this->get(route('secure-access.invitations.index'))->assertRedirect(route('secure-access.login'));
        $user = $this->administrator('guest');
        $this->get(route('secure-access.invitations.index'))->assertForbidden();
        $this->post(route('secure-access.invitations.store'), [])->assertForbidden();
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get(route('secure-access.invitations.index'))->assertRedirect(route('secure-access.login'));
        $this->assertSame(0, RegistrationInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_invitation_input_is_validated_and_revoked_permission_takes_effect(): void
    {
        Notification::fake();
        $user = $this->administrator();
        $url = route('secure-access.invitations.store');
        $this->post($url, [])->assertSessionHasErrors(['email', 'groups']);
        $this->post($url, ['email' => 'staff@example.com', 'groups' => [999999]])
            ->assertSessionHasErrors('groups.0');
        $this->post($url, ['email' => $user->email, 'groups' => [AccessGroup::first()->id]])
            ->assertSessionHasErrors('email');
        $user->accessGroups()->detach();
        $this->post($url, ['email' => 'staff@example.com', 'groups' => [AccessGroup::first()->id]])
            ->assertForbidden();
        $this->assertSame(0, RegistrationInvitation::count());
        Notification::assertNothingSent();
    }
}
