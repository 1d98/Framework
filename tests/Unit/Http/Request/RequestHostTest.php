<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\RequestHost;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestHost::class)]
final class RequestHostTest extends TestCase
{
    /**
     * @var array<int|string, mixed>
     */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testIsFinalReadonlyValueObject(): void
    {
        $reflection = new \ReflectionClass(RequestHost::class);
        self::assertTrue($reflection->isFinal(), 'RequestHost must be final');
        self::assertTrue($reflection->isReadOnly(), 'RequestHost must be readonly');
    }

    public function testConstructorPromotesAllProperties(): void
    {
        $host = new RequestHost(
            host: 'example.com:8080',
            isSecure: true,
            remoteAddr: '10.0.0.5',
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertSame('example.com:8080', $host->host);
        self::assertTrue($host->isSecure);
        self::assertSame('10.0.0.5', $host->remoteAddr);
        self::assertSame(['10.0.0.0/8'], $host->trustedProxies);
    }

    public function testEmptyConstructorProducesSensibleDefaults(): void
    {
        $host = new RequestHost();

        self::assertNull($host->host);
        self::assertFalse($host->isSecure);
        self::assertNull($host->remoteAddr);
        self::assertNull($host->trustedProxies);
    }

    public function testHostWithNullArgReturnsRawHeaderAsIs(): void
    {
        $host = new RequestHost(host: 'example.com:8080');

        self::assertSame('example.com:8080', $host->host(null));
    }

    public function testHostWithNullArgAndEmptyRawHeaderReturnsLocalhost(): void
    {
        $host = new RequestHost(host: '');

        self::assertSame('localhost', $host->host(null));
    }

    public function testHostOverrideArgWinsOverSnapshot(): void
    {
        $host = new RequestHost(host: 'snapshot.example.com');

        self::assertSame('override.example.com', $host->host(null, 'override.example.com'));
    }

    public function testHostReturnsExactMatch(): void
    {
        $host = new RequestHost(host: 'example.com');

        self::assertSame('example.com', $host->host(['example.com']));
    }

    public function testHostFallsBackToFirstTrustedPatternWhenUntrusted(): void
    {
        $host = new RequestHost(host: 'evil.com');

        self::assertSame('example.com', $host->host(['example.com']));
    }

    public function testHostFallsBackToFirstTrustedWhenMultiplePatterns(): void
    {
        $host = new RequestHost(host: 'evil.com');

        self::assertSame(
            'primary.example.com',
            $host->host(['primary.example.com', 'secondary.example.com']),
        );
    }

    public function testHostWildcardMatchesSubdomain(): void
    {
        $host = new RequestHost(host: 'api.example.com');

        self::assertSame('api.example.com', $host->host(['*.example.com']));
    }

    public function testHostWildcardMatchesApex(): void
    {
        $host = new RequestHost(host: 'example.com');

        self::assertSame('example.com', $host->host(['*.example.com']));
    }

    public function testHostWildcardDoesNotMatchUnrelatedDomain(): void
    {
        $host = new RequestHost(host: 'evil.com');

        self::assertSame('example.com', $host->host(['*.example.com']));
    }

    public function testHostWildcardDoesNotMatchSuffixOfUnrelatedDomain(): void
    {
        $host = new RequestHost(host: 'notexample.com');

        self::assertSame('example.com', $host->host(['*.example.com']));
    }

    public function testHostStripsPortBeforeMatching(): void
    {
        $host = new RequestHost(host: 'example.com:8080');

        self::assertSame('example.com', $host->host(['example.com']));
    }

    public function testHostStripsPortForWildcardMatch(): void
    {
        $host = new RequestHost(host: 'api.example.com:443');

        self::assertSame('api.example.com', $host->host(['*.example.com']));
    }

    public function testHostIsCaseInsensitive(): void
    {
        $host = new RequestHost(host: 'EXAMPLE.com');

        self::assertSame('example.com', $host->host(['example.com']));
    }

    public function testHostIsCaseInsensitiveForWildcard(): void
    {
        $host = new RequestHost(host: 'API.EXAMPLE.COM');

        self::assertSame('api.example.com', $host->host(['*.example.com']));
    }

    public function testHostThrowsOnCrlfInjection(): void
    {
        $host = new RequestHost(host: "example.com\r\nX-Evil: 1");

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid Host header');

        $host->host(['example.com']);
    }

    public function testHostThrowsOnLoneLfInjection(): void
    {
        $host = new RequestHost(host: "example.com\nX-Evil: 1");

        $this->expectException(BadRequestHttpException::class);

        $host->host(['example.com']);
    }

    public function testHostThrowsOnNulByteInjection(): void
    {
        $host = new RequestHost(host: "example.com\0.evil.com");

        $this->expectException(BadRequestHttpException::class);

        $host->host(['example.com']);
    }

    public function testHostThrowsOnEmptyTrustedHostsList(): void
    {
        $host = new RequestHost(host: 'example.com');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-empty trusted hosts list');

        /** @var list<string> $empty */
        $empty = [];
        $host->host($empty);
    }

    public function testHostFallsBackToLocalhostWhenHeaderMissingEvenWithTrustedList(): void
    {
        $host = new RequestHost(host: null);

        self::assertSame('localhost', $host->host(['example.com']));
    }

    public function testIsSecureReturnsTrueFromTransportSnapshot(): void
    {
        $host = new RequestHost(isSecure: true);

        self::assertTrue($host->isSecure());
    }

    public function testIsSecureIgnoresForwardedProtoWhenTransportIsSecure(): void
    {
        $host = new RequestHost(isSecure: true);

        self::assertTrue(
            $host->isSecure(null, null, 'http'),
            'A genuine HTTPS connection wins even when X-Forwarded-Proto says http',
        );
    }

    public function testIsSecureIsStrictWhenNoTrustedProxiesConfigured(): void
    {
        $host = new RequestHost(isSecure: false);

        self::assertFalse(
            $host->isSecure(null, null, 'https'),
            'Without a trust list, X-Forwarded-Proto must NEVER flip isSecure to true',
        );
    }

    public function testIsSecureIsStrictWhenTrustedProxiesEmpty(): void
    {
        $host = new RequestHost(isSecure: false, trustedProxies: []);

        self::assertFalse(
            $host->isSecure([], '127.0.0.1', 'https'),
            'Empty trust list behaves identically to null — header is ignored',
        );
    }

    public function testIsSecureReturnsTrueFromTrustedLoopbackProxy(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertTrue($host->isSecure(null, null, 'https'));
    }

    public function testIsSecureReturnsTrueFromLoopbackCidr(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.53',
            trustedProxies: ['127.0.0.0/8', '::1/128'],
        );

        self::assertTrue($host->isSecure(null, null, 'https'));
    }

