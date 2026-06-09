<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testIsSecureIsStrictWhenNoTrustedProxiesConfigured(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure());
    }

    public function testIsSecureIsStrictWhenTrustedProxiesEmpty(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure([], '127.0.0.1'));
    }

    public function testIsSecureReturnsTrueFromTrustedLoopbackProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureReturnsTrueFromLoopbackCidr(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(Request::TRUST_LOOPBACK, '127.0.0.53'));
    }

    public function testIsSecureReturnsTrueFromPrivateCidr(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['10.0.0.0/8'], '10.1.2.3'));
        self::assertTrue($request->isSecure(['172.16.0.0/12'], '172.16.0.5'));
        self::assertTrue($request->isSecure(['192.168.0.0/16'], '192.168.42.7'));
    }

    public function testIsSecureReturnsFalseWhenRemoteAddrIsNotInCidr(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure(['10.0.0.0/8'], '192.168.1.1'));
        self::assertFalse($request->isSecure(['10.0.0.0/8'], '11.0.0.1'));
    }

    public function testIsSecureSupportsIpv6Loopback(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['::1'], '::1'));
        self::assertTrue($request->isSecure(['::1/128'], '::1'));
    }

    public function testIsSecureSupportsIpv6Cidr(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['2001:db8::/32'], '2001:db8::1'));
        self::assertFalse($request->isSecure(['2001:db8::/32'], '2001:db9::1'));
    }

    public function testIsSecureDoesNotMatchIpv4CandidateAgainstIpv6Network(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure(['::1'], '127.0.0.1'));
        self::assertFalse($request->isSecure(['2001:db8::/32'], '192.168.1.1'));
    }

    public function testIsSecureDoesNotMatchIpv6CandidateAgainstIpv4Network(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure(['127.0.0.1'], '::1'));
        self::assertFalse($request->isSecure(['10.0.0.0/8'], '2001:db8::1'));
    }

    public function testIsSecureIgnoresHeaderWhenRemoteAddrIsEmpty(): void
    {
        $_SERVER['REMOTE_ADDR'] = '';
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure(['127.0.0.1']));
    }

    public function testIsSecureReadsRemoteAddrFromServerWhenNotProvided(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['10.0.0.0/8']));
    }

    public function testIsSecureReturnsFalseForPlainHttpWithTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'http']);

        self::assertFalse($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureReturnsFalseWhenForwardedProtoHeaderMissing(): void
    {
        $request = new Request('GET', '/');

        self::assertFalse($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureReturnsTrueForGenuineHttpsRegardlessOfTrustedProxies(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $request = new Request('GET', '/');

        self::assertTrue($request->isSecure());
        self::assertTrue($request->isSecure(null, '192.168.1.1'));
        unset($_SERVER['HTTPS']);
    }

    public function testIsSecureReturnsTrueForHttpsWithEmptyTrustedProxiesWhenGenuineHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure([]));
        unset($_SERVER['HTTPS']);
    }

    public function testIsSecureTreatsHttpsOffEnvVarAsInsecure(): void
    {
        $_SERVER['HTTPS'] = 'off';
        $request = new Request('GET', '/');

        self::assertFalse($request->isSecure());
        unset($_SERVER['HTTPS']);
    }

    public function testIsSecureRejectsMultiValueForwardedProtoFromTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http']);

        self::assertFalse(
            $request->isSecure(['127.0.0.1'], '127.0.0.1'),
            'Multi-value X-Forwarded-Proto is untrusted even from a trusted proxy; the leftmost token must not flip isSecure() to true',
        );
    }

    public function testIsSecureRejectsMultiValueForwardedProtoRegardlessOfLeftmostToken(): void
    {
        $httpFirst = new Request('GET', '/', '', ['x-forwarded-proto' => 'http, https']);
        $httpsFirst = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http']);
        $triple = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http, https']);

        self::assertFalse($httpFirst->isSecure(['127.0.0.1'], '127.0.0.1'));
        self::assertFalse($httpsFirst->isSecure(['127.0.0.1'], '127.0.0.1'));
        self::assertFalse($triple->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureAcceptsSingleValueForwardedProtoFromTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureAcceptsSingleValueForwardedProtoWithWhitespaceAndUppercase(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => '  HTTPS  ']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureAcceptsSingleValueHttpsCaseVariationsFromTrustedProxy(): void
    {
        $cases = ['https', 'HTTPS', 'Https', 'hTTpS'];
        foreach ($cases as $value) {
            $request = new Request('GET', '/', '', ['x-forwarded-proto' => $value]);
            self::assertTrue(
                $request->isSecure(['127.0.0.1'], '127.0.0.1'),
                "Single-value `{$value}` must be honored as https by a trusted proxy",
            );
        }
    }

    public function testIsSecureFallsThroughToActualSchemeForMultiValueHeaderWithoutTrustedProxies(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http']);

        self::assertFalse(
            $request->isSecure(null, '127.0.0.1'),
            'With no trusted-proxies list, the X-Forwarded-Proto header is ignored regardless of value shape',
        );
        self::assertFalse(
            $request->isSecure([], '127.0.0.1'),
            'Empty trusted-proxies list behaves identically to null',
        );
    }

    public function testIsSecureGenuineHttpsWinsOverMultiValueHeader(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
        unset($_SERVER['HTTPS']);
    }

    public function testTrustLoopbackConstantExposesLoopbackRanges(): void
    {
        self::assertSame(['127.0.0.0/8', '::1/128'], Request::TRUST_LOOPBACK);
    }

    public function testTrustPrivateConstantExposesRfc1918Ranges(): void
    {
        self::assertSame(
            ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            Request::TRUST_PRIVATE,
        );
    }

    /**
     * @return list<array{0: list<string>, 1: string, 2: bool, 3: string}>
     */
    public static function trustedProxyMatrix(): array
    {
        return [
            [['127.0.0.1'], '127.0.0.1', true, 'exact loopback IPv4'],
            [['127.0.0.0/8'], '127.99.99.99', true, 'loopback /8'],
            [['10.0.0.0/8'], '10.255.255.255', true, 'private /8'],
            [['172.16.0.0/12'], '172.16.0.1', true, 'private /12 lower'],
            [['172.16.0.0/12'], '172.31.255.255', true, 'private /12 upper'],
            [['172.16.0.0/12'], '172.32.0.1', false, 'private /12 miss'],
            [['192.168.0.0/16'], '192.168.1.1', true, 'private /16'],
            [['192.168.0.0/16'], '10.0.0.1', false, 'private miss'],
            [['::1'], '::1', true, 'exact IPv6 loopback'],
            [['2001:db8::/127'], '2001:db8::1', true, 'IPv6 /127'],
            [['2001:db8::/127'], '2001:db8::2', false, 'IPv6 /127 miss'],
            [['::1'], '127.0.0.1', false, 'family mismatch v6 net / v4 candidate'],
            [['127.0.0.1'], '::1', false, 'family mismatch v4 net / v6 candidate'],
        ];
    }

    /**
     * @param list<string> $proxies
     */
    #[DataProvider('trustedProxyMatrix')]
    public function testTrustedProxyMatrix(array $proxies, string $candidate, bool $expected, string $label): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertSame(
            $expected,
            $request->isSecure($proxies, $candidate),
            "Case '{$label}' failed: proxies=" . json_encode($proxies) . ", candidate={$candidate}",
        );
    }
}
