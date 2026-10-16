<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\UserEmail;
use SecureAccess\Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_login_page_renders(): void
    {
        $this->get(route('secure-access.login'))
            ->assertOk()
            ->assertSee('Email or Username');
    }

    public function test_user_can_start_login_with_username(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);

        $this->passwordStep('taylor')->assertRedirect(route('secure-access.challenge'));

        $this->assertGuest();
    }

    public function test_identifiers_are_case_insensitive(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);

        $this->passwordStep('TAYLOR')->assertRedirect(route('secure-access.challenge'));
        $this->passwordStep(' Taylor@Example.com ')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_user_can_start_login_with_primary_email(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);

        $this->passwordStep('taylor@example.com')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_user_can_start_login_with_any_verified_additional_email(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com', 'verified_at' => now()]);
        UserEmail::create(['user_id' => $user->id, 'email' => 'personal@example.com', 'verified_at' => now()]);

        $this->passwordStep('work@example.com')->assertRedirect(route('secure-access.challenge'));
        $this->passwordStep('personal@example.com')->assertRedirect(route('secure-access.challenge'));
    }

    public function test_unverified_additional_email_cannot_be_used(): void
    {
        $user = $this->createUser();
        UserEmail::create(['user_id' => $user->id, 'email' => 'work@example.com']);

        $this->passwordStep('work@example.com')
            ->assertRedirect(route('secure-access.login'))
            ->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);
    }

    public function test_unknown_identifier_fails(): void
    {
        $this->passwordStep('nobody')
            ->assertSessionHasErrors(['identifier' => 'These credentials do not match our records.']);
    }

    public function test_user_without_methods_is_forced_to_register_one(): void
    {
        $this->createUser();

        $this->passwordStep()->assertRedirect(route('secure-access.challenge'));

        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.setup'));

        $this->get(route('secure-access.setup'))
            ->assertOk()
            ->assertSee('Multi-factor authentication is required')
            ->assertSee('Text message (SMS)')
            ->assertSee('Authenticator app')
            ->assertSee('Biometrics')
            ->assertSee('Hardware security key');

        $this->get('/dashboard')->assertRedirect(route('secure-access.login'));
        $this->assertGuest();
    }

    public function test_challenge_requires_password_step(): void
    {
        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.login'));
        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.login'));
    }

    public function test_pending_login_expires(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $this->passwordStep();

        $this->travel(11)->minutes();

        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.login'));
    }

    public function test_user_with_methods_cannot_register_another_one_before_completing_mfa(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $this->passwordStep();

        $this->get(route('secure-access.setup'))->assertRedirect(route('secure-access.challenge'));
        $this->get(route('secure-access.setup.create', 'totp'))->assertRedirect(route('secure-access.challenge'));
    }

    public function test_logout(): void
    {
        $user = $this->createUser();
        $secret = $this->addTotp($user);
        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $user->mfaMethods()->first()), ['code' => $this->currentTotp($secret)]);
        $this->assertAuthenticatedAs($user);

        $this->post(route('secure-access.logout'))->assertRedirect('/');

        $this->assertGuest();
    }
}
