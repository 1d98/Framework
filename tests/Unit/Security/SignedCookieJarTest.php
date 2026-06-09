<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Security;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Request\Request;
use Framework\Security\SignedCookieJar;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedCookieJar::class)]
final class SignedCookieJarTest extends TestCase
{
    public function testSignPayloadRoundtrip(): void
    {
        $jar = new SignedCookieJar('super-secret-key');

        $signed = $jar->sign('user_42');
        self::assertSame('user_42', $jar->payload($signed));
    }

    public function testSignWithEmptyValueRoundtrips(): void
    {
        $jar = new SignedCookieJar('key');

        $signed = $jar->sign('');
        self::assertStringStartsWith('.', $signed);
        self::assertSame('', $jar->payload($signed));
    }

    public function testPayloadReturnsNullForMissingDot(): void
    {
        $jar = new SignedCookieJar('key');

        self::assertNull($jar->payload('no-separator'));
    }

    public function testPayloadReturnsNullForTamperedValue(): void
    {
        $jar = new SignedCookieJar('key');
        $signed = $jar->sign('user_42');
        $dotPos = strpos($signed, '.');
        self::assertIsInt($dotPos);
        $tampered = 'user_99' . substr($signed, $dotPos);

        self::assertNull($jar->payload($tampered));
    }

    public function testPayloadReturnsNullForTamperedSignature(): void
    {
        $jar = new SignedCookieJar('key');
        $signed = $jar->sign('user_42');
        $dotPos = strpos($signed, '.');
        $tampered = substr($signed, 0, $dotPos + 1) . 'AAAA' . substr($signed, $dotPos + 5);

        self::assertNull($jar->payload($tampered));
    }

    public function testPayloadReturnsNullForInvalidBase64Signature(): void
    {
        $jar = new SignedCookieJar('key');

        self::assertNull($jar->payload('user_42.!!!not-base64!!!'));
    }

    public function testPayloadReturnsNullForSignatureFromDifferentSecret(): void
    {
        $jarA = new SignedCookieJar('secret-A');
        $jarB = new SignedCookieJar('secret-B');

        $signedFromA = $jarA->sign('value');

        self::assertNull($jarB->payload($signedFromA));
    }

    public function testPayloadWithExtraDotsSplitsOnFirst(): void
    {
        $jar = new SignedCookieJar('key');
        $signed = $jar->sign('value');

        $parts = explode('.', $signed, 2);
        $value = $parts[0];
        $originalSig = $parts[1];

        $reassembled = $value . '.' . $originalSig;
        self::assertSame('value', $jar->payload($reassembled));

        $tampered = $value . '.more.dots' . $originalSig;
        self::assertNull($jar->payload($tampered));
    }

    public function testVerifyReturnsTrueForValidSignature(): void
    {
        $jar = new SignedCookieJar('key');
        $signed = $jar->sign('user_42');

        self::assertTrue($jar->verify($signed));
    }

    public function testVerifyReturnsFalseForInvalidSignature(): void
    {
        $jar = new SignedCookieJar('key');

        self::assertFalse($jar->verify('garbage.invalidsig'));
    }

    public function testVerifyReturnsFalseForMultipleSegments(): void
    {
        $jar = new SignedCookieJar('key');

        self::assertFalse($jar->verify('no.sig.here'));
    }

    public function testVerifyReturnsFalseForEmptyString(): void
    {
        $jar = new SignedCookieJar('key');

        self::assertFalse($jar->verify(''));
    }

    public function testVerifyReturnsFalseForSignatureFromDifferentSecret(): void
    {
        $jarA = new SignedCookieJar('secret-A');
        $jarB = new SignedCookieJar('secret-B');

        $signedFromA = $jarA->sign('value');

        self::assertFalse($jarB->verify($signedFromA));
    }

    public function testConstructorThrowsOnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secret cannot be empty');

        new SignedCookieJar('');
    }

    public function testConstructorThrowsOnWhitespaceOnlySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secret cannot be empty');

        new SignedCookieJar('   ');
    }

    public function testConstructorThrowsOnUnsupportedAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported algorithm');

        new SignedCookieJar('key', 'fake-algo-999');
    }

    public function testConstructorAcceptsSha1AndMd5Algorithms(): void
    {
        new SignedCookieJar('key', 'sha1');
        new SignedCookieJar('key', 'md5');

        $this->expectNotToPerformAssertions();
    }

    public function testMakeCookieReturnsCookieWithSignedValue(): void
    {
        $jar = new SignedCookieJar('key');
        $cookie = $jar->makeCookie('session', 'alice', expiresAt: 0, secure: true);

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('session', $cookie->name);
        self::assertSame('alice', $jar->payload($cookie->value));
        self::assertTrue($jar->verify($cookie->value));
        self::assertTrue($cookie->secure);
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
    }

    public function testReadFromRequestReturnsVerifiedValue(): void
    {
        $jar = new SignedCookieJar('key');
        $request = new Request('GET', '/', '', [], '', null, null, null, [
            'session' => $jar->sign('alice'),
        ]);

        self::assertSame('alice', $jar->read($request, 'session'));
    }

    public function testReadReturnsNullForMissingCookie(): void
    {
        $jar = new SignedCookieJar('key');
        $request = new Request('GET', '/');

        self::assertNull($jar->read($request, 'session'));
    }

    public function testReadReturnsNullForTamperedCookie(): void
    {
        $jar = new SignedCookieJar('key');
        $request = new Request('GET', '/', '', [], '', null, null, null, [
            'session' => 'alice.badSig',
        ]);

        self::assertNull($jar->read($request, 'session'));
    }
}
