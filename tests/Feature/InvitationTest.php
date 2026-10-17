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

    protected function invitationDetails(array $attributes = []): array
    {
        return $attributes + [
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'date_of_birth' => '2000-01-31',
            'gender' => 'non_binary',
            'delivery' => 'email',
        ];
    }

    public function test_director_can_create_a_pending_registration_with_selected_expiration_and_groups(): void
    {
        Notification::fake();
        $this->travelTo(now()->startOfSecond());
        $this->administrator();
        $groups = AccessGroup::whereIn('key', ['medical-nursing', 'behavioral-support'])->pluck('id')->all();

        $this->get(route('secure-access.invitations.index'))->assertOk()->assertSee('Medical')
            ->assertSee('name="first_name"', false)
            ->assertSee('name="middle_initial"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('name="delivery"', false)
            ->assertSee('value="2_days"', false);
        $this->post(route('secure-access.invitations.store'), [
            'email' => ' Staff@Example.com ',
            'groups' => $groups,
            'expires_in' => '2_weeks',
            'middle_initial' => 'Q',
        ] + $this->invitationDetails())->assertRedirect(route('secure-access.invitations.index'))
            ->assertSessionMissing('activation_url');

        $registration = PendingRegistration::sole();
        $this->assertSame('staff@example.com', $registration->email);
        $this->assertSame($groups, $registration->group_ids);
        $this->assertSame('Staff Q Member', $registration->name);
        $this->assertSame('2000-01-31', $registration->date_of_birth->toDateString());
        $this->assertSame('non_binary', $registration->gender);
        $this->assertNull($registration->terms_accepted_at);
        $this->assertNull($registration->privacy_policy_accepted_at);
        $this->assertEquals(now()->addWeeks(2), $registration->expires_at);
        Notification::assertSentOnDemand(ActivateAccount::class, function ($notification, $channels, $notifiable) use ($registration) {
            $url = $notification->activationUrl();
            $token = basename(parse_url($url, PHP_URL_PATH));
            $this->assertNotSame($token, $registration->token);
            $this->assertTrue($registration->tokenMatches($token));
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
            '2_days' => now()->addDays(2),
            '2_weeks' => now()->addWeeks(2),
        ] as $choice => $expiresAt) {
            $this->post(route('secure-access.invitations.store'), [
                'email' => $choice.'@example.com',
                'groups' => [$group],
                'expires_in' => $choice,
            ] + $this->invitationDetails())->assertRedirect(route('secure-access.invitations.index'));

            $this->assertEquals($expiresAt, PendingRegistration::where('email', $choice.'@example.com')->sole()->expires_at);
        }

        $this->post(route('secure-access.invitations.store'), [
            'email' => 'invalid@example.com',
            'groups' => [$group],
            'expires_in' => '1_hour',
        ] + $this->invitationDetails())->assertSessionHasErrors('expires_in');
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
        ] + $this->invitationDetails())->assertRedirect(route('secure-access.invitations.index'));

        $this->assertSame('staff@example.com', PendingRegistration::sole()->email);
        $this->assertSame('Staff Member', PendingRegistration::sole()->name);
    }

    public function test_director_created_registration_uses_stored_profile_collects_consent_and_assigns_groups(): void
    {
        Notification::fake();
        $this->administrator();
        $group = AccessGroup::where('key', 'medical-nursing')->sole();
        $this->post(route('secure-access.invitations.store'), [
            'email' => 'staff@example.com',
            'groups' => [$group->id],
            'expires_in' => '1_day',
        ] + $this->invitationDetails())->assertSessionHasNoErrors();
        $pending = PendingRegistration::where('email', 'staff@example.com')->sole();
        $this->assertSame([$group->id], $pending->group_ids);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);

        $url = null;
        Notification::assertSentOnDemand(ActivateAccount::class, function ($notification, $channels, $notifiable) use (&$url) {
            $url = $notification->activationUrl();

            return $notifiable->routes['mail'] === 'staff@example.com';
        });

        SecureAccess::guard()->logout();
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get($url)->assertOk()
            ->assertSee('Staff Member')
            ->assertDontSee('name="first_name"', false)
            ->assertSee('name="terms"', false)
            ->assertSee('name="privacy"', false);

        $this->post($url, [
            'username' => 'staffmember',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ])->assertSessionHasErrors(['terms', 'privacy']);
        $this->assertDatabaseHas('pending_registrations', ['id' => $pending->id]);
        $this->assertDatabaseMissing('users', ['username' => 'staffmember']);

        $this->post($url, [
            'terms' => '1',
            'privacy' => '1',
            'username' => 'staffmember',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ])->assertRedirect(route('secure-access.register.role'));

        $user = User::where('username', 'staffmember')->sole();
        $this->assertSame('Staff Member', $user->name);
        $this->assertSame('2000-01-31', $user->date_of_birth);
        $this->assertSame('non_binary', $user->gender);
        $this->assertSame([$group->key], $user->accessGroups()->pluck('key')->all());
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_policy_accepted_at);
        $this->assertSame(0, PendingRegistration::count());
        $this->assertGuest();
        $this->assertFalse(SecureAccess::mfaVerified($user));
        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.register.role'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'employee'])
            ->assertRedirect(route('secure-access.setup'));
        $this->assertSame('employee', $user->fresh()->registration_role);
        $this->assertSame([$group->key], $user->accessGroups()->pluck('key')->all());
        $this->assertGuest();
        $this->get($url)->assertRedirect(route('secure-access.register'));
    }

    public function test_manual_delivery_returns_a_working_signed_link_without_sending_email(): void
    {
        Notification::fake();
        $this->administrator();
        $group = AccessGroup::where('key', 'medical-nursing')->value('id');
        $response = $this->post(route('secure-access.invitations.store'), [
            'email' => 'manual@example.com',
            'groups' => [$group],
            'expires_in' => '2_days',
            'delivery' => 'manual',
        ] + $this->invitationDetails())->assertRedirect(route('secure-access.invitations.index'))
            ->assertSessionHas('activation_url');

        $url = $response->getSession()->get('activation_url');
        $pending = PendingRegistration::sole();
        $this->assertTrue($pending->tokenMatches(basename(parse_url($url, PHP_URL_PATH))));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($pending->expires_at->timestamp, (int) $query['expires']);
        Notification::assertNothingSent();
        $this->get(route('secure-access.invitations.index'))->assertOk()->assertSee($url);
        $this->get(route('secure-access.invitations.index'))->assertOk()->assertDontSee($url);

        SecureAccess::guard()->logout();
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get($url.'tampered')->assertRedirect(route('secure-access.register'));
        $this->get($url)->assertOk()->assertSee('Staff Member');
        $this->post($url, [
            'username' => 'manual',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
            'terms' => '1',
            'privacy' => '1',
        ])->assertRedirect(route('secure-access.register.role'));
        $this->assertSame('Staff Member', User::where('username', 'manual')->sole()->name);
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_reinviting_rotates_the_token_and_manual_links_expire(): void
    {
        Notification::fake();
        $this->administrator();
        $data = [
            'email' => 'manual@example.com',
            'groups' => [AccessGroup::where('key', 'medical-nursing')->value('id')],
            'expires_in' => '5_minutes',
            'delivery' => 'manual',
        ] + $this->invitationDetails();
        $first = $this->post(route('secure-access.invitations.store'), $data);
        $oldUrl = $first->getSession()->get('activation_url');
        $oldToken = PendingRegistration::sole()->token;
        $second = $this->post(route('secure-access.invitations.store'), $data);
        $newUrl = $second->getSession()->get('activation_url');
        $this->assertNotSame($oldToken, PendingRegistration::sole()->token);
        $this->assertSame(1, PendingRegistration::count());
        Notification::assertNothingSent();

        SecureAccess::guard()->logout();
        $this->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => null]);
        $this->get($oldUrl)->assertRedirect(route('secure-access.register'));
        $this->get($newUrl)->assertOk();
        $this->travel(6)->minutes();
        $this->get($newUrl)->assertRedirect(route('secure-access.register'));
        $this->post($newUrl, [])->assertRedirect(route('secure-access.register'));
        $this->assertDatabaseMissing('users', ['email' => 'manual@example.com']);
    }

    public function test_invitation_profile_and_delivery_are_validated_before_storage(): void
    {
        Notification::fake();
        $this->administrator();
        $data = [
            'email' => 'invalid@example.com',
            'groups' => [AccessGroup::first()->id],
            'expires_in' => '1_day',
        ] + $this->invitationDetails();

        foreach ([
            ['first_name', ''],
            ['last_name', ''],
            ['middle_initial', 'AB'],
            ['first_name', str_repeat('A', 255)],
            ['date_of_birth', 'not-a-date'],
            ['date_of_birth', '2000-02-30'],
            ['date_of_birth', '1899-12-31'],
            ['date_of_birth', now()->subYears(12)->toDateString()],
            ['gender', 'invalid'],
            ['delivery', 'sms'],
            ['delivery', null],
        ] as [$field, $value]) {
            $error = $field === 'first_name' && $value !== '' ? 'last_name' : $field;
            $this->post(route('secure-access.invitations.store'), array_replace($data, [$field => $value]))
                ->assertSessionHasErrors($error);
            $this->assertSame(0, PendingRegistration::count());
        }
        config(['coppa.minimum_age' => 21]);
        $this->post(route('secure-access.invitations.store'), array_replace($data, [
            'date_of_birth' => now()->subYears(20)->toDateString(),
        ]))->assertSessionHasErrors('date_of_birth');
        Notification::assertNothingSent();
    }

    public function test_legacy_invitations_still_collect_profile_and_consent(): void
    {
        $pending = PendingRegistration::create([
            'email' => 'legacy@example.com',
            'token' => PendingRegistration::hashToken('legacy-token'),
            'expires_at' => now()->addDay(),
            'group_ids' => [AccessGroup::where('key', 'medical-nursing')->value('id')],
        ]);
        $url = (new ActivateAccount($pending, 'legacy-token'))->activationUrl();
        $this->get($url)->assertOk()->assertSee('name="first_name"', false)
            ->assertSee('name="terms"', false)->assertSee('name="privacy"', false);
        $this->post($url, $this->invitationDetails() + [
            'username' => 'legacy',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
            'terms' => '1',
            'privacy' => '1',
        ])->assertRedirect(route('secure-access.register.role'));
        $user = User::where('username', 'legacy')->sole();
        $this->assertSame('Staff Member', $user->name);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_policy_accepted_at);
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
        $this->post($url, [])->assertSessionHasErrors([
            'first_name', 'last_name', 'date_of_birth', 'gender', 'email', 'groups', 'expires_in', 'delivery',
        ]);
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
