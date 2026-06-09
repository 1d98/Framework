<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Http\Request\RequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestFactory::class)]
#[CoversClass(Request::class)]
final class RequestFactoryTrustedProxiesTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $serverBackup = [];

    /** @var array<int|string, mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
    }

    private function primeSapiForTrustedProxyCase(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTPS']);
    }

    public function testFactoryAppliesTrustedProxiesToIsSecureWhenHeaderSaysHttps(): void
    {
        $this->primeSapiForTrustedProxyCase();
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $request = RequestFactory::fromGlobals(trustedProxies: ['127.0.0.1']);

        self::assertTrue($request->isSecure());
    }

    public function testFactoryWithoutTrustedProxiesIgnoresForwardedProtoHeader(): void
    {
        $this->primeSapiForTrustedProxyCase();
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $request = RequestFactory::fromGlobals(trustedProxies: null);

        self::assertFalse($request->isSecure());
    }

    public function testFactoryWithEmptyTrustedProxiesListIgnoresForwardedProtoHeader(): void
    {
        $this->primeSapiForTrustedProxyCase();
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $request = RequestFactory::fromGlobals(trustedProxies: []);

        self::assertFalse($request->isSecure());
    }

    public function testFactoryTrustedProxiesRespectsRemoteAddrNotInList(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        unset($_SERVER['HTTPS']);

        $request = RequestFactory::fromGlobals(trustedProxies: ['127.0.0.0/8']);

        self::assertFalse($request->isSecure());
    }

    public function testFactoryTrustedProxiesAppliesToIpResolution(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
        unset($_SERVER['HTTPS']);

        $request = RequestFactory::fromGlobals(trustedProxies: ['10.0.0.0/8']);

        self::assertSame('203.0.113.99', $request->ip());
    }

    public function testFactoryWithoutTrustedProxiesIgnoresXForwardedFor(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
        unset($_SERVER['HTTPS']);

        $request = RequestFactory::fromGlobals(trustedProxies: null);

        self::assertSame('10.0.0.5', $request->ip());
    }

    public function testFactoryWithTrustedProxiesPreservesRequestShape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload?id=42';
        $_SERVER['HTTP_COOKIE'] = 'a=1';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'corr-7';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTPS']);

        $request = RequestFactory::fromGlobals(
            maxBodyBytes: 2048,
            id: 'explicit',
            attributes: ['k' => 'v'],
            trustedProxies: ['127.0.0.1'],
        );

        self::assertSame('POST', $request->method);
        self::assertSame('/upload', $request->path);
        self::assertSame('id=42', $request->queryString);
        self::assertSame('1', $request->cookie('a'));
        self::assertSame('explicit', $request->id);
        self::assertSame(['k' => 'v'], $request->attributes());
        self::assertSame(2048, $request->maxBodyBytes());
    }

    public function testWithTrustedProxiesMutatorReturnsNewInstanceWithStoredList(): void
    {
        $base = new Request('GET', '/');
        $withList = $base->withTrustedProxies(['127.0.0.1']);

        self::assertNotSame($base, $withList);
    }

    public function testWithTrustedProxiesMutatorDoesNotMutateOriginal(): void
    {
        $base = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($base->isSecure([], '127.0.0.1'));

        $base->withTrustedProxies(['127.0.0.1']);

        self::assertFalse(
            $base->isSecure([], '127.0.0.1'),
            'withTrustedProxies() must return a new instance and not mutate the original',
        );
    }

    public function testWithTrustedProxiesMutatorEnablesIsSecureWithStoredList(): void
    {
        $base = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        $wired = $base->withTrustedProxies(['127.0.0.1']);

        self::assertTrue($wired->isSecure(remoteAddr: '127.0.0.1'));
    }

    public function testWithTrustedProxiesMutatorEnablesIpWithStoredList(): void
    {
        $base = new Request('GET', '/', '', ['x-forwarded-for' => '203.0.113.42']);

        $wired = $base->withTrustedProxies(['10.0.0.0/8']);

        self::assertSame('203.0.113.42', $wired->ip(remoteAddr: '10.0.0.5'));
    }

    public function testWithJsonPropagatesTrustedProxies(): void
    {
        $base = (new Request('GET', '/'))->withTrustedProxies(['127.0.0.1']);
        $headers = $base->headers;
        $headers['x-forwarded-proto'] = 'https';

        $rebuilt = (new Request('GET', '/', '', $headers))->withTrustedProxies(['127.0.0.1'])->withJson(['k' => 'v']);

        self::assertSame(['k' => 'v'], $rebuilt->json());
        self::assertTrue($rebuilt->isSecure(remoteAddr: '127.0.0.1'));
    }

    public function testWithAttributePropagatesTrustedProxies(): void
    {
        $headers = ['x-forwarded-proto' => 'https'];
        $base = (new Request('GET', '/', '', $headers))->withTrustedProxies(['127.0.0.1']);
        $rebuilt = $base->withAttribute('route', 'home');

        self::assertTrue($rebuilt->hasAttribute('route'));
        self::assertTrue($rebuilt->isSecure(remoteAddr: '127.0.0.1'));
    }

    public function testDirectConstructionWithoutTrustedProxiesStaysStrict(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse(
            $request->isSecure(remoteAddr: '127.0.0.1'),
            'Direct construction must keep the strict default; the trust list is opt-in via the factory or withTrustedProxies()',
        );
    }

    public function testIsSecurePerCallArgWinsOverStoredList(): void
    {
        $headers = ['x-forwarded-proto' => 'https'];
        $request = (new Request('GET', '/', '', $headers))->withTrustedProxies(['10.0.0.0/8']);

        self::assertTrue(
            $request->isSecure(['127.0.0.1'], '127.0.0.1'),
            'A non-null $trustedProxies argument at the call site must take precedence over the stored list',
        );
        self::assertTrue(
            $request->isSecure(remoteAddr: '10.0.0.5'),
            'The stored list is honored when the call site passes no explicit list',
        );
        self::assertFalse(
            $request->isSecure(remoteAddr: '198.51.100.7'),
            'The stored list is consulted; a remote address outside it does not flip isSecure()',
        );
    }
}
