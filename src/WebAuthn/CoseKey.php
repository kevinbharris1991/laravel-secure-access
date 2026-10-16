<?php

namespace SecureAccess\WebAuthn;

/**
 * COSE (RFC 9053) public key as found in WebAuthn attested credential data.
 */
class CoseKey
{
    public const ALG_ES256 = -7;

    public const ALG_EDDSA = -8;

    public const ALG_RS256 = -257;

    public const SUPPORTED_ALGORITHMS = [self::ALG_ES256, self::ALG_EDDSA, self::ALG_RS256];

    /**
     * @param  array<int|string, mixed>  $key
     */
    public function __construct(protected array $key)
    {
        $this->validate();
    }

    public static function fromCbor(string $cbor): self
    {
        $key = Cbor::decode($cbor);

        if (! is_array($key)) {
            throw new WebAuthnException('Invalid COSE key.');
        }

        return new self($key);
    }

    public function algorithm(): int
    {
        return $this->key[3];
    }

    public function verify(string $data, string $signature): bool
    {
        if ($this->algorithm() === self::ALG_EDDSA) {
            return function_exists('sodium_crypto_sign_verify_detached')
                && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $data, $this->key[-2]);
        }

        $publicKey = openssl_pkey_get_public($this->toPem());

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function toPem(): string
    {
        $der = match ($this->algorithm()) {
            self::ALG_ES256 => hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
                ."\x04".$this->key[-2].$this->key[-3],
            self::ALG_RS256 => $this->rsaDer(),
            default => throw new WebAuthnException('Key type cannot be exported as PEM.'),
        };

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    protected function validate(): void
    {
        $kty = $this->key[1] ?? null;
        $alg = $this->key[3] ?? null;

        $valid = match ($alg) {
            self::ALG_ES256 => $kty === 2 && ($this->key[-1] ?? null) === 1
                && is_string($this->key[-2] ?? null) && strlen($this->key[-2]) === 32
                && is_string($this->key[-3] ?? null) && strlen($this->key[-3]) === 32,
            self::ALG_EDDSA => $kty === 1 && ($this->key[-1] ?? null) === 6
                && is_string($this->key[-2] ?? null) && strlen($this->key[-2]) === 32,
            self::ALG_RS256 => $kty === 3
                && is_string($this->key[-1] ?? null) && strlen($this->key[-1]) >= 256
                && is_string($this->key[-2] ?? null) && $this->key[-2] !== '',
            default => false,
        };

        if (! $valid) {
            throw new WebAuthnException('Unsupported or malformed public key.');
        }
    }

    protected function rsaDer(): string
    {
        $rsaPublicKey = self::sequence(self::integer($this->key[-1]).self::integer($this->key[-2]));
        $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $bitString = "\x03".self::length(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;

        return self::sequence($algorithm.$bitString);
    }

    protected static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    protected static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value === '' || ord($value[0]) > 0x7F) {
            $value = "\x00".$value;
        }

        return "\x02".self::length(strlen($value)).$value;
    }

    protected static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
