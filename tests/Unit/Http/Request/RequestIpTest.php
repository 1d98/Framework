<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestIpTest extends TestCase
{
    /**
     * @var array<int|string, mixed>
     */
    private array $serverBackup = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $getBackup = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $postBackup = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $cookieBackup = [];

    /**
     * @var array<int|string, mixed>
     */
    private array $filesBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $this->filesBackup = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_FILES = $this->filesBackup;
    }

    public function testFromGlobalsWithNoForwardedHeaderReturnsRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('203.0.113.5', $request->ip());
        self::assertSame('203.0.113.5', $request->ip(['10.0.0.0/8']));
    }

    public function testFromGlobalsWithSingleValueForwardedHeaderAndTrustedProxyReturnsLeftmost(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('1.2.3.4', $request->ip(['127.0.0.0/8']));
    }

    public function testFromGlobalsWithMultiValueForwardedHeaderAndTrustedProxyReturnsLeftmost(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8, 9.10.11.12';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('1.2.3.4', $request->ip(Request::TRUST_LOOPBACK));
    }

    public function testFromGlobalsWithForwardedHeaderButNoTrustedProxyIgnoresHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('198.51.100.7', $request->ip());
        self::assertSame('198.51.100.7', $request->ip(null));
        self::assertSame('198.51.100.7', $request->ip([]));
    }

    public function testFromGlobalsWithoutRemoteAddrReturnsNull(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertNull($request->ip());
    }

    public function testFromGlobalsWithEmptyRemoteAddrReturnsNull(): void
    {
        $_SERVER['REMOTE_ADDR'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertNull($request->ip());
    }

    public function testIpDefaultsToRemoteAddrWithoutTrustedProxiesEvenWhenHeaderIsPresent(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '1.2.3.4']);

        self::assertSame('203.0.113.10', $request->ip(null, '203.0.113.10'));
    }

    public function testIpWithEmptyTrustedProxiesListBehavesLikeNull(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '1.2.3.4']);

        self::assertSame('203.0.113.10', $request->ip([], '203.0.113.10'));
    }

    public function testIpReturnsRemoteAddrWhenImmediateIsNotInTrustedList(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '1.2.3.4']);

        self::assertSame(
            '198.51.100.42',
            $request->ip(['10.0.0.0/8'], '198.51.100.42'),
            'An untrusted immediate connection must not be allowed to set the IP via X-Forwarded-For',
        );
    }

    public function testIpReturnsLeftmostTokenTrimmed(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '  1.2.3.4  ,  5.6.7.8']);

        self::assertSame('1.2.3.4', $request->ip(['127.0.0.0/8'], '127.0.0.1'));
    }

    public function testIpFallsBackToRemoteAddrWhenForwardedHeaderIsEmpty(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '']);

        self::assertSame('127.0.0.1', $request->ip(['127.0.0.0/8'], '127.0.0.1'));
    }

    public function testIpFallsBackToRemoteAddrWhenForwardedHeaderIsWhitespace(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '   ']);

        self::assertSame('127.0.0.1', $request->ip(['127.0.0.0/8'], '127.0.0.1'));
    }

    public function testIpSupportsIpv6TrustedProxies(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '2001:db8::1']);

        self::assertSame('2001:db8::1', $request->ip(['::1/128'], '::1'));
    }

    public function testIpSupportsPrivateCidrTrustedProxies(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => '203.0.113.99']);

        self::assertSame('203.0.113.99', $request->ip(Request::TRUST_PRIVATE, '10.1.2.3'));
        self::assertSame('203.0.113.99', $request->ip(Request::TRUST_PRIVATE, '172.16.0.5'));
        self::assertSame('203.0.113.99', $request->ip(Request::TRUST_PRIVATE, '192.168.42.7'));
    }

    public function testIpFromGlobalsReadsRemoteAddrFromServerWhenNoHeaderAndNoOverride(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('203.0.113.5', $request->ip(Request::TRUST_PRIVATE));
    }

    public function testIpFromGlobalsIgnoresForwardedHeaderWithoutTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame(
            '198.51.100.7',
            $request->ip(),
            'Without a trusted-proxies list, X-Forwarded-For must never affect the result',
        );
    }

    public function testIpFromGlobalsRespectsTrustedProxyListForLeftmostSelection(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = Request::fromGlobals();

        self::assertSame('1.2.3.4', $request->ip(['10.0.0.0/8']));
    }

    public function testIpWithRemoteAddrOverrideAndNoHeaderReturnsOverride(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('203.0.113.99', $request->ip(null, '203.0.113.99'));
    }

    public function testIpWithEmptyRemoteAddrOverrideReturnsNull(): void
    {
        $request = new Request('GET', '/');

        self::assertNull($request->ip(null, ''));
        self::assertNull($request->ip(['10.0.0.0/8'], ''));
    }

    public function testIpWithForwardedHeaderFromTrustedProxyAndEmptyHeaderTokenFallsBackToRemoteAddr(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-for' => ', 5.6.7.8']);

        self::assertSame(
            '127.0.0.1',
            $request->ip(['127.0.0.0/8'], '127.0.0.1'),
            'When the leftmost X-Forwarded-For token is empty/whitespace, the result must be the immediate REMOTE_ADDR, not the second token',
        );
    }

    /**
     * @return list<array{0: list<string>, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function ipMatrix(): array
    {
        return [
            [[], '203.0.113.1', '', '203.0.113.1', 'no forwarded, no trust'],
            [[], '203.0.113.1', '1.2.3.4', '203.0.113.1', 'forwarded, no trust'],
            [['127.0.0.0/8'], '8.8.8.8', '1.2.3.4', '8.8.8.8', 'forwarded, empty trust (untrusted REMOTE_ADDR)'],
            [['127.0.0.0/8'], '127.0.0.1', '1.2.3.4', '1.2.3.4', 'forwarded, trusted proxy, single token'],
            [['127.0.0.0/8'], '127.0.0.1', '1.2.3.4, 5.6.7.8', '1.2.3.4', 'forwarded, trusted proxy, multi token'],
            [['10.0.0.0/8'], '10.0.0.5', '1.2.3.4', '1.2.3.4', 'forwarded, trusted proxy private cidr'],
            [['10.0.0.0/8'], '8.8.8.8', '1.2.3.4', '8.8.8.8', 'forwarded, trusted proxy private cidr miss'],
        ];
    }

    /**
     * @param list<string> $trustedProxies
     */
    #[DataProvider('ipMatrix')]
    public function testIpMatrix(
        array $trustedProxies,
        string $remoteAddr,
        string $forwardedFor,
        string $expected,
        string $label,
    ): void {
        $request = $forwardedFor === ''
            ? new Request('GET', '/')
            : new Request('GET', '/', '', ['x-forwarded-for' => $forwardedFor]);

        self::assertSame(
            $expected,
            $request->ip($trustedProxies, $remoteAddr),
            "Case '{$label}' failed: remote={$remoteAddr}, forwarded='{$forwardedFor}'",
        );
    }
}
