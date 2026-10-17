<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use SecureAccess\Models\PendingRegistration;
use SecureAccess\Models\AccessGroup;
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
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 6, 15)->startOfDay());
    }

    protected function registration(array $attributes = [])
    {
        return $this->post(route('secure-access.register.store'), $attributes + [
            'first_name' => 'New',
            'last_name' => 'User',
            'date_of_birth' => '2000-01-31',
            'email' => 'new@example.com',
            'gender' => 'non_binary',
            'terms' => '1',
            'privacy' => '1',
        ]);
    }

    protected function activationUrl(string $email = 'new@example.com'): string
    {
        $url = null;

        Notification::assertSentOnDemand(ActivateAccount::class, function (ActivateAccount $notification, array $channels, object $notifiable) use ($email, &$url) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }

            $url = $notification->activationUrl();

            return true;
        });

        return $url;
    }

    protected function activate(string $url, array $attributes = [])
    {
        return $this->post($url, $attributes + [
            'username' => 'newuser',
            'password' => 'Secure-password123!',
            'password_confirmation' => 'Secure-password123!',
        ]);
    }

    public function test_registration_page_renders(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Register')
            ->assertSee('name="first_name"', false)
            ->assertSee('name="middle_initial"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('max="2013-06-15"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="gender"', false)
            ->assertSee('value="non_binary"', false)
            ->assertDontSee('name="registration_role"', false)
            ->assertDontSee('Which best describes you?')
            ->assertSee('name="terms"', false)
            ->assertSee('name="privacy"', false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/privacy"', false)
            ->assertDontSee('invitation_code')
            ->assertDontSee('name="username"', false)
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="name"', false)
            ->assertSee(route('secure-access.register.store'));
    }

    public function test_first_activated_user_is_executive_and_later_users_are_guests(): void
    {
        $this->registration(['email' => 'later@example.com']);
        $later = $this->activationUrl('later@example.com');
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));

        $first = User::sole();
        $this->assertSame(['executive-director'], $first->accessGroups()->pluck('key')->all());

        $this->activate($later, ['username' => 'later'])->assertRedirect(route('secure-access.register.role'));
        $this->assertSame(['guest'], User::where('username', 'later')->sole()->accessGroups()->pluck('key')->all());
    }

    public function test_existing_accounts_prevent_executive_bootstrap_and_public_group_input_is_ignored(): void
    {
        $this->createUser();
        $this->registration(['groups' => [AccessGroup::where('key', 'executive-director')->value('id')]]);
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));

        $this->assertSame(['guest'], User::where('username', 'newuser')->sole()->accessGroups()->pluck('key')->all());
    }

    public function test_deleting_all_users_does_not_reopen_executive_bootstrap(): void
    {
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));
        User::sole()->delete();
        Notification::fake();
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));
        $this->assertSame(['guest'], User::sole()->accessGroups()->pluck('key')->all());
    }

    public function test_acl_can_be_disabled_without_blocking_regular_registration(): void
    {
        config(['secure-access-acl.enabled' => false]);
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));
        $this->assertSame(0, User::sole()->accessGroups()->count());
    }

    public function test_full_registration_activation_and_mfa_flow(): void
    {
        $this->registration(['email' => ' New@Example.com ', 'middle_initial' => 'Q', 'locked_at' => now()])
            ->assertRedirect(route('secure-access.register.sent'));
        $this->get(route('secure-access.register.sent'))->assertOk()->assertSee('new@example.com');

        $this->assertSame(0, User::count());
        $this->assertGuest();
        $pending = PendingRegistration::sole();
        $this->assertSame('New Q User', $pending->name);
        $this->assertSame('2000-01-31', $pending->date_of_birth->toDateString());
        $this->assertSame('non_binary', $pending->gender);
        $this->assertNull($pending->registration_role);
        $this->assertNotNull($pending->terms_accepted_at);
        $this->assertNotNull($pending->privacy_policy_accepted_at);
        $this->assertEquals(now()->addMinutes(5), $pending->expires_at);

        $url = $this->activationUrl();
        $this->assertStringNotContainsString($pending->token, $url);

        $this->get($url)->assertOk()
            ->assertSee('name="profile_photo"', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('name="registration_role"', false)
            ->assertDontSee('Which best describes you?')
            ->assertSee('enctype="multipart/form-data"', false);

        $this->activate($url, [
            'username' => ' NewUser ',
            'profile_photo' => UploadedFile::fake()->image('me.png', 200, 200),
        ])->assertRedirect(route('secure-access.register.role'));

        $user = User::sole();
        $this->assertSame('New Q User', $user->name);
        $this->assertSame('newuser', $user->username);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('2000-01-31', substr($user->date_of_birth, 0, 10));
        $this->assertSame('non_binary', $user->gender);
        $this->assertNull($user->registration_role);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertNotNull($user->privacy_policy_accepted_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->locked_at);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));
        $this->assertStringStartsWith('profile-photos/', $user->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
        $this->assertSame(0, PendingRegistration::count());

        // Activation does not authenticate: MFA enrollment is required first.
        $this->assertGuest();
        $this->assertFalse(SecureAccess::mfaVerified($user));
        $this->assertSame((string) $user->id, session('secure_access.pending.user_id'));
        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));

        $this->get(route('secure-access.register.role'))->assertOk()
            ->assertSee('Which best describes you?')
            ->assertSee('name="registration_role"', false)
            ->assertSee('value="self_advocate"', false)
            ->assertSee('Self-advocate: I am an individual with high support needs.')
            ->assertSee('Family member / Caregiver: I support a relative with high support needs.')
            ->assertSee('Professional: I work in a field or career supporting individuals with high support needs.')
            ->assertSee("Employee: I work for Camp Cody's.")
            ->assertSee('Other / None of the above');
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'self_advocate'])
            ->assertRedirect(route('secure-access.setup'));
        $this->assertSame('self_advocate', $user->fresh()->registration_role);
        $this->assertGuest();
        $this->assertFalse(SecureAccess::mfaVerified($user));

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
        $this->get('/dashboard')->assertOk();
    }

    public function test_profile_photo_is_optional(): void
    {
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));

        $this->assertNull(User::sole()->profile_photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_role_is_only_collected_after_activation_and_must_be_allowlisted(): void
    {
        $this->registration(['registration_role' => 'employee'])->assertSessionHasNoErrors();
        $this->assertNull(PendingRegistration::sole()->registration_role);
        $this->activate($this->activationUrl(), ['registration_role' => 'employee']);
        $user = User::sole();
        $this->assertNull($user->registration_role);

        foreach ([[], ['registration_role' => ''], ['registration_role' => 'executive-director'], ['registration_role' => ['other']]] as $input) {
            $this->from(route('secure-access.register.role'))
                ->post(route('secure-access.register.role.store'), $input)
                ->assertRedirect(route('secure-access.register.role'))
                ->assertSessionHasErrors('registration_role');
            $this->assertNull($user->fresh()->registration_role);
            $this->assertGuest();
        }

        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'professional'])
            ->assertRedirect(route('secure-access.setup'));
        $this->assertSame('professional', $user->fresh()->registration_role);
        $this->get(route('secure-access.register.role'))->assertRedirect(route('secure-access.challenge'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'employee'])
            ->assertRedirect(route('secure-access.challenge'));
        $this->assertSame('professional', $user->fresh()->registration_role);
        $this->assertGuest();
    }

    public function test_every_role_choice_is_accepted_without_changing_access_groups(): void
    {
        foreach (['self_advocate', 'family_caregiver', 'professional', 'employee', 'other'] as $role) {
            $email = $role.'@example.com';
            $this->registration(['email' => $email])->assertSessionHasNoErrors();
            $this->activate($this->activationUrl($email), ['username' => $role]);
            $user = User::where('email', $email)->sole();
            $groups = $user->accessGroups()->pluck('key')->all();
            $this->post(route('secure-access.register.role.store'), [
                'registration_role' => $role,
                'groups' => [AccessGroup::where('key', 'executive-director')->value('id')],
            ])->assertRedirect(route('secure-access.setup'));
            $this->assertSame($role, $user->fresh()->registration_role);
            $this->assertSame($groups, $user->accessGroups()->pluck('key')->all());
            $this->assertGuest();
        }
    }

    public function test_role_selection_cannot_be_skipped_by_direct_mfa_requests(): void
    {
        $this->registration();
        $this->activate($this->activationUrl());
        $roleUrl = route('secure-access.register.role');

        $this->get(route('secure-access.challenge'))->assertRedirect($roleUrl);
        $this->get(route('secure-access.setup'))->assertRedirect($roleUrl);
        $this->post(route('secure-access.setup.sms.send'), ['phone' => '+15555550123'])->assertRedirect($roleUrl);
        foreach (['totp', 'sms', 'biometric', 'security_key'] as $type) {
            $this->get(route('secure-access.setup.create', $type))->assertRedirect($roleUrl);
            $this->post(route('secure-access.setup.store', $type), [])->assertRedirect($roleUrl);
        }
        $this->assertNull(session('secure_access.setup.totp'));
        $this->assertDatabaseCount('mfa_methods', 0);
        $this->assertGuest();
    }

    public function test_role_page_requires_a_live_pending_login_and_resumes_after_signing_in(): void
    {
        $this->get(route('secure-access.register.role'))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect(route('secure-access.login'));

        $this->registration();
        $this->activate($this->activationUrl());
        $this->travel(11)->minutes();
        $this->get(route('secure-access.register.role'))->assertRedirect(route('secure-access.login'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect(route('secure-access.login'));
        $this->assertNull(User::sole()->registration_role);
        $this->assertGuest();

        $this->passwordStep('newuser', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.register.role'));
        $this->get(route('secure-access.register.role'))->assertOk();
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect(route('secure-access.setup'));
        $this->assertGuest();
    }

    public function test_switching_pending_users_does_not_apply_another_accounts_role_requirement(): void
    {
        $legacy = $this->createUser();
        $this->registration();
        $this->activate($this->activationUrl());
        $new = User::where('username', 'newuser')->sole();

        $this->passwordStep()->assertRedirect(route('secure-access.challenge'));
        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.setup'));
        $this->get(route('secure-access.setup'))->assertOk();
        $this->get(route('secure-access.register.role'))->assertRedirect(route('secure-access.challenge'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect(route('secure-access.challenge'));
        $this->assertNull($legacy->fresh()->registration_role);
        $this->assertNull($new->fresh()->registration_role);

        $this->passwordStep('newuser', 'Secure-password123!');
        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.register.role'));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'family_caregiver'])
            ->assertRedirect(route('secure-access.setup'));
        $this->passwordStep('newuser', 'Secure-password123!');
        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.setup'));
        $this->assertSame('family_caregiver', $new->fresh()->registration_role);
        $this->assertGuest();
    }

    public function test_profile_photo_must_be_a_small_image(): void
    {
        config(['secure-access.registration.profile_photos.max_size' => 100]);
        $this->registration();
        $url = $this->activationUrl();

        $this->activate($url, ['profile_photo' => UploadedFile::fake()->create('me.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('profile_photo');
        $this->activate($url, ['profile_photo' => UploadedFile::fake()->image('me.png')->size(500)])
            ->assertSessionHasErrors('profile_photo');

        $this->assertSame(0, User::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_users_younger_than_the_coppa_minimum_age_cannot_register(): void
    {
        $this->assertSame(13, config('coppa.minimum_age'));

        $this->registration(['date_of_birth' => '2013-06-16'])
            ->assertSessionHasErrors(['date_of_birth' => 'You must be at least 13 years old to register.']);
        $this->registration(['date_of_birth' => '2030-01-01'])->assertSessionHasErrors('date_of_birth');
        $this->assertSame(0, PendingRegistration::count());
        Notification::assertNothingSent();

        $this->registration(['date_of_birth' => '2013-06-15'])->assertSessionHasNoErrors();
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_minimum_age_is_read_from_the_coppa_config(): void
    {
        config(['coppa.minimum_age' => 18]);

        $this->get(route('secure-access.register'))->assertSee('max="2008-06-15"', false)->assertSee('at least 18 years old');
        $this->registration(['date_of_birth' => '2008-06-16'])
            ->assertSessionHasErrors(['date_of_birth' => 'You must be at least 18 years old to register.']);
        $this->registration(['date_of_birth' => '2008-06-15'])->assertSessionHasNoErrors();
    }

    public function test_terms_and_privacy_policy_must_be_accepted(): void
    {
        $this->registration(['terms' => null, 'privacy' => '0'])->assertSessionHasErrors(['terms', 'privacy']);
        $this->assertSame(0, PendingRegistration::count());
        Notification::assertNothingSent();
    }

    public function test_required_fields_and_formats_are_validated(): void
    {
        $this->post(route('secure-access.register.store'), [])
            ->assertSessionHasErrors(['first_name', 'last_name', 'date_of_birth', 'email', 'gender', 'terms', 'privacy']);
        $this->registration(['middle_initial' => 'AB'])->assertSessionHasErrors('middle_initial');
        $this->registration(['first_name' => str_repeat('A', 255), 'last_name' => str_repeat('B', 255)])
            ->assertSessionHasErrors('last_name');
        $this->registration(['gender' => 'unknown', 'date_of_birth' => '31/01/2000', 'email' => ['new@example.com']])
            ->assertSessionHasErrors(['gender', 'date_of_birth', 'email']);
        $this->registration(['date_of_birth' => '1899-12-31'])->assertSessionHasErrors('date_of_birth');
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_duplicate_emails_are_rejected_case_insensitively(): void
    {
        $user = $this->createUser();
        UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com', 'verified_at' => now()]);

        $this->registration(['email' => 'TAYLOR@example.com'])->assertSessionHasErrors('email');
        $this->registration(['email' => 'WORK@example.com'])->assertSessionHasErrors('email');
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_registering_again_replaces_the_pending_registration_and_invalidates_old_links(): void
    {
        $this->registration();
        $first = $this->activationUrl();
        Notification::fake();

        // Repeated submissions within the resend window neither send nor replace anything.
        $this->registration(['first_name' => 'Too'])->assertRedirect(route('secure-access.register.sent'));
        Notification::assertNothingSent();
        $this->assertSame('New User', PendingRegistration::sole()->name);
        $this->get($first)->assertOk();

        $this->travel(61)->seconds();
        $this->registration(['first_name' => 'Renamed']);
        $second = $this->activationUrl();

        $this->assertSame('Renamed User', PendingRegistration::sole()->name);
        $this->get($first)->assertRedirect(route('secure-access.register'))->assertSessionHasErrors('activation');
        $this->get($second)->assertOk();
    }

    public function test_activation_links_are_signed_expiring_and_single_use(): void
    {
        $this->registration();
        $url = $this->activationUrl();

        $this->get($url.'x')->assertRedirect(route('secure-access.register'))->assertSessionHasErrors('activation');
        $this->activate(str_replace('/activate/1/', '/activate/2/', $url))->assertSessionHasErrors('activation');
        $this->assertSame(0, User::count());

        $this->activate($url)->assertRedirect(route('secure-access.register.role'));
        $this->activate($url, ['username' => 'another'])->assertSessionHasErrors('activation');
        $this->assertSame(1, User::count());

        Notification::fake();
        $this->registration(['email' => 'late@example.com']);
        $late = $this->activationUrl('late@example.com');
        $this->travel(6)->minutes();
        $this->get($late)->assertRedirect(route('secure-access.register'))->assertSessionHasErrors('activation');
        $this->activate($late, ['username' => 'late'])->assertSessionHasErrors('activation');
        $this->assertSame(1, User::count());
    }

    public function test_activation_validates_username_and_password(): void
    {
        $this->createUser();
        $this->registration();
        $url = $this->activationUrl();

        $this->activate($url, ['username' => 'TAYLOR'])->assertSessionHasErrors('username');
        $this->activate($url, ['username' => 'taylor@example.com'])->assertSessionHasErrors('username');
        $this->activate($url, ['username' => ['x']])->assertSessionHasErrors('username');
        $this->activate($url, ['password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->activate($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->activate($url, ['password' => 'onlylowercaseletters', 'password_confirmation' => 'onlylowercaseletters'])
            ->assertSessionHasErrors('password');
        $this->post($url, [])->assertSessionHasErrors(['username', 'password']);

        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_application_password_defaults_are_respected(): void
    {
        $defaults = Password::$defaultCallback;
        Password::defaults(fn () => Password::min(24));

        try {
            $this->registration();
            $this->activate($this->activationUrl())->assertSessionHasErrors('password');
            $this->assertSame(0, User::count());
        } finally {
            Password::$defaultCallback = $defaults;
        }
    }

    public function test_email_claimed_before_activation_is_rejected(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $this->createUser(['email' => 'new@example.com', 'username' => 'someone']);

        $this->activate($url)->assertRedirect(route('secure-access.register'))->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame(0, PendingRegistration::count());
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

        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));
        $user = RegistrationUser::sole();
        $this->assertSame('newuser', $user->handle);
        $this->assertSame('new@example.com', $user->email_address);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'employee'])
            ->assertRedirect(route('secure-access.setup'));
        $this->assertSame('employee', $user->fresh()->registration_role);

        $this->registration(['email' => 'NEW@example.com'])->assertSessionHasErrors('email');
        $this->passwordStep('NEWUSER', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_optional_columns_are_skipped_when_they_do_not_exist(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['name', 'username', 'date_of_birth', 'gender', 'profile_photo_path', 'registration_role']);
        });

        $this->registration();
        $url = $this->activationUrl();
        $this->get($url)->assertOk()->assertDontSee('name="username"', false);
        $this->activate($url, ['username' => null])->assertRedirect(route('secure-access.register.role'));
        $this->assertSame('new@example.com', User::sole()->email);
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect(route('secure-access.setup'));
        $this->get(route('secure-access.setup'))->assertOk();
        $this->assertGuest();
        $this->passwordStep('new@example.com', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_registration_and_login_work_when_the_user_table_has_no_email_column(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });

        $this->registration()->assertRedirect(route('secure-access.register.sent'));
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));

        $user = User::sole();
        $email = UserEmail::sole();
        $this->assertSame((string) $user->id, (string) $email->user_id);
        $this->assertSame('new@example.com', $email->email);
        $this->assertNotNull($email->verified_at);

        $this->assertGuest();
        $this->passwordStep('new@example.com', 'Secure-password123!')
            ->assertRedirect(route('secure-access.challenge'));

        $this->registration(['email' => 'NEW@example.com'])->assertSessionHasErrors('email');
    }

    public function test_mfa_enrollment_after_activation_expires(): void
    {
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.register.role'));
        $this->travel(11)->minutes();

        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_authenticated_users_cannot_register_or_activate(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $user = $this->createUser();
        $this->actingAs($user)->withSession([SecureAccess::MFA_VERIFIED_SESSION_KEY => (string) $user->id]);

        $this->get(route('secure-access.register'))->assertRedirect('/dashboard');
        $this->registration(['email' => 'other@example.com'])->assertRedirect('/dashboard');
        $this->get($url)->assertRedirect('/dashboard');
        $this->activate($url)->assertRedirect('/dashboard');
        $this->get(route('secure-access.register.role'))->assertRedirect('/dashboard');
        $this->post(route('secure-access.register.role.store'), ['registration_role' => 'other'])
            ->assertRedirect('/dashboard');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_activation_clears_unverified_authentication_and_stale_state(): void
    {
        $this->registration();
        $url = $this->activationUrl();
        $user = $this->createUser();
        $this->actingAs($user)->withSession([
            SecureAccess::MFA_VERIFIED_SESSION_KEY => 'stale',
            'url.intended' => 'https://example.test/elsewhere',
        ]);

        $this->activate($url)->assertRedirect(route('secure-access.register.role'));
        $this->assertGuest();
        $this->assertNull(session(SecureAccess::MFA_VERIFIED_SESSION_KEY));
        $this->assertNull(session('url.intended'));
        $this->assertNotSame((string) $user->id, session('secure_access.pending.user_id'));
    }

    public function test_expired_pending_registrations_are_prunable(): void
    {
        $this->registration();
        $this->assertSame(0, (new PendingRegistration)->prunable()->count());

        $this->travel(61)->minutes();
        $this->assertSame(1, (new PendingRegistration)->prunable()->count());
    }

    public function test_registration_posts_are_throttled(): void
    {
        config(['secure-access.routes.throttle' => 1]);

        $this->post(route('secure-access.register.store'), [])->assertSessionHasErrors('email');
        $this->post(route('secure-access.register.store'), [])->assertStatus(429);
        $this->assertSame(0, PendingRegistration::count());
    }
}

class RegistrationUser extends User
{
    protected $table = 'members';

    protected $guarded = ['*'];
}
