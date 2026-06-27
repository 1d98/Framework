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
    /**
     * 32-character secret — well above the {@see SignedCookieJar::MIN_SECRET_BYTES}
     * 16-byte minimum. Used as the default fixture for tests that do not care about
     * the specific secret value but need a long enough one to construct the jar.
     */
    private const string SECRET = 'unit-test-secret-32-chars-long';

    public function testSignPayloadRoundtrip(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        $signed = $jar->sign('user_42');
        self::assertSame('user_42', $jar->payload($signed));
    }

    public function testSignWithEmptyValueRoundtrips(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        $signed = $jar->sign('');
        self::assertStringStartsWith('.', $signed);
        self::assertSame('', $jar->payload($signed));
    }

    public function testPayloadReturnsNullForMissingDot(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        self::assertNull($jar->payload('no-separator'));
    }

    public function testPayloadReturnsNullForTamperedValue(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
        $signed = $jar->sign('user_42');
        $dotPos = strpos($signed, '.');
        self::assertIsInt($dotPos);
        $tampered = 'user_99' . substr($signed, $dotPos);

        self::assertNull($jar->payload($tampered));
    }

    public function testPayloadReturnsNullForTamperedSignature(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
        $signed = $jar->sign('user_42');
        $dotPos = strpos($signed, '.');
        $tampered = substr($signed, 0, $dotPos + 1) . 'AAAA' . substr($signed, $dotPos + 5);

        self::assertNull($jar->payload($tampered));
    }

    public function testPayloadReturnsNullForInvalidBase64Signature(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        self::assertNull($jar->payload('user_42.!!!not-base64!!!'));
    }

    public function testPayloadReturnsNullForSignatureFromDifferentSecret(): void
    {
        $jarA = new SignedCookieJar('secret-A-long-enough-32-bytes-yes');
        $jarB = new SignedCookieJar('secret-B-long-enough-32-bytes-yes');

        $signedFromA = $jarA->sign('value');

        self::assertNull($jarB->payload($signedFromA));
    }

    public function testPayloadWithExtraDotsSplitsOnFirst(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
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
        $jar = new SignedCookieJar(self::SECRET);
        $signed = $jar->sign('user_42');

        self::assertTrue($jar->verify($signed));
    }

    public function testVerifyReturnsFalseForInvalidSignature(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        self::assertFalse($jar->verify('garbage.invalidsig'));
    }

    public function testVerifyReturnsFalseForMultipleSegments(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        self::assertFalse($jar->verify('no.sig.here'));
    }

    public function testVerifyReturnsFalseForEmptyString(): void
    {
        $jar = new SignedCookieJar(self::SECRET);

        self::assertFalse($jar->verify(''));
    }

    public function testVerifyReturnsFalseForSignatureFromDifferentSecret(): void
    {
        $jarA = new SignedCookieJar('secret-A-long-enough-32-bytes-yes');
        $jarB = new SignedCookieJar('secret-B-long-enough-32-bytes-yes');

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

    public function testConstructorRejectsShortSecret(): void
    {
        // 15 bytes — one byte below the MIN_SECRET_BYTES = 16 floor.
        // 'fifteen-bytes-xx' is intentionally crafted to be exactly 15
        // bytes (f-i-f-t-e-e-n + -bytes- + x = 15 chars).
        $shortSecret = 'fifteen-bytes-x';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('secret is too short');
        $this->expectExceptionMessage('minimum is 16');

        new SignedCookieJar($shortSecret);
    }

    public function testConstructorAcceptsSecretAtExactly16Bytes(): void
    {
        $exactSecret = str_repeat('a', 16);

        $jar = new SignedCookieJar($exactSecret);

        // Sanity check: round-trips through sign/payload.
        $signed = $jar->sign('hello');
        self::assertSame('hello', $jar->payload($signed));
    }

    public function testConstructorThrowsOnUnsupportedAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported algorithm');

        new SignedCookieJar(self::SECRET, 'fake-algo-999');
    }

    public function testConstructorRejectsSha1Algorithm(): void
    {
        // sha1 is no longer in the allowlist — defense against collision attacks
        // on session-bearing cookies.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported algorithm');
        $this->expectExceptionMessage('sha1');

        new SignedCookieJar(self::SECRET, 'sha1');
    }

    public function testConstructorRejectsMd5Algorithm(): void
    {
        // md5 has been broken since 2004; we explicitly reject it from the start.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported algorithm');
        $this->expectExceptionMessage('md5');

        new SignedCookieJar(self::SECRET, 'md5');
    }

    public function testConstructorAcceptsSha3Algorithms(): void
    {
        // SHA-3 family is in the allowlist — verify each variant constructs cleanly.
        foreach (['sha3-256', 'sha3-384', 'sha3-512'] as $algo) {
            $jar = new SignedCookieJar(self::SECRET, $algo);
            $signed = $jar->sign('payload-' . $algo);
            self::assertSame('payload-' . $algo, $jar->payload($signed));
        }
    }

    public function testMakeCookieReturnsCookieWithSignedValue(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
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
        $jar = new SignedCookieJar(self::SECRET);
        $request = new Request('GET', '/', '', [], '', null, null, null, [
            'session' => $jar->sign('alice'),
        ]);

        self::assertSame('alice', $jar->read($request, 'session'));
    }

    public function testReadReturnsNullForMissingCookie(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
        $request = new Request('GET', '/');

        self::assertNull($jar->read($request, 'session'));
    }

    public function testReadReturnsNullForTamperedCookie(): void
    {
        $jar = new SignedCookieJar(self::SECRET);
        $request = new Request('GET', '/', '', [], '', null, null, null, [
            'session' => 'alice.badSig',
        ]);

        self::assertNull($jar->read($request, 'session'));
    }
}