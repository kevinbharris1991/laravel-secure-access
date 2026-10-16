<?php

namespace SecureAccess\WebAuthn;

/**
 * Minimal CBOR (RFC 8949) decoder covering the subset used by WebAuthn / CTAP2.
 * Byte strings and text strings are both returned as PHP strings.
 */
class Cbor
{
    protected const MAX_DEPTH = 16;

    /**
     * Decode a single CBOR item that must span the whole input.
     */
    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value = static::decodeItem($data, $offset);

        if ($offset !== strlen($data)) {
            throw new WebAuthnException('Unexpected trailing CBOR data.');
        }

        return $value;
    }

    /**
     * Decode a single CBOR item starting at $offset, advancing $offset past it.
     */
    public static function decodeItem(string $data, int &$offset, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new WebAuthnException('CBOR data is nested too deeply.');
        }

        $initial = ord(static::read($data, $offset, 1));
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return static::decodeSimple($data, $offset, $info);
        }

        $length = static::readLength($data, $offset, $info);

        switch ($major) {
            case 0:
                return $length;
            case 1:
                return -1 - $length;
            case 2:
            case 3:
                return static::read($data, $offset, $length);
            case 4:
                $items = [];
                for ($i = 0; $i < $length; $i++) {
                    $items[] = static::decodeItem($data, $offset, $depth + 1);
                }

                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $length; $i++) {
                    $key = static::decodeItem($data, $offset, $depth + 1);
                    if (! is_int($key) && ! is_string($key)) {
                        throw new WebAuthnException('Unsupported CBOR map key.');
                    }
                    $map[$key] = static::decodeItem($data, $offset, $depth + 1);
                }

                return $map;
            case 6:
                // Tags carry no meaning for WebAuthn; return the tagged item.
                return static::decodeItem($data, $offset, $depth + 1);
        }

        throw new WebAuthnException('Unsupported CBOR major type.');
    }

    protected static function readLength(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        $value = match ($info) {
            24 => ord(static::read($data, $offset, 1)),
            25 => unpack('n', static::read($data, $offset, 2))[1],
            26 => unpack('N', static::read($data, $offset, 4))[1],
            27 => unpack('J', static::read($data, $offset, 8))[1],
            default => throw new WebAuthnException('Unsupported CBOR length encoding.'),
        };

        if ($value < 0) {
            throw new WebAuthnException('CBOR length out of range.');
        }

        return $value;
    }

    protected static function decodeSimple(string $data, int &$offset, int $info): mixed
    {
        return match ($info) {
            20 => false,
            21 => true,
            22, 23 => null,
            25 => static::decodeHalfFloat(unpack('n', static::read($data, $offset, 2))[1]),
            26 => unpack('G', static::read($data, $offset, 4))[1],
            27 => unpack('E', static::read($data, $offset, 8))[1],
            default => throw new WebAuthnException('Unsupported CBOR simple value.'),
        };
    }

    protected static function decodeHalfFloat(int $half): float
    {
        $exp = ($half >> 10) & 0x1F;
        $mant = $half & 0x3FF;
        $value = match (true) {
            $exp === 0 => $mant * 2 ** -24,
            $exp !== 31 => ($mant + 1024) * 2 ** ($exp - 25),
            $mant === 0 => INF,
            default => NAN,
        };

        return ($half & 0x8000) ? -$value : $value;
    }

    protected static function read(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new WebAuthnException('Unexpected end of CBOR data.');
        }

        $chunk = substr($data, $offset, $length);
        $offset += $length;

        return $chunk;
    }
}