    public function testIsSecureReturnsTrueFromPrivateCidr(): void
    {
        $ranges = [
            ['10.0.0.0/8', '10.1.2.3'],
            ['172.16.0.0/12', '172.16.0.5'],
            ['192.168.0.0/16', '192.168.42.7'],
        ];
        foreach ($ranges as [$cidr, $candidate]) {
            $host = new RequestHost(
                isSecure: false,
                remoteAddr: $candidate,
                trustedProxies: [$cidr],
            );
            self::assertTrue(
                $host->isSecure(null, null, 'https'),
                "CIDR {$cidr} should trust candidate {$candidate}",
            );
        }
    }

    public function testIsSecureReturnsFalseWhenRemoteAddrIsNotInCidr(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '192.168.1.1',
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertFalse($host->isSecure(null, null, 'https'));
    }

    public function testIsSecureIgnoresHeaderWhenRemoteAddrIsEmpty(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: null,
            trustedProxies: ['127.0.0.1'],
        );

        self::assertFalse($host->isSecure(null, null, 'https'));
    }

    public function testIsSecureRemoteAddrOverrideWinsOverSnapshot(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '198.51.100.7',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertTrue(
            $host->isSecure(null, '127.0.0.1', 'https'),
            'Caller-supplied remoteAddr overrides the snapshot for the trust check',
        );
    }

    public function testIsSecurePerCallTrustedProxiesWinsOverStoredList(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertTrue(
            $host->isSecure(['127.0.0.1'], null, 'https'),
            'Per-call trustedProxies wins over the stored list',
        );
    }

    public function testIsSecureReturnsFalseForPlainHttpWithTrustedProxy(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertFalse($host->isSecure(null, null, 'http'));
    }

    public function testIsSecureReturnsFalseWhenForwardedProtoHeaderMissing(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertFalse($host->isSecure(null, null, null));
    }

    public function testIsSecureRejectsMultiValueForwardedProtoFromTrustedProxy(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertFalse(
            $host->isSecure(null, null, 'https, http'),
            'Multi-value X-Forwarded-Proto is untrusted even from a trusted proxy',
        );
    }

    public function testIsSecureRejectsMultiValueForwardedProtoRegardlessOfLeftmostToken(): void
    {
        $cases = ['http, https', 'https, http', 'https, http, https'];
        foreach ($cases as $value) {
            $host = new RequestHost(
                isSecure: false,
                remoteAddr: '127.0.0.1',
                trustedProxies: ['127.0.0.1'],
            );
            self::assertFalse(
                $host->isSecure(null, null, $value),
                "Multi-value '{$value}' must never flip isSecure to true",
            );
        }
    }

    public function testIsSecureAcceptsSingleValueForwardedProtoWithWhitespaceAndUppercase(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertTrue($host->isSecure(null, null, '  HTTPS  '));
    }

    public function testIpReturnsImmediateAddrWhenNoTrustedProxiesConfigured(): void
    {
        $host = new RequestHost(remoteAddr: '203.0.113.5');

        self::assertSame('203.0.113.5', $host->ip());
    }

    public function testIpReturnsNullWhenNoRemoteAddrAndNoOverride(): void
    {
        $host = new RequestHost();

        self::assertNull($host->ip());
    }

    public function testIpIgnoresForwardedForWhenNoTrustedProxies(): void
    {
        $host = new RequestHost(remoteAddr: '203.0.113.5');

        self::assertSame(
            '203.0.113.5',
            $host->ip(null, null, '1.2.3.4'),
            'Without a trust list, X-Forwarded-For must NEVER be consulted',
        );
    }

    public function testIpIgnoresForwardedForWhenTrustedProxiesEmpty(): void
    {
        $host = new RequestHost(remoteAddr: '203.0.113.5', trustedProxies: []);

        self::assertSame('203.0.113.5', $host->ip([], null, '1.2.3.4'));
    }

    public function testIpReturnsImmediateAddrWhenRemoteNotTrusted(): void
    {
        $host = new RequestHost(
            remoteAddr: '8.8.8.8',
            trustedProxies: ['127.0.0.0/8'],
        );

        self::assertSame(
            '8.8.8.8',
            $host->ip(null, null, '1.2.3.4'),
            'Untrusted REMOTE_ADDR must yield the immediate address, not the forwarded one',
        );
    }

    public function testIpReturnsLeftmostForwardedAddrWhenTrustedProxy(): void
    {
        $host = new RequestHost(
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.0/8'],
        );

        self::assertSame('1.2.3.4', $host->ip(null, null, '1.2.3.4'));
    }

    public function testIpReturnsLeftmostTokenOfMultiValueForwardedFor(): void
    {
        $host = new RequestHost(
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.0/8'],
        );

        self::assertSame(
            '1.2.3.4',
            $host->ip(null, null, '1.2.3.4, 5.6.7.8, 9.10.11.12'),
        );
    }

    public function testIpReturnsImmediateWhenForwardedForEmptyDespiteTrust(): void
    {
        $host = new RequestHost(
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.0/8'],
        );

        self::assertSame('127.0.0.1', $host->ip(null, null, ''));
    }

    public function testIpReturnsImmediateWhenLeftmostTokenIsEmpty(): void
    {
        $host = new RequestHost(
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.0/8'],
        );

        self::assertSame('127.0.0.1', $host->ip(null, null, ', 5.6.7.8'));
    }

    public function testIpPerCallTrustedProxiesWinsOverStoredList(): void
    {
        $host = new RequestHost(
            remoteAddr: '127.0.0.1',
            trustedProxies: ['10.0.0.0/8'],
        );

        self::assertSame(
            '1.2.3.4',
            $host->ip(['127.0.0.0/8'], null, '1.2.3.4'),
            'Per-call trustedProxies wins over the stored list',
        );
    }

    public function testIpSupportsIpv6TrustedProxy(): void
    {
        $host = new RequestHost(
            remoteAddr: '::1',
            trustedProxies: ['::1/128'],
        );

        self::assertSame('2001:db8::1', $host->ip(null, null, '2001:db8::1'));
    }

    public function testSchemeReturnsHttpsFromTransportSnapshot(): void
    {
        $host = new RequestHost(isSecure: true);

        self::assertSame('https', $host->scheme());
    }

    public function testSchemeReturnsHttpWhenTransportInsecureAndNoTrust(): void
    {
        $host = new RequestHost(isSecure: false);

        self::assertSame('http', $host->scheme());
    }

    public function testSchemeReturnsHttpsWhenTrustedProxyAssertsHttps(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertSame('https', $host->scheme(null, null, 'https'));
    }

    public function testSchemeReturnsHttpWhenForwardedProtoIsHttp(): void
    {
        $host = new RequestHost(
            isSecure: false,
            remoteAddr: '127.0.0.1',
            trustedProxies: ['127.0.0.1'],
        );

        self::assertSame('http', $host->scheme(null, null, 'http'));
    }

    public function testSnapshotTransportHttpsReturnsFalseWhenServerEmpty(): void
    {
        unset($_SERVER['HTTPS']);

        self::assertFalse(RequestHost::snapshotTransportHttps());
    }

    public function testSnapshotTransportHttpsReturnsTrueWhenOn(): void
    {
        $_SERVER['HTTPS'] = 'on';

        self::assertTrue(RequestHost::snapshotTransportHttps());
    }

    public function testSnapshotTransportHttpsReturnsFalseWhenOff(): void
    {
        $_SERVER['HTTPS'] = 'off';

        self::assertFalse(RequestHost::snapshotTransportHttps());
    }

    public function testSnapshotTransportHttpsIsCaseInsensitive(): void
    {
        $_SERVER['HTTPS'] = 'ON';

        self::assertTrue(RequestHost::snapshotTransportHttps());
    }

    public function testSnapshotRemoteAddrReturnsNullWhenEmpty(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        self::assertNull(RequestHost::snapshotRemoteAddr());
    }

    public function testSnapshotRemoteAddrReturnsValueWhenSet(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        self::assertSame('203.0.113.99', RequestHost::snapshotRemoteAddr());
    }

    public function testSnapshotHostHeaderReturnsNullWhenEmpty(): void
    {
        unset($_SERVER['HTTP_HOST']);

        self::assertNull(RequestHost::snapshotHostHeader());
    }

    public function testSnapshotHostHeaderReturnsValueWhenSet(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com:8080';

        self::assertSame('example.com:8080', RequestHost::snapshotHostHeader());
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function hostPatternMatrix(): array
    {
        return [
            ['example.com', 'example.com', true, 'exact match'],
            ['example.com', 'other.com', false, 'no match'],
            ['api.example.com', '*.example.com', true, 'single-label subdomain'],
            ['a.b.example.com', '*.example.com', true, 'multi-label subdomain'],
            ['example.com', '*.example.com', true, 'apex matches wildcard'],
            ['notexample.com', '*.example.com', false, 'suffix of unrelated domain'],
            ['example.com', 'example.com:8080', false, 'port suffix does not match bare label'],
        ];
    }

    #[DataProvider('hostPatternMatrix')]
    public function testHostPatternMatrix(
        string $candidate,
        string $pattern,
        bool $expected,
        string $label,
    ): void {
        self::assertSame(
            $expected,
            RequestHost::hostMatchesPattern($candidate, $pattern),
            "Pattern '{$pattern}' vs candidate '{$candidate}' ({$label})",
        );
    }
}
