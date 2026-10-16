<?php

namespace SecureAccess\Tests\Feature;

use Illuminate\Testing\TestResponse;
use SecureAccess\Models\MfaMethod;
use SecureAccess\Tests\Support\FakeAuthenticator;
use SecureAccess\Tests\TestCase;

class WebAuthnTest extends TestCase
{
    protected function webauthnOptions(TestResponse $response): array
    {
        preg_match('#<script type="application/json" data-webauthn-options>(.*?)</script>#s', $response->getContent(), $matches);

        return json_decode($matches[1], true);
    }

    protected function registerDuringFirstLogin(string $type, FakeAuthenticator $authenticator, bool $userVerified = true): TestResponse
    {
        $options = $this->webauthnOptions($this->get(route('secure-access.setup.create', $type))->assertOk());

        return $this->post(route('secure-access.setup.store', $type), [
            'name' => 'My key',
            'credential' => json_encode($authenticator->register($options, $userVerified)),
        ]);
    }

    protected function enrolledUser(string $type, FakeAuthenticator $authenticator)
    {
        $user = $this->createUser();
        $this->passwordStep();
        $this->registerDuringFirstLogin($type, $authenticator)->assertRedirect('/dashboard');
        $this->post(route('secure-access.logout'));

        return $user;
    }

    protected function challenge(MfaMethod $method, FakeAuthenticator $authenticator, bool $userVerified = true, array $overrides = []): TestResponse
    {
        $options = $this->webauthnOptions($this->get(route('secure-access.challenge.show', $method))->assertOk());

        return $this->post(route('secure-access.challenge.verify', $method), [
            'credential' => json_encode($authenticator->assert($options, $userVerified, $overrides)),
        ]);
    }

    public function test_registration_options_for_security_keys_and_biometrics(): void
    {
        $this->createUser();
        $this->passwordStep();

        $key = $this->webauthnOptions($this->get(route('secure-access.setup.create', 'security_key')));
        $this->assertSame('example.test', $key['rp']['id']);
        $this->assertSame('cross-platform', $key['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame('none', $key['attestation']);
        $this->assertSame([-7, -8, -257], array_column($key['pubKeyCredParams'], 'alg'));

        $bio = $this->webauthnOptions($this->get(route('secure-access.setup.create', 'biometric')));
        $this->assertSame('platform', $bio['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame('required', $bio['authenticatorSelection']['userVerification']);
    }

    public function test_first_login_registers_a_hardware_security_key(): void
    {
        $user = $this->createUser();
        $this->passwordStep();
        $authenticator = new FakeAuthenticator;

        $this->registerDuringFirstLogin('security_key', $authenticator, userVerified: false)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $method = $user->mfaMethods()->sole();
        $this->assertSame(MfaMethod::TYPE_SECURITY_KEY, $method->type);
        $this->assertSame('My key', $method->name);
        $this->assertSame(['usb'], $method->transports);
    }

    public function test_biometric_registration_requires_user_verification(): void
    {
        $user = $this->createUser();
        $this->passwordStep();
        $authenticator = new FakeAuthenticator;

        $this->registerDuringFirstLogin('biometric', $authenticator, userVerified: false)->assertSessionHasErrors('credential');
        $this->assertGuest();

        $this->registerDuringFirstLogin('biometric', $authenticator)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(MfaMethod::TYPE_BIOMETRIC, $user->mfaMethods()->sole()->type);
    }

    public function test_security_key_login(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('security_key', $authenticator);
        $method = $user->mfaMethods()->sole();

        $this->passwordStep();
        $this->challenge($method, $authenticator, userVerified: false)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $method->fresh()->counter);
    }

    public function test_biometric_login_requires_user_verification(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('biometric', $authenticator);
        $method = $user->mfaMethods()->sole();

        $this->passwordStep();
        $this->challenge($method, $authenticator, userVerified: false)->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->challenge($method, $authenticator)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_rs256_and_eddsa_authenticators(): void
    {
        foreach (['rs256', 'eddsa'] as $i => $algorithm) {
            $authenticator = new FakeAuthenticator($algorithm);
            $user = $this->createUser(['username' => "user{$i}", 'email' => "user{$i}@example.com"]);
            $this->passwordStep("user{$i}");
            $this->registerDuringFirstLogin('security_key', $authenticator)->assertRedirect('/dashboard');
            $this->post(route('secure-access.logout'));

            $this->passwordStep("user{$i}");
            $this->challenge($user->mfaMethods()->sole(), $authenticator)->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
            $this->post(route('secure-access.logout'));
        }
    }

    public function test_invalid_assertions_are_rejected_and_count_as_failures(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('security_key', $authenticator);
        $method = $user->mfaMethods()->sole();

        $this->passwordStep();

        // Phishing site origin.
        $this->challenge($method, $authenticator, overrides: ['origin' => 'https://evil.test'])->assertSessionHasErrors('code');

        // Different key pretending to be the registered credential.
        $impostor = new FakeAuthenticator;
        $impostor->credentialId = $authenticator->credentialId;
        $this->challenge($method, $impostor)->assertSessionHasErrors('code');
        $this->assertSame(2, (int) $user->fresh()->failed_login_attempts);

        // Relying party mismatch.
        $authenticator->rpId = 'evil.test';
        $this->challenge($method, $authenticator)->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_cloned_authenticator_counter_is_rejected(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('security_key', $authenticator);
        $method = $user->mfaMethods()->sole();

        $this->passwordStep();
        $authenticator->counter = 5;
        $this->challenge($method, $authenticator)->assertRedirect('/dashboard');
        $this->post(route('secure-access.logout'));

        $this->passwordStep();
        $authenticator->counter = 2;
        $this->challenge($method, $authenticator)->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_cannot_be_reused(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('security_key', $authenticator);
        $method = $user->mfaMethods()->sole();

        $this->passwordStep();
        $options = $this->webauthnOptions($this->get(route('secure-access.challenge.show', $method)));
        $first = $authenticator->assert($options);
        $this->post(route('secure-access.challenge.verify', $method), ['credential' => json_encode($first)])->assertRedirect('/dashboard');
        $this->post(route('secure-access.logout'));

        $this->passwordStep();
        $this->post(route('secure-access.challenge.verify', $method), [
            'credential' => json_encode($authenticator->assert($options)),
        ])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_same_authenticator_cannot_be_registered_twice(): void
    {
        $authenticator = new FakeAuthenticator;
        $user = $this->enrolledUser('security_key', $authenticator);
        $this->passwordStep();
        $this->challenge($user->mfaMethods()->sole(), $authenticator)->assertRedirect('/dashboard');

        $options = $this->webauthnOptions($this->get(route('secure-access.setup.create', 'security_key')));
        $this->assertSame([$user->mfaMethods()->sole()->credential_id], array_column($options['excludeCredentials'], 'id'));

        $this->post(route('secure-access.setup.store', 'security_key'), [
            'credential' => json_encode($authenticator->register($options)),
        ])->assertSessionHasErrors(['credential' => 'This device is already registered.']);
    }
}
