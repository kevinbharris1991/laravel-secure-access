<?php

namespace SecureAccess\Tests\Feature;

use SecureAccess\Models\MfaMethod;
use SecureAccess\Tests\TestCase;
use SecureAccess\Totp\Totp;

class TotpTest extends TestCase
{
    public function test_first_login_enrolls_an_authenticator_app_and_logs_in(): void
    {
        $user = $this->createUser();
        $this->passwordStep();

        $response = $this->get(route('secure-access.setup.create', 'totp'))->assertOk()->assertSee('<svg', false);
        $secret = session('secure_access.setup.totp')['secret'];
        $this->assertNotEmpty($secret);
        $response->assertSee($secret);

        $this->post(route('secure-access.setup.store', 'totp'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('secure-access.setup.store', 'totp'), ['name' => 'My phone', 'code' => $this->currentTotp($secret)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $method = $user->mfaMethods()->sole();
        $this->assertSame(MfaMethod::TYPE_TOTP, $method->type);
        $this->assertSame('My phone', $method->name);
        $this->assertSame($secret, $method->secret);
        $this->assertNotSame($secret, $method->getRawOriginal('secret'), 'The secret must be stored encrypted.');
        $this->get('/dashboard')->assertOk();
    }

    public function test_totp_challenge_logs_the_user_in(): void
    {
        $user = $this->createUser();
        $secret = $this->addTotp($user);

        $this->passwordStep();
        $method = $user->mfaMethods()->sole();

        $this->get(route('secure-access.challenge'))->assertRedirect(route('secure-access.challenge.show', $method));
        $this->get(route('secure-access.challenge.show', $method))->assertOk()->assertSee('authenticator app');

        $this->post(route('secure-access.challenge.verify', $method), ['code' => $this->currentTotp($secret)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($method->fresh()->last_used_at);
        $this->get('/dashboard')->assertOk()->assertSee('dashboard');
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $user = $this->createUser();
        $secret = $this->addTotp($user);
        $method = $user->mfaMethods()->sole();
        $code = $this->currentTotp($secret);

        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $method), ['code' => $code])->assertRedirect('/dashboard');
        $this->post(route('secure-access.logout'));

        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $method), ['code' => $code])
            ->assertSessionHasErrors(['code' => 'The provided code is invalid.']);
        $this->assertGuest();
    }

    public function test_user_cannot_use_another_users_method(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        $other = $this->createUser(['username' => 'other', 'email' => 'other@example.com']);
        $otherSecret = $this->addTotp($other);
        $otherMethod = $other->mfaMethods()->sole();

        $this->passwordStep();

        $this->get(route('secure-access.challenge.show', $otherMethod))->assertNotFound();
        $this->post(route('secure-access.challenge.verify', $otherMethod), ['code' => $this->currentTotp($otherSecret)])->assertNotFound();
        $this->assertGuest();
    }

    public function test_user_with_multiple_methods_chooses_one(): void
    {
        $user = $this->createUser();
        $this->addTotp($user);
        MfaMethod::create(['user_id' => $user->id, 'type' => 'sms', 'name' => 'Mobile', 'secret' => '+15555550123']);

        $this->passwordStep();

        $this->get(route('secure-access.challenge'))
            ->assertOk()
            ->assertSee('Phone app')
            ->assertSee('Mobile')
            ->assertSee('••••••••••23')
            ->assertDontSee('+15555550123');
    }

    public function test_rfc6238_reference_values(): void
    {
        config(['secure-access.mfa.totp.digits' => 8]);
        $totp = app(Totp::class);
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame('94287082', $totp->code($secret, $totp->timestep(59)));
        $this->assertSame('07081804', $totp->code($secret, $totp->timestep(1111111109)));
        $this->assertSame('14050471', $totp->code($secret, $totp->timestep(1111111111)));
        $this->assertSame('89005924', $totp->code($secret, $totp->timestep(1234567890)));
        $this->assertSame('69279037', $totp->code($secret, $totp->timestep(2000000000)));
    }

    public function test_verification_window_and_replay_protection(): void
    {
        $totp = app(Totp::class);
        $secret = $totp->generateSecret();
        $now = 1700000000;
        $step = $totp->timestep($now);

        $this->assertSame($step, $totp->verify($secret, $totp->code($secret, $step), 0, $now));
        $this->assertSame($step - 1, $totp->verify($secret, $totp->code($secret, $step - 1), 0, $now));
        $this->assertNull($totp->verify($secret, $totp->code($secret, $step - 2), 0, $now));
        $this->assertNull($totp->verify($secret, $totp->code($secret, $step), $step, $now));
        $this->assertNull($totp->verify($secret, 'abcdef', 0, $now));
        $this->assertSame('12345678901234567890', Totp::base32Decode(Totp::base32Encode('12345678901234567890')));
        $this->assertStringStartsWith('otpauth://totp/', $totp->provisioningUri($secret, 'taylor@example.com'));
    }
}
