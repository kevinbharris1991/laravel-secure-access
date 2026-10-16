<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\AccessGroup;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\TestCase;
use SecureAccess\Tests\Fixtures\User;

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

    public function test_director_can_create_a_pending_registration_with_selected_expiration_and_groups(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $this->administrator();
        $groups = AccessGroup::whereIn('key', ['medical-nursing', 'behavioral-support'])->pluck('id')->all();

        $this->get(route('secure-access.invitations.index'))->assertOk()->assertSee('Medical');
        $this->post(route('secure-access.invitations.store'), [
            'email' => ' Staff@Example.com ',
            'groups' => $groups,
            'expires_in' => '2_weeks',
        ])->assertRedirect(route('secure-access.invitations.index'));

        $registration = PendingRegistration::sole();
        $this->assertSame('staff@example.com', $registration->email);
        $this->assertSame($groups, $registration->group_ids);
        $this->assertNull($registration->name);
        $this->assertEquals(now()->addWeeks(2), $registration->expires_at);
        Notification::assertSentOnDemand(ActivateAccount::class, function ($notification, $channels, $notifiable) use ($registration) {
            $url = $notification->activationUrl();
            $this->assertStringContainsString('/register/activate/'.$registration->id.'/', parse_url($url, PHP_URL_PATH));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($registration->expires_at->timestamp, (int) $query['expires']);

            return $notifiable->routes['mail'] === 'staff@example.com';
        });
    }

    public function test_director_expiration_choices_are_validated_and_applied(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $this->administrator();
        $group = AccessGroup::where('key', 'medical-nursing')->value('id');

        foreach ([
            '5_minutes' => now()->addMinutes(5),
            '15_minutes' => now()->addMinutes(15),
            '1_day' => now()->addDay(),
            '2_weeks' => now()->addWeeks(2),
        ] as $choice => $expiresAt) {
            $this->post(route('secure-access.invitations.store'), [
                'email' => $choice.'@example.com',
                'groups' => [$group],
                'expires_in' => $choice,
            ])->assertRedirect(route('secure-access.invitations.index'));

            $this->assertEquals($expiresAt, PendingRegistration::where('email', $choice.'@example.com')->sole()->expires_at);
        }

        $this->post(route('secure-access.invitations.store'), [
            'email' => 'invalid@example.com',
            'groups' => [$group],
            'expires_in' => '1_hour',
        ])->assertSessionHasErrors('expires_in');
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'invalid@example.com']);
    }

    public function test_executive_director_can_create_pending_registrations(): void
    {
        Notification::fake();
        $this->administrator('executive-director');
        $group = AccessGroup::where('key', 'medical-nursing')->value('id');

        $this->post(route('secure-access.invitations.store'), [
            'email' => 'staff@example.com',
            'groups' => [$group],
            'expires_in' => '15_minutes',
        ])->assertRedirect(route('secure-access.invitations.index'));

        $this->assertSame('staff@example.com', PendingRegistration::sole()->email);
    }

    public function test_director_created_registration_collects_profile_and_assigns_selected_groups(): void
    {
        Notification::fake();
        $this->administrator();
        $group = AccessGroup::where('key', 'medical-nursing')->sole();
        $this->post(route('secure-access.invitations.store'), [
            'email' => 'staff@example.com',
            'groups' => [$group->id],
            'expires_in' => '1_day',
        ]);
        $pending = PendingRegistration::where('email', 'staff@example.com')->sole();
        $this->assertSame([$group->id], $pending->group_ids);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);

        $url = null;
        Notification::assertSentOnDemand(ActivateAccount::class, function ($notification, $channels, $notifiable) use (&$url) {
            $url = $notification->activationUrl();

            return $notifiable->routes['mail'] === 'staff@example.com';
        });

        $this->get($url)->assertOk()
            ->assertSee('name="first_name"', false)
            ->assertSee('name="terms"', false)
            ->assertSee('name="privacy"', false);

        $this->post($url, [
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'date_of_birth' => '2000-01-31',
            'gender' => 'non_binary',
            'terms' => '1',
            'privacy' => '1',
            'username' => 'staffmember',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ])->assertRedirect(route('secure-access.setup'));

        $user = User::where('username', 'staffmember')->sole();
        $this->assertSame('Staff Member', $user->name);
        $this->assertSame([$group->key], $user->accessGroups()->pluck('key')->all());
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_policy_accepted_at);
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_guests_unverified_sessions_and_guest_members_cannot_create_pending_registrations(): void
    {
        Notification::fake();
        $this->get(route('secure-access.invitations.index'))->assertRedirect(route('secure-access.login'));
        $this->administrator('guest');
        $this->get(route('secure-access.invitations.index'))->assertForbidden();
        $this->post(route('secure-access.invitations.store'), [])->assertForbidden();
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get(route('secure-access.invitations.index'))->assertRedirect(route('secure-access.login'));
        $this->assertSame(0, PendingRegistration::count());
        Notification::assertNothingSent();
    }

    public function test_registration_input_is_validated_and_revoked_permission_takes_effect(): void
    {
        Notification::fake();
        $user = $this->administrator();
        $url = route('secure-access.invitations.store');
        $this->post($url, [])->assertSessionHasErrors(['email', 'groups', 'expires_in']);
        $this->post($url, ['email' => 'staff@example.com', 'groups' => [999999], 'expires_in' => '1_day'])
            ->assertSessionHasErrors('groups.0');
        $this->post($url, ['email' => $user->email, 'groups' => [AccessGroup::first()->id], 'expires_in' => '1_day'])
            ->assertSessionHasErrors('email');
        $user->accessGroups()->detach();
        $this->post($url, [
            'email' => 'staff@example.com',
            'groups' => [AccessGroup::first()->id],
            'expires_in' => '1_day',
        ])->assertForbidden();
        $this->assertSame(0, PendingRegistration::count());
        Notification::assertNothingSent();
    }
}
