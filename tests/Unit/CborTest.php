<?php

namespace SecureAccess\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecureAccess\WebAuthn\AuthenticatorData;
use SecureAccess\WebAuthn\Cbor;
use SecureAccess\WebAuthn\CoseKey;
use SecureAccess\WebAuthn\WebAuthnException;

class CborTest extends TestCase
{
    public function test_decodes_rfc8949_examples(): void
    {
        $this->assertSame(0, Cbor::decode(hex2bin('00')));
        $this->assertSame(23, Cbor::decode(hex2bin('17')));
        $this->assertSame(1000000, Cbor::decode(hex2bin('1a000f4240')));
        $this->assertSame(-1000, Cbor::decode(hex2bin('3903e7')));
        $this->assertSame("\x01\x02\x03\x04", Cbor::decode(hex2bin('4401020304')));
        $this->assertSame('IETF', Cbor::decode(hex2bin('6449455446')));
        $this->assertSame([1, [2, 3], [4, 5]], Cbor::decode(hex2bin('8301820203820405')));
        $this->assertSame(['a' => 1, 'b' => [2, 3]], Cbor::decode(hex2bin('a26161016162820203')));
        $this->assertTrue(Cbor::decode(hex2bin('f5')));
        $this->assertNull(Cbor::decode(hex2bin('f6')));
        $this->assertSame(1.5, Cbor::decode(hex2bin('f93e00')));
    }

    public function test_rejects_truncated_and_trailing_data(): void
    {
        foreach (['44010203', '8301', '0000', '5f', 'ff'] as $hex) {
            try {
                Cbor::decode(hex2bin($hex));
                $this->fail("Expected failure for {$hex}");
            } catch (WebAuthnException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_deeply_nested_data(): void
    {
        $this->expectException(WebAuthnException::class);

        Cbor::decode(str_repeat("\x81", 100).hex2bin('00'));
    }

    public function test_rejects_unsupported_keys(): void
    {
        $this->expectException(WebAuthnException::class);

        // EC2 key on an unsupported curve (P-384).
        new CoseKey([1 => 2, 3 => -7, -1 => 2, -2 => str_repeat('a', 32), -3 => str_repeat('b', 32)]);
    }

    public function test_rejects_short_authenticator_data(): void
    {
        $this->expectException(WebAuthnException::class);

        new AuthenticatorData(str_repeat("\0", 36));
    }
}
