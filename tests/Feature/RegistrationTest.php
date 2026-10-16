<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use SecureAccess\Models\UserEmail;
use SecureAccess\SecureAccess;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class RegistrationTest extends TestCase
{
    protected function registration(array $attributes = [])
    {
        return $this->post(route('secure-access.register.store'), $attributes + [
            'name' => 'New User',
            'username' => 'newuser',
            'email' => 'new@example.com',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ]);
    }

    public function test_registration_page_renders(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Register')
            ->assertSee('name="name"', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee(route('secure-access.register.store'));
    }

    public function test_registration_requires_mfa_enrollment_before_authentication(): void
    {
        $this->registration([
            'email' => ' New@Example.com ',
            'username' => ' NewUser ',
            'locked_at' => now(),
            'email_verified_at' => now(),
        ])->assertRedirect(route('secure-access.setup'));

        $user = User::sole();
        $this->assertSame('New User', $user->name);
        $this->assertSame('newuser', $user->username);
        $this->assertSame('new@example.com', $user->email);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->locked_at);
        $this->assertGuest();
        $this->assertFalse(SecureAccess::mfaVerified($user));
        $this->assertSame((string) $user->id, session('secure_access.pending.user_id'));

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

        $this->registration(['email' => 'TAYLOR@example.com', 'username' => 'TAYLOR'])
            ->assertSessionHasErrors(['email', 'username']);
        $this->assertSame(1, User::count());
        $this->assertGuest();
        $this->assertNull(session('secure_access.pending'));
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

        $this->registration(['username' => 'taylor@example.com'])->assertSessionHasErrors('username');
        $this->assertSame(1, User::count());
    }

    public function test_password_confirmation_and_required_fields_are_validated(): void
    {
        $this->registration(['password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->registration(['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->registration(['password' => 'onlylowercaseletters', 'password_confirmation' => 'onlylowercaseletters'])
            ->assertSessionHasErrors('password');
        $this->post(route('secure-access.register.store'), [])
            ->assertSessionHasErrors(['name', 'username', 'email', 'password']);
        $this->assertSame(0, User::count());
    }

    public function test_invalid_identifier_types_are_validation_errors(): void
    {
        $this->registration(['email' => ['new@example.com'], 'username' => ['newuser']])
            ->assertSessionHasErrors(['email', 'username']);
        $this->assertSame(0, User::count());
    }

    public function test_application_password_defaults_are_respected(): void
    {
        $defaults = Password::$defaultCallback;
        Password::defaults(fn () => Password::min(24));

        try {
            $this->registration()->assertSessionHasErrors('password');
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

        $this->registration()->assertRedirect(route('secure-access.setup'));
        $user = RegistrationUser::sole();
        $this->assertSame('newuser', $user->handle);
        $this->assertSame('new@example.com', $user->email_address);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));

        $this->registration(['email' => 'NEW@example.com', 'username' => 'NEWUSER'])
            ->assertSessionHasErrors(['email', 'username']);
        $this->passwordStep('NEWUSER', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_name_and_username_are_omitted_when_the_columns_do_not_exist(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['name', 'username']);
        });

        $this->get(route('secure-access.register'))->assertOk()
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="username"', false);
        $this->registration(['name' => null, 'username' => null])->assertRedirect(route('secure-access.setup'));
        $this->passwordStep('new@example.com', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_registration_pending_session_expires(): void
    {
        $this->registration()->assertRedirect(route('secure-access.setup'));
        $this->travel(11)->minutes();

        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_registration_cannot_replace_or_unlock_an_existing_account(): void
    {
        $user = $this->createUser(['locked_at' => now(), 'failed_login_attempts' => 3]);
        $this->registration(['username' => $user->username, 'email' => $user->email])
            ->assertSessionHasErrors(['email', 'username']);

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

        $this->registration()->assertRedirect(route('secure-access.setup'));
        $this->assertGuest();
        $this->assertNull(session(SecureAccess::MFA_VERIFIED_SESSION_KEY));
        $this->assertNotSame((string) $user->id, session('secure_access.pending.user_id'));
        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
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
