<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Models\UserEmail;
use SecureAccess\Notifications\VerifyAdditionalEmail;
use SecureAccess\Tests\Fixtures\User;
use SecureAccess\Tests\TestCase;

class SecurityPageTest extends TestCase
{
    protected function login(?User $user = null): User
    {
        $user ??= $this->createUser();
        $secret = MfaMethod::query()->where('user_id', $user->id)->exists()
            ? $user->mfaMethods()->first()->secret
            : $this->addTotp($user);

        $this->passwordStep($user->username);
        $this->post(route('secure-access.challenge.verify', $user->mfaMethods()->first()), ['code' => $this->currentTotp($secret)])
            ->assertRedirect('/dashboard');

        return $user;
    }

    public function test_acting_as_without_mfa_is_redirected_to_the_challenge(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('secure-access.challenge'));
        $this->assertGuest();

        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.challenge.show', $user->mfaMethods()->sole()));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('secure-access.security'))->assertRedirect(route('secure-access.login'));
        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
    }

    public function test_intended_url_is_preserved(): void
    {
        $user = $this->createUser();
        $secret = $this->addTotp($user);

        $this->get(route('secure-access.security'))->assertRedirect(route('secure-access.login'));
        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $user->mfaMethods()->sole()), ['code' => $this->currentTotp($secret)])
            ->assertRedirect(route('secure-access.security'));
    }

    public function test_security_page_lists_methods_and_identifiers(): void
    {
        $user = $this->login();
        UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com', 'verified_at' => now()]);

        $this->get(route('secure-access.security'))
            ->assertOk()
            ->assertSee('Phone app')
            ->assertSee('taylor@example.com')
            ->assertSee('work@example.com')
            ->assertSee('Add method');
    }

    public function test_signed_in_user_can_add_more_methods(): void
    {
        $user = $this->login();

        $this->get(route('secure-access.setup'))->assertOk()->assertSee('Choose the type of method');
        $this->get(route('secure-access.setup.create', 'totp'))->assertOk();
        $secret = session('secure_access.setup.totp_secret');

        $this->post(route('secure-access.setup.store', 'totp'), ['name' => 'Backup app', 'code' => $this->currentTotp($secret)])
            ->assertRedirect(route('secure-access.security'));

        $this->assertSame(2, $user->mfaMethods()->count());
    }

    public function test_removing_a_method_requires_password_and_keeps_at_least_one(): void
    {
        $user = $this->login();
        $first = $user->mfaMethods()->sole();

        $this->delete(route('secure-access.methods.destroy', $first), ['password' => 'password'])
            ->assertSessionHasErrorsIn('removeMethod', 'password');
        $this->assertSame(1, $user->mfaMethods()->count());

        $second = MfaMethod::create(['user_id' => $user->id, 'type' => 'sms', 'name' => 'Mobile', 'secret' => '+15555550123']);

        $this->delete(route('secure-access.methods.destroy', $second), ['password' => 'wrong'])
            ->assertSessionHasErrorsIn('removeMethod', 'password');

        $this->delete(route('secure-access.methods.destroy', $second), ['password' => 'password'])
            ->assertRedirect(route('secure-access.security'));
        $this->assertSame([$first->id], $user->mfaMethods()->pluck('id')->all());
    }

    public function test_cannot_remove_another_users_method(): void
    {
        $this->login();
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        $this->addTotp($other);
        $this->addTotp($other);

        $this->delete(route('secure-access.methods.destroy', $other->mfaMethods()->first()), ['password' => 'password'])
            ->assertNotFound();
    }

    public function test_adding_and_verifying_an_additional_email(): void
    {
        Notification::fake();
        $user = $this->login();

        $this->post(route('secure-access.emails.store'), ['email' => 'Work@Example.com'])
            ->assertRedirect(route('secure-access.security'));

        $email = UserEmail::sole();
        $this->assertSame('work@example.com', $email->email);
        $this->assertFalse($email->isVerified());

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, VerifyAdditionalEmail::class, function ($notification, $channels, $notifiable) use (&$url) {
            $url = $notification->verificationUrl();

            return $notifiable->routes['mail'] === 'work@example.com';
        });

        // Not usable before verification.
        $this->post(route('secure-access.logout'));
        $this->passwordStep('work@example.com')->assertSessionHasErrors('identifier');

        // Tampered link is rejected.
        $this->get(str_replace('/verify/', '/verify/x', $url))->assertForbidden();

        $this->get($url)->assertRedirect(route('secure-access.security'));
        $this->assertTrue($email->fresh()->isVerified());

        $this->passwordStep('work@example.com')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_email_already_in_use_cannot_be_added(): void
    {
        Notification::fake();
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        UserEmail::create(['user_id' => $other->id, 'email' => 'shared@example.com', 'verified_at' => now()]);
        $this->login();

        $this->post(route('secure-access.emails.store'), ['email' => 'OTHER@example.com'])
            ->assertSessionHasErrorsIn('addEmail', 'email');
        $this->post(route('secure-access.emails.store'), ['email' => 'shared@example.com'])
            ->assertSessionHasErrorsIn('addEmail', 'email');
        $this->post(route('secure-access.emails.store'), ['email' => 'taylor@example.com'])
            ->assertSessionHasErrorsIn('addEmail', 'email');

        Notification::assertNothingSent();
    }

    public function test_removing_an_email(): void
    {
        $user = $this->login();
        $email = UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com', 'verified_at' => now()]);

        $this->delete(route('secure-access.emails.destroy', $email))->assertRedirect(route('secure-access.security'));

        $this->assertModelMissing($email);
    }
}
