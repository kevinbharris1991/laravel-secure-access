<?php

namespace SecureAccess\WebAuthn;

/**
 * Parsed WebAuthn authenticator data (https://www.w3.org/TR/webauthn-2/#sctn-authenticator-data).
 */
class AuthenticatorData
{
    public const FLAG_USER_PRESENT = 0x01;

    public const FLAG_USER_VERIFIED = 0x04;

    public const FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    public const FLAG_EXTENSION_DATA = 0x80;

    public string $rpIdHash;

    public int $flags;

    public int $signCount;

    public ?string $credentialId = null;

    public ?string $credentialPublicKey = null;

    public function __construct(public string $raw)
    {
        if (strlen($raw) < 37) {
            throw new WebAuthnException('Authenticator data is too short.');
        }

        $this->rpIdHash = substr($raw, 0, 32);
        $this->flags = ord($raw[32]);
        $this->signCount = unpack('N', substr($raw, 33, 4))[1];

        $offset = 37;

        if ($this->hasFlag(self::FLAG_ATTESTED_CREDENTIAL_DATA)) {
            if (strlen($raw) < $offset + 18) {
                throw new WebAuthnException('Attested credential data is truncated.');
            }

            $offset += 16; // AAGUID
            $length = unpack('n', substr($raw, $offset, 2))[1];
            $offset += 2;

            if ($length === 0 || strlen($raw) < $offset + $length) {
                throw new WebAuthnException('Credential ID is invalid.');
            }

            $this->credentialId = substr($raw, $offset, $length);
            $offset += $length;

            $start = $offset;
            Cbor::decodeItem($raw, $offset);
            $this->credentialPublicKey = substr($raw, $start, $offset - $start);
        }

        if ($this->hasFlag(self::FLAG_EXTENSION_DATA)) {
            Cbor::decodeItem($raw, $offset);
        }

        if ($offset !== strlen($raw)) {
            throw new WebAuthnException('Unexpected trailing authenticator data.');
        }
    }

    public function hasFlag(int $flag): bool
    {
        return ($this->flags & $flag) === $flag;
    }
}
