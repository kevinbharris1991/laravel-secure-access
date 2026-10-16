<?php

namespace SecureAccess\Totp;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * RFC 6238 time-based one-time passwords compatible with authenticator apps.
 */
class Totp
{
    protected const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return static::base32Encode(random_bytes($bytes));
    }

    public function digits(): int
    {
        return (int) config('secure-access.mfa.totp.digits', 6);
    }

    public function period(): int
    {
        return (int) config('secure-access.mfa.totp.period', 30);
    }

    public function timestep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), $this->period());
    }

    public function code(string $secret, int $timestep): string
    {
        $hash = hash_hmac('sha1', pack('J', $timestep), static::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $this->digits())), $this->digits(), '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code. Returns the matching time step, or null when the code is invalid
     * or belongs to a time step that is not newer than $lastTimestep (replay protection).
     */
    public function verify(string $secret, string $code, int $lastTimestep = 0, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.$this->digits().'}$/', $code)) {
            return null;
        }

        $current = $this->timestep($timestamp);
        $window = (int) config('secure-access.mfa.totp.window', 1);

        for ($step = $current - $window; $step <= $current + $window; $step++) {
            if ($step > $lastTimestep && hash_equals($this->code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $issuer = (string) config('secure-access.mfa.totp.issuer', config('app.name'));

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $this->digits(),
            'period' => $this->period(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function qrCodeSvg(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $output;
    }

    public static function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[\s=]+/', '', $data));
        $bits = '';

        foreach (str_split($data) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid base32 character.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
