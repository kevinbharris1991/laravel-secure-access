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
use SecureAccess\Models\RegistrationInvitation;
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
            ->assertSee('name="terms"', false)
            ->assertSee('name="privacy"', false)
            ->assertSee('href="/terms"', false)
            ->assertSee('href="/privacy"', false)
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
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));

        $first = User::sole();
        $this->assertSame(['executive-director'], $first->accessGroups()->pluck('key')->all());

        $this->activate($later, ['username' => 'later'])->assertRedirect(route('secure-access.setup'));
        $this->assertSame(['guest'], User::where('username', 'later')->sole()->accessGroups()->pluck('key')->all());
    }

    public function test_existing_accounts_prevent_executive_bootstrap_and_public_group_input_is_ignored(): void
    {
        $this->createUser();
        $this->registration(['groups' => [AccessGroup::where('key', 'executive-director')->value('id')]]);
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));

        $this->assertSame(['guest'], User::where('username', 'newuser')->sole()->accessGroups()->pluck('key')->all());
    }

    public function test_deleting_all_users_does_not_reopen_executive_bootstrap(): void
    {
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));
        User::sole()->delete();
        Notification::fake();
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));
        $this->assertSame(['guest'], User::sole()->accessGroups()->pluck('key')->all());
    }

    protected function invitation(array $attributes = []): array
    {
        $code = str_repeat('i', 64);
        $group = AccessGroup::where('key', 'medical-nursing')->sole();
        $invitation = RegistrationInvitation::create($attributes + [
            'email' => 'new@example.com',
            'token' => PendingRegistration::hashToken($code),
            'group_ids' => [$group->id],
            'expires_at' => now()->addDay(),
        ]);

        return [$invitation, $code, $group];
    }

    public function test_invitation_assigns_only_preconfigured_groups_after_activation(): void
    {
        $this->createUser();
        $groups = AccessGroup::whereIn('key', ['medical-nursing', 'behavioral-support'])->orderBy('id')->get();
        [$invitation, $code] = $this->invitation(['group_ids' => $groups->modelKeys()]);
        $this->registration(['invitation_code' => $code])->assertSessionHasNoErrors();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertSame($invitation->id, PendingRegistration::sole()->invitation_id);

        $url = $this->activationUrl();
        $this->activate($url, ['groups' => [AccessGroup::where('key', 'executive-director')->value('id')]])
            ->assertRedirect(route('secure-access.setup'));
        $user = User::where('username', 'newuser')->sole();
        $this->assertSame($groups->pluck('key')->all(), $user->accessGroups()->orderBy('secure_access_groups.id')->pluck('key')->all());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertGuest();
        $this->activate($url)->assertSessionHasErrors('activation');
        $user->delete();
        $this->registration(['invitation_code' => $code])->assertSessionHasErrors('invitation_code');
    }

    public function test_invalid_expired_used_and_wrong_email_invitations_are_rejected(): void
    {
        [$invitation, $code] = $this->invitation();
        $this->registration(['invitation_code' => 'invalid'])->assertSessionHasErrors('invitation_code');
        $this->registration(['invitation_code' => str_repeat('x', 64)])->assertSessionHasErrors('invitation_code');
        $this->registration(['invitation_code' => $code, 'email' => 'other@example.com'])->assertSessionHasErrors('invitation_code');
        $invitation->update(['expires_at' => now()->subSecond()]);
        $this->registration(['invitation_code' => $code])->assertSessionHasErrors('invitation_code');
        $invitation->update(['expires_at' => now()->addDay(), 'accepted_at' => now()]);
        $this->registration(['invitation_code' => $code])->assertSessionHasErrors('invitation_code');
        $this->assertSame(0, PendingRegistration::count());
    }

    public function test_invitation_is_rechecked_at_activation_and_failure_rolls_back(): void
    {
        $this->createUser();
        [$invitation, $code] = $this->invitation();
        $this->registration(['invitation_code' => $code]);
        $url = $this->activationUrl();
        $invitation->update(['expires_at' => now()->subSecond()]);

        $this->activate($url, ['profile_photo' => UploadedFile::fake()->image('me.png')])
            ->assertSessionHasErrors('invitation_code');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_deleted_invitation_or_group_cannot_silently_downgrade_an_invited_registration(): void
    {
        $this->createUser();
        [$invitation, $code, $group] = $this->invitation();
        $this->registration(['invitation_code' => $code]);
        $url = $this->activationUrl();
        $group->delete();
        $this->activate($url)->assertSessionHasErrors('invitation_code');
        $invitation->delete();
        $this->activate($url)->assertSessionHasErrors('invitation_code');
        $this->assertSame(1, User::count());
        $this->assertSame(1, PendingRegistration::count());
    }

    public function test_acl_can_be_disabled_without_blocking_regular_registration(): void
    {
        config(['secure-access-acl.enabled' => false]);
        $this->registration();
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));
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
        $this->assertNotNull($pending->terms_accepted_at);
        $this->assertNotNull($pending->privacy_policy_accepted_at);

        $url = $this->activationUrl();
        $this->assertStringNotContainsString($pending->token, $url);

        $this->get($url)->assertOk()
            ->assertSee('name="profile_photo"', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        $this->activate($url, [
            'username' => ' NewUser ',
            'profile_photo' => UploadedFile::fake()->image('me.png', 200, 200),
        ])->assertRedirect(route('secure-access.setup'));

        $user = User::sole();
        $this->assertSame('New Q User', $user->name);
        $this->assertSame('newuser', $user->username);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('2000-01-31', substr($user->date_of_birth, 0, 10));
        $this->assertSame('non_binary', $user->gender);
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
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));

        $this->assertNull(User::sole()->profile_photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
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

        $this->activate($url)->assertRedirect(route('secure-access.setup'));
        $this->activate($url, ['username' => 'another'])->assertSessionHasErrors('activation');
        $this->assertSame(1, User::count());

        Notification::fake();
        $this->registration(['email' => 'late@example.com']);
        $late = $this->activationUrl('late@example.com');
        $this->travel(61)->minutes();
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
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));
        $user = RegistrationUser::sole();
        $this->assertSame('newuser', $user->handle);
        $this->assertSame('new@example.com', $user->email_address);
        $this->assertTrue(Hash::check('Secure-password123!', $user->password));

        $this->registration(['email' => 'NEW@example.com'])->assertSessionHasErrors('email');
        $this->passwordStep('NEWUSER', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_optional_columns_are_skipped_when_they_do_not_exist(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['name', 'username', 'date_of_birth', 'gender', 'profile_photo_path']);
        });

        $this->registration();
        $url = $this->activationUrl();
        $this->get($url)->assertOk()->assertDontSee('name="username"', false);
        $this->activate($url, ['username' => null])->assertRedirect(route('secure-access.setup'));
        $this->assertSame('new@example.com', User::sole()->email);
        $this->passwordStep('new@example.com', 'Secure-password123!')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_registration_and_login_work_when_the_user_table_has_no_email_column(): void
    {
        Schema::table('users', function ($table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });

        $this->registration()->assertRedirect(route('secure-access.register.sent'));
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));

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
        $this->activate($this->activationUrl())->assertRedirect(route('secure-access.setup'));
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

        $this->activate($url)->assertRedirect(route('secure-access.setup'));
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
