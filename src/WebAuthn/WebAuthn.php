<?php

namespace SecureAccess\WebAuthn;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SecureAccess\Models\MfaMethod;

/**
 * WebAuthn relying party used for biometrics (platform authenticators) and hardware security keys.
 */
class WebAuthn
{
    protected const SESSION_KEY = 'secure_access.webauthn.challenge';

    public function rpId(): string
    {
        return (string) (config('secure-access.mfa.webauthn.rp_id')
            ?: parse_url((string) config('app.url'), PHP_URL_HOST)
            ?: 'localhost');
    }

    public function origin(): string
    {
        if ($origin = config('secure-access.mfa.webauthn.origin')) {
            return rtrim((string) $origin, '/');
        }

        $url = parse_url((string) config('app.url'));
        $origin = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? 'localhost');

        return isset($url['port']) ? $origin.':'.$url['port'] : $origin;
    }

    /**
     * Opaque, stable user handle that does not reveal the user's primary key.
     */
    public function userHandle(Model $user): string
    {
        return substr(hash_hmac('sha256', 'webauthn-user:'.$user->getKey(), (string) config('app.key'), true), 0, 32);
    }

    /**
     * Options for navigator.credentials.create().
     *
     * @param  Collection<int, MfaMethod>  $existing
     */
    public function registrationOptions(Model $user, string $type, Collection $existing): array
    {
        $challenge = $this->newChallenge('create', $type, $user);
        $name = (string) ($user->getAttribute(config('secure-access.username_column', 'username'))
            ?: $user->getAttribute(config('secure-access.email_column', 'email'))
            ?: $user->getKey());

        return [
            'challenge' => Base64Url::encode($challenge),
            'rp' => [
                'id' => $this->rpId(),
                'name' => (string) config('secure-access.mfa.webauthn.rp_name', config('app.name')),
            ],
            'user' => [
                'id' => Base64Url::encode($this->userHandle($user)),
                'name' => $name,
                'displayName' => (string) ($user->getAttribute('name') ?: $name),
            ],
            'pubKeyCredParams' => array_map(
                fn (int $alg) => ['type' => 'public-key', 'alg' => $alg],
                CoseKey::SUPPORTED_ALGORITHMS
            ),
            'authenticatorSelection' => $type === MfaMethod::TYPE_BIOMETRIC
                ? ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred']
                : ['authenticatorAttachment' => 'cross-platform', 'userVerification' => 'discouraged', 'residentKey' => 'discouraged'],
            'excludeCredentials' => $this->descriptors($existing),
            'attestation' => 'none',
            'timeout' => (int) config('secure-access.mfa.webauthn.timeout', 60000),
        ];
    }

    /**
     * Options for navigator.credentials.get().
     *
     * @param  Collection<int, MfaMethod>  $methods
     */
    public function assertionOptions(Model $user, string $type, Collection $methods): array
    {
        $challenge = $this->newChallenge('get', $type, $user);

        return [
            'challenge' => Base64Url::encode($challenge),
            'rpId' => $this->rpId(),
            'allowCredentials' => $this->descriptors($methods),
            'userVerification' => $this->requiresUserVerification($type) ? 'required' : 'discouraged',
            'timeout' => (int) config('secure-access.mfa.webauthn.timeout', 60000),
        ];
    }

    /**
     * Verify a registration response and return the attributes of the new credential.
     *
     * @return array{credential_id: string, public_key: string, counter: int, transports: array<int, string>}
     */
    public function verifyRegistration(Model $user, string $type, array $credential): array
    {
        $this->pullChallenge('create', $type, $user, $challenge);

        $clientDataJson = Base64Url::decode((string) data_get($credential, 'response.clientDataJSON', ''));
        $this->verifyClientData($clientDataJson, 'webauthn.create', $challenge);

        $attestation = Cbor::decode(Base64Url::decode((string) data_get($credential, 'response.attestationObject', '')));

        if (! is_array($attestation) || ! is_string($attestation['authData'] ?? null) || ! is_string($attestation['fmt'] ?? null)) {
            throw new WebAuthnException('Invalid attestation object.');
        }

        $authData = new AuthenticatorData($attestation['authData']);
        $this->verifyAuthenticatorData($authData, $type);

        if ($authData->credentialId === null || $authData->credentialPublicKey === null) {
            throw new WebAuthnException('No credential was created.');
        }

        if (! hash_equals($authData->credentialId, Base64Url::decode((string) ($credential['rawId'] ?? '')))) {
            throw new WebAuthnException('Credential ID mismatch.');
        }

        CoseKey::fromCbor($authData->credentialPublicKey);

        $transports = array_values(array_filter(
            (array) data_get($credential, 'response.transports', []),
            fn ($transport) => is_string($transport) && in_array($transport, ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'], true)
        ));

        return [
            'credential_id' => Base64Url::encode($authData->credentialId),
            'public_key' => base64_encode($authData->credentialPublicKey),
            'counter' => $authData->signCount,
            'transports' => $transports,
        ];
    }

    /**
     * Verify an authentication response against one of the given methods and return the matched method.
     *
     * @param  Collection<int, MfaMethod>  $methods
     */
    public function verifyAssertion(Model $user, string $type, Collection $methods, array $credential): MfaMethod
    {
        $this->pullChallenge('get', $type, $user, $challenge);

        $rawId = Base64Url::decode((string) ($credential['rawId'] ?? ''));
        $method = $methods->first(fn (MfaMethod $method) => $method->isWebAuthn()
            && hash_equals(Base64Url::decode($method->credential_id), $rawId));

        if (! $method) {
            throw new WebAuthnException('Unknown credential.');
        }

        $clientDataJson = Base64Url::decode((string) data_get($credential, 'response.clientDataJSON', ''));
        $this->verifyClientData($clientDataJson, 'webauthn.get', $challenge);

        $authData = new AuthenticatorData(Base64Url::decode((string) data_get($credential, 'response.authenticatorData', '')));
        $this->verifyAuthenticatorData($authData, $type);

        $userHandle = data_get($credential, 'response.userHandle');
        if (is_string($userHandle) && $userHandle !== '' && ! hash_equals($this->userHandle($user), Base64Url::decode($userHandle))) {
            throw new WebAuthnException('User handle mismatch.');
        }

        $signature = Base64Url::decode((string) data_get($credential, 'response.signature', ''));
        $key = CoseKey::fromCbor(base64_decode($method->public_key));

        if (! $key->verify($authData->raw.hash('sha256', $clientDataJson, true), $signature)) {
            throw new WebAuthnException('Invalid signature.');
        }

        // Detect cloned authenticators: the counter must increase when the authenticator supports it.
        if ($authData->signCount > 0 || $method->counter > 0) {
            $updated = MfaMethod::query()
                ->whereKey($method->getKey())
                ->where('counter', '<', $authData->signCount)
                ->update(['counter' => $authData->signCount]);

            if ($updated === 0) {
                throw new WebAuthnException('Signature counter did not increase.');
            }

            $method->counter = $authData->signCount;
            $method->syncOriginalAttribute('counter');
        }

        return $method;
    }

    protected function requiresUserVerification(string $type): bool
    {
        return $type === MfaMethod::TYPE_BIOMETRIC;
    }

    protected function verifyClientData(string $json, string $expectedType, string $challenge): void
    {
        $clientData = json_decode($json, true);

        if (! is_array($clientData) || ($clientData['type'] ?? null) !== $expectedType) {
            throw new WebAuthnException('Invalid client data type.');
        }

        if (! is_string($clientData['challenge'] ?? null) || ! hash_equals($challenge, Base64Url::decode($clientData['challenge']))) {
            throw new WebAuthnException('Challenge mismatch.');
        }

        if (! is_string($clientData['origin'] ?? null) || ! hash_equals($this->origin(), $clientData['origin'])) {
            throw new WebAuthnException('Origin mismatch.');
        }

        if (($clientData['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('Cross-origin requests are not allowed.');
        }
    }

    protected function verifyAuthenticatorData(AuthenticatorData $authData, string $type): void
    {
        if (! hash_equals(hash('sha256', $this->rpId(), true), $authData->rpIdHash)) {
            throw new WebAuthnException('Relying party ID mismatch.');
        }

        if (! $authData->hasFlag(AuthenticatorData::FLAG_USER_PRESENT)) {
            throw new WebAuthnException('User presence is required.');
        }

        if ($this->requiresUserVerification($type) && ! $authData->hasFlag(AuthenticatorData::FLAG_USER_VERIFIED)) {
            throw new WebAuthnException('User verification is required.');
        }
    }

    /**
     * @param  Collection<int, MfaMethod>  $methods
     */
    protected function descriptors(Collection $methods): array
    {
        return $methods->filter(fn (MfaMethod $method) => $method->isWebAuthn())->map(fn (MfaMethod $method) => array_filter([
            'type' => 'public-key',
            'id' => $method->credential_id,
            'transports' => $method->transports ?: null,
        ]))->values()->all();
    }

    protected function newChallenge(string $ceremony, string $type, Model $user): string
    {
        $challenge = random_bytes(32);

        session()->put(self::SESSION_KEY, [
            'ceremony' => $ceremony,
            'type' => $type,
            'user_id' => (string) $user->getKey(),
            'challenge' => base64_encode($challenge),
            'expires_at' => time() + (int) ceil((int) config('secure-access.mfa.webauthn.timeout', 60000) / 1000) + 60,
        ]);

        return $challenge;
    }

    protected function pullChallenge(string $ceremony, string $type, Model $user, ?string &$challenge): void
    {
        $state = session()->pull(self::SESSION_KEY);

        if (! is_array($state)
            || $state['ceremony'] !== $ceremony
            || $state['type'] !== $type
            || $state['user_id'] !== (string) $user->getKey()
            || $state['expires_at'] < time()) {
            throw new WebAuthnException('The request has expired. Please try again.');
        }

        $challenge = base64_decode($state['challenge']);
    }
}
