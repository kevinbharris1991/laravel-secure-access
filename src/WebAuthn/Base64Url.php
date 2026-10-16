<?php

namespace SecureAccess\WebAuthn;

class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw new WebAuthnException('Invalid base64url data.');
        }

        return $decoded;
    }
}
