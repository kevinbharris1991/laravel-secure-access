<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Models\UserEmail;
use SecureAccess\Notifications\ActivateAccount;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class RegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function registration(array $attributes = [])
    {
        return $this->post(route('secure-access.register.store'), $attributes + [
            'name' => 'New User',
            'date_of_birth' => '1990-05-15',
            'email' => 'new@example.com',
            'gender' => 'Non-binary',
            'terms' => '1',
            'privacy_policy' => '1',
        ]);
    }

    protected function activationUrl(): string
    {
        return Notification::sentOnDemand(ActivateAccount::class)->last()->activationUrl();
    }

    protected function activate(array $attributes = [], ?string $url = null)
    {
        return $this->post($url ?? $this->activationUrl(), $attributes + [
            'username' => 'newuser',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ]);
    }

    public function test_registration_page_renders(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Register')
            ->assertSee('name="name"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('name="terms"', false)
            ->assertSee('name="privacy_policy"', false)
            ->assertDontSee('name="username"', false)
            ->assertDontSee('name="password"', false)
            ->assertSee(route('secure-access.register.store'));
    }

    public function test_registration_emails_an_activation_link_without_creating_a_user(): void
    {
        $this->registration([
            'email' => ' New@Example.com ',
            'username' => 'ignored',
            'password' => 'ignored',
            'locked_at' => now(),
            'email_verified_at' => now(),
        ])->assertRedirect(route('secure-access.register.sent'));

        $this->assertSame(0, User::count());
        $registration = PendingRegistration::sole();
        $this->assertSame('New User', $registration->name);
        $this->assertSame('1990-05-15', $registration->date_of_birth->toDateString());
        $this->assertSame('Non-binary', $registration->gender);
        $this->assertSame('new@example.com', $registration->email);
        $this->assertArrayNotHasKey('password', $registration->getAttributes());
        $this->assertGuest();
        $this->assertNull(session('secure_access.pending'));
        Notification::assertSentOnDemand(ActivateAccount::class, function ($notification, $channels, $notifiable) {
            $this->assertSame(['mail'], $channels);
            $this->assertSame('new@example.com', $notifiable->routes['mail']);
            $this->assertSame($this->activationUrl(), $notification->activationUrl());
            $this->assertSame($notification->activationUrl(), $notification->toMail($notifiable)->actionUrl);

            return true;
        });

        $this->get(route('secure-access.register.sent'))->assertOk()->assertSee('Check Your Email');
        $this->passwordStep('new@example.com', 'ignored')->assertSessionHasErrors('identifier');
        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
    }

    public function test_activation_requires_mfa_enrollment_before_authentication(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $this->flushSession();
        $this->get($url)->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('Continue to MFA Setup');
        $this->assertSame(1, PendingRegistration::count());
        $this->activate([
            'username' => ' NewUser ',
            'email' => 'attacker@example.com',
            'name' => 'Ignored',
            'date_of_birth' => '2020-01-01',
            'gender' => 'Ignored',
            'locked_at' => now(),
        ], $url)->assertRedirect(route('secure-access.setup'));

        $user = User::sole();
        $this->assertSame('New User', $user->name);
        $this->assertSame('1990-05-15', $user->date_of_birth);
        $this->assertSame('Non-binary', $user->gender);
        $this->assertSame('newuser', $user->username);
        $this->assertSame('new@example.com', $user->email);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->locked_at);
        $this->assertGuest();
        $this->assertFalse(SecureAccess::mfaVerified($user));
        $this->assertSame((string) $user->id, session('secure_access.pending.user_id'));
        $this->assertSame(0, PendingRegistration::count());

        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.security'))->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.setup'))->assertOk();
        $this->get(route('secure-access.setup.create', 'totp'))->assertOk();
        $secret = session('secure_access.setup.totp')['secret'];

        $this->post(route('secure-access.setup.store', 'totp'), ['code' => 'invalid'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('secure-access.setup.store', 'totp'), ['code' => $this->currentTotp($secret)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(SecureAccess::mfaVerified($user));
        $this->assertNull(session('secure_access.pending'));
        $this->get('/dashboard')->assertOk();
    }

    public function test_duplicate_identifiers_are_rejected_case_insensitively(): void
    {
        $this->createUser();

        $this->registration(['email' => 'TAYLOR@example.com'])->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertGuest();
        $this->assertNull(session('secure_access.pending'));
        $this->registration();
        $this->activate(['username' => 'TAYLOR'])->assertSessionHasErrors('username');
        $this->assertSame(1, User::count());
    }

    public function test_verified_additional_email_cannot_be_registered(): void
    {
        $user = $this->createUser();
        UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com', 'verified_at' => now()]);

        $this->registration(['email' => 'WORK@example.com'])->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    public function test_usernames_cannot_shadow_an_existing_email_identifier(): void
    {
        $this->createUser();

        $this->registration();
        $this->activate(['username' => 'taylor@example.com'])->assertSessionHasErrors('username');
        $this->assertSame(1, User::count());
    }

    public function test_password_confirmation_and_required_fields_are_validated(): void
    {
        $this->post(route('secure-access.register.store'), [])
            ->assertSessionHasErrors(['name', 'date_of_birth', 'email', 'gender']);
        $this->registration();
        $this->activate(['password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->activate(['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->activate(['password' => 'onlylowercaseletters', 'password_confirmation' => 'onlylowercaseletters'])
            ->assertSessionHasErrors('password');
        $this->post($this->activationUrl(), [])->assertSessionHasErrors(['username', 'password']);
        $this->assertSame(0, User::count());
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_invalid_identifier_types_are_validation_errors(): void
    {
        $this->registration(['email' => ['new@example.com']])->assertSessionHasErrors('email');
        $this->registration();
        $this->activate(['username' => ['newuser']])->assertSessionHasErrors('username');
        $this->assertSame(0, User::count());
    }

    public function test_birth_date_and_gender_are_validated(): void
    {
        foreach (['not-a-date', '2000-02-30', today()->toDateString(), today()->addDay()->toDateString()] as $date) {
            $this->registration(['date_of_birth' => $date])->assertSessionHasErrors('date_of_birth');
        }

        $this->registration(['gender' => ['invalid']])->assertSessionHasErrors('gender');
        $this->registration(['gender' => str_repeat('a', 256)])->assertSessionHasErrors('gender');
        $this->assertSame(0, PendingRegistration::count());
        Notification::assertNothingSent();
    }

    public function test_registration_requires_agreement_to_both_policies(): void
    {
        foreach ([null, '0', 'no', 'false'] as $declined) {
            $this->registration(['terms' => $declined])->assertSessionHasErrors('terms');
            $this->registration(['privacy_policy' => $declined])->assertSessionHasErrors('privacy_policy');
        }

        $this->assertSame(0, PendingRegistration::count());
        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_registration_uses_configured_policy_links(): void
    {
        config([
            'secure-access.registration.terms_url' => '/legal/terms',
            'secure-access.registration.privacy_url' => '/legal/privacy',
        ]);

        $this->get(route('secure-access.register'))->assertOk()
            ->assertSee('href="/legal/terms"', false)
            ->assertSee('href="/legal/privacy"', false);
    }

    public function test_application_password_defaults_are_respected(): void
    {
        $defaults = Password::$defaultCallback;
        Password::defaults(fn () => Password::min(24));

        try {
            $this->registration();
            $this->activate()->assertSessionHasErrors('password');
            $this->assertSame(0, User::count());
        } finally {
            Password::$defaultCallback = $defaults;
        }
    }

    public function test_configured_model_and_identifier_columns_are_used(): void
    {
        Schema::rename('users', 'members');
        Schema::table('members', function ($table) {
            $table->renameColumn('username', 'handle');
            $table->renameColumn('email', 'email_address');
        });
        config([
            'secure-access.user_model' => RegistrationUser::class,
            'secure-access.username_column' => 'handle',
            'secure-access.email_column' => 'email_address',
        ]);

        $this->registration()->assertRedirect(route('secure-access.register.sent'));
        $this->activate()->assertRedirect(route('secure-access.setup'));
        $user = RegistrationUser::sole();
        $this->assertSame('newuser', $user->handle);
        $this->assertSame('new@example.com', $user->email_address);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));

        $this->registration(['email' => 'NEW@example.com'])->assertSessionHasErrors('email');
        $this->passwordStep('NEWUSER', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_name_and_username_are_omitted_when_the_columns_do_not_exist(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['name', 'username']);
        });

        $this->get(route('secure-access.register'))->assertOk()
            ->assertSee('name="name"', false)
            ->assertDontSee('name="username"', false);
        $this->registration()->assertRedirect(route('secure-access.register.sent'));
        $this->get($this->activationUrl())->assertOk()->assertDontSee('name="username"', false);
        $this->activate(['username' => null])->assertRedirect(route('secure-access.setup'));
        $this->passwordStep('new@example.com', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_registration_pending_session_expires(): void
    {
        $this->registration();
        $this->activate()->assertRedirect(route('secure-access.setup'));
        $this->travel(11)->minutes();

        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_registration_cannot_replace_or_unlock_an_existing_account(): void
    {
        $user = $this->createUser(['locked_at' => now(), 'failed_login_attempts' => 3]);
        $this->registration(['email' => $user->email])->assertSessionHasErrors('email');

        $this->assertNotNull($user->fresh()->locked_at);
        $this->assertSame(3, (int) $user->fresh()->failed_login_attempts);
        $this->assertSame(1, User::count());
        $this->passwordStep()->assertSessionHasErrors('identifier');
        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_start_registration(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);

        $this->get(route('secure-access.register'))->assertRedirect('/dashboard');
        $this->registration()->assertRedirect('/dashboard');
        $this->assertSame(1, User::count());
    }

    public function test_registration_clears_unverified_authentication_and_stale_mfa_state(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => 'stale']);

        $this->registration()->assertRedirect(route('secure-access.register.sent'));
        $this->assertGuest();
        $this->assertNull(session(SecureAccess::MFA_VERIFIED_SESSION_KEY));
        $this->assertNull(session('secure_access.pending'));
        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
    }

    public function test_activation_links_require_a_valid_signature_and_expire(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $unsigned = strtok($url, '?');
        $this->get($unsigned)->assertForbidden();
        $this->activate([], $unsigned)->assertForbidden();
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->activate([], $url.'&tampered=1')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->activate([], $url)->assertForbidden();
        $this->assertSame(0, User::count());
        $this->assertGuest();
        $this->assertNull(session('secure_access.pending'));
    }

    public function test_activation_links_are_single_use_and_cannot_reset_credentials(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $this->activate([], $url)->assertRedirect(route('secure-access.setup'));
        $this->get($url)->assertNotFound();
        $this->activate(['password' => 'Changed-password123!', 'password_confirmation' => 'Changed-password123!'], $url)
            ->assertNotFound();
        $this->assertTrue(Hash::check('Secure-password123!', User::sole()->password));
        $this->assertSame(1, User::count());
    }

    public function test_an_expired_registration_is_rejected_even_with_a_fresh_signature(): void
    {
        $this->registration();
        PendingRegistration::sole()->update(['expires_at' => now()->subMinute()]);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('secure-access.activate', now()->addHour(), [
            'registration' => PendingRegistration::sole()->id,
        ]);

        $this->get($url)->assertForbidden();
        $this->activate([], $url)->assertForbidden();
        $this->assertSame(0, User::count());
    }

    public function test_authenticated_users_cannot_activate_another_account(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $user = $this->createUser();
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);

        $this->get($url)->assertRedirect('/dashboard');
        $this->activate([], $url)->assertRedirect('/dashboard');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_activation_rechecks_email_ownership_before_creating_an_account(): void
    {
        $this->registration();
        $user = $this->createUser();
        UserEmail::create(['user_id' => $user->id, 'email' => 'new@example.com', 'verified_at' => now()]);

        $this->activate()->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
        $this->assertNull(session('secure_access.pending'));
    }

    public function test_multiple_links_for_the_same_email_cannot_replace_an_activated_account(): void
    {
        $this->registration();
        $first = $this->activationUrl();
        $this->registration();
        $this->activate()->assertRedirect(route('secure-access.setup'));
        $this->activate(['username' => 'another'], $first)->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame('newuser', User::sole()->username);
    }

    public function test_activation_posts_are_throttled(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        config(['secure-access.routes.throttle' => 2]);

        $this->post($url, [])->assertSessionHasErrors('password');
        $this->post($url, [])->assertStatus(429);
        $this->assertSame(0, User::count());
    }

    public function test_registration_posts_are_throttled(): void
    {
        config(['secure-access.routes.throttle' => 1]);

        $this->post(route('secure-access.register.store'), [])->assertSessionHasErrors('email');
        $this->post(route('secure-access.register.store'), [])->assertStatus(429);
        $this->assertSame(0, User::count());
    }
}

class RegistrationUser extends User
{
    protected $table = 'members';

    protected $guarded = ['*'];
}
