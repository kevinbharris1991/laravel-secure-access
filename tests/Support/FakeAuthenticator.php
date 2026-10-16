<?php

namespace SecureAccess\Tests\Support;

use SecureAccess\WebAuthn\Base64Url;

/**
 * Software WebAuthn authenticator used to exercise the relying party in tests.
 */
class FakeAuthenticator
{
    public string $credentialId;

    public int $counter = 0;

    public string $rpId = 'example.test';

    public string $origin = 'https://example.test';

    protected mixed $privateKey;

    protected string $cosePublicKey;

    public function __construct(public string $algorithm = 'es256')
    {
        $this->credentialId = random_bytes(32);

        switch ($algorithm) {
            case 'es256':
                $this->privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
                $ec = openssl_pkey_get_details($this->privateKey)['ec'];
                $this->cosePublicKey = self::map([
                    [self::int(1), self::int(2)],
                    [self::int(3), self::int(-7)],
                    [self::int(-1), self::int(1)],
                    [self::int(-2), self::bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))],
                    [self::int(-3), self::bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))],
                ]);
                break;
            case 'rs256':
                $this->privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
                $rsa = openssl_pkey_get_details($this->privateKey)['rsa'];
                $this->cosePublicKey = self::map([
                    [self::int(1), self::int(3)],
                    [self::int(3), self::int(-257)],
                    [self::int(-1), self::bytes($rsa['n'])],
                    [self::int(-2), self::bytes($rsa['e'])],
                ]);
                break;
            case 'eddsa':
                $this->privateKey = sodium_crypto_sign_keypair();
                $this->cosePublicKey = self::map([
                    [self::int(1), self::int(1)],
                    [self::int(3), self::int(-8)],
                    [self::int(-1), self::int(6)],
                    [self::int(-2), self::bytes(sodium_crypto_sign_publickey($this->privateKey))],
                ]);
                break;
        }
    }

    public function register(array $options, bool $userVerified = true, array $clientDataOverrides = []): array
    {
        $flags = 0x01 | 0x40 | ($userVerified ? 0x04 : 0);
        $authData = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->cosePublicKey;

        $clientData = json_encode($clientDataOverrides + [
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => $this->origin,
            'crossOrigin' => false,
        ]);

        $attestation = self::map([
            [self::text('fmt'), self::text('none')],
            [self::text('attStmt'), self::map([])],
            [self::text('authData'), self::bytes($authData)],
        ]);

        return [
            'id' => Base64Url::encode($this->credentialId),
            'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'attestationObject' => Base64Url::encode($attestation),
                'transports' => ['usb', 'bogus'],
            ],
        ];
    }

    public function assert(array $options, bool $userVerified = true, array $clientDataOverrides = [], bool $incrementCounter = true): array
    {
        if ($incrementCounter) {
            $this->counter++;
        }

        $authData = hash('sha256', $this->rpId, true).chr(0x01 | ($userVerified ? 0x04 : 0)).pack('N', $this->counter);
        $clientData = json_encode($clientDataOverrides + [
            'type' => 'webauthn.get',
            'challenge' => $options['challenge'],
            'origin' => $this->origin,
        ]);

        return [
            'id' => Base64Url::encode($this->credentialId),
            'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authData),
                'signature' => Base64Url::encode($this->sign($authData.hash('sha256', $clientData, true))),
                'userHandle' => null,
            ],
        ];
    }

    protected function sign(string $data): string
    {
        if ($this->algorithm === 'eddsa') {
            return sodium_crypto_sign_detached($data, sodium_crypto_sign_secretkey($this->privateKey));
        }

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    public static function int(int $value): string
    {
        return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
    }

    public static function bytes(string $value): string
    {
        return self::head(2, strlen($value)).$value;
    }

    public static function text(string $value): string
    {
        return self::head(3, strlen($value)).$value;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $pairs  Encoded key/value pairs.
     */
    public static function map(array $pairs): string
    {
        return self::head(5, count($pairs)).implode('', array_map(fn ($pair) => $pair[0].$pair[1], $pairs));
    }

    public static function head(int $major, int $value): string
    {
        return match (true) {
            $value < 24 => chr($major << 5 | $value),
            $value < 0x100 => chr($major << 5 | 24).chr($value),
            $value < 0x10000 => chr($major << 5 | 25).pack('n', $value),
            default => chr($major << 5 | 26).pack('N', $value),
        };
    }
}
