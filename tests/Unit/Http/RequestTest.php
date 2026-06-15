<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testConstructorExposesProperties(): void
    {
        $request = new Request('POST', '/users', 'a=1&b=2', ['content-type' => 'application/json'], '{"x":1}');

        self::assertSame('POST', $request->method);
        self::assertSame('/users', $request->path);
        self::assertSame('a=1&b=2', $request->queryString);
        self::assertSame(['content-type' => 'application/json'], $request->headers);
        self::assertSame('{"x":1}', $request->body);
    }

    public function testDefaults(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('', $request->queryString);
        self::assertSame([], $request->headers);
        self::assertSame('', $request->body);
    }

    public function testQueryParsesQueryString(): void
    {
        $request = new Request('GET', '/search', 'q=hello&page=2');

        self::assertSame(['q' => 'hello', 'page' => '2'], $request->query());
    }

    public function testQueryReturnsEmptyArrayForEmptyQueryString(): void
    {
        $request = new Request('GET', '/');

        self::assertSame([], $request->query());
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', '', ['content-type' => 'text/html']);

        self::assertSame('text/html', $request->header('Content-Type'));
        self::assertSame('text/html', $request->header('content-type'));
        self::assertSame('text/html', $request->header('CONTENT-TYPE'));
    }

    public function testHeaderReturnsNullForMissing(): void
    {
        $request = new Request('GET', '/');

        self::assertNull($request->header('X-Nonexistent'));
    }

    public function testFilesDefaultsToNull(): void
    {
        $request = new Request('POST', '/upload');

        self::assertNull($request->files());
    }

    public function testWithFilesReturnsNewInstance(): void
    {
        $original = new Request('POST', '/upload');
        $modified = $original->withFiles(['avatar' => new \Framework\Http\UploadedFile('a.png', 'image/png', sys_get_temp_dir() . '/fake_x', 0, 10)]);

        self::assertNotSame($original, $modified);
        self::assertNull($original->files());
        $files = $modified->files();
        self::assertNotNull($files);
        self::assertCount(1, $files);
        self::assertInstanceOf(\Framework\Http\UploadedFile::class, $files['avatar']);
    }

    public function testWithFilesPreservesOtherProperties(): void
    {
        $original = new Request('POST', '/upload', 'a=1', ['x-foo' => 'bar'], 'body', ['k' => 'v'], ['f' => 'x']);
        $modified = $original->withFiles(['doc' => new \Framework\Http\UploadedFile('d.txt', 'text/plain', sys_get_temp_dir() . '/fake_y', 0, 5)]);

        self::assertSame('POST', $modified->method);
        self::assertSame('/upload', $modified->path);
        self::assertSame('a=1', $modified->queryString);
        self::assertSame(['x-foo' => 'bar'], $modified->headers);
        self::assertSame('body', $modified->body);
        self::assertSame(['k' => 'v'], $modified->json());
        self::assertSame(['f' => 'x'], $modified->form());
        $files = $modified->files();
        self::assertNotNull($files);
        self::assertCount(1, $files);
    }

    public function testIsSecureReturnsFalseWhenHeaderMissing(): void
    {
        $request = new Request('GET', '/');

        self::assertFalse($request->isSecure());
    }

    public function testIsSecureIsStrictByDefaultAndIgnoresForwardedProto(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isSecure());
    }

    public function testIsSecureReturnsTrueForHttpsFromTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureAcceptsUppercaseHeaderValueFromTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'HTTPS']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testIsSecureRejectsCommaListFromTrustedProxyToPreventChainSpoofing(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https, http']);

        self::assertFalse(
            $request->isSecure(['127.0.0.1'], '127.0.0.1'),
            'Multi-value X-Forwarded-Proto is untrusted; the leftmost token must not flip isSecure() to true',
        );
    }

    public function testIsSecureReturnsFalseForHttp(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'http']);

        self::assertFalse($request->isSecure());
    }

    public function testIsSecureTrimsWhitespaceFromTrustedProxy(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => '  https  ']);

        self::assertTrue($request->isSecure(['127.0.0.1'], '127.0.0.1'));
    }

    public function testHostReturnsHostHeader(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com:8080']);

        self::assertSame('example.com:8080', $request->host());
    }

    public function testHostFallsBackToLocalhostWhenMissing(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('localhost', $request->host());
    }

    public function testSchemeReturnsHttpsWhenSecure(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertSame('https', $request->scheme(Request::TRUST_LOOPBACK, '127.0.0.1'));
    }

    public function testSchemeReturnsHttpByDefault(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('http', $request->scheme());
    }

    public function testCookiesDefaultToEmptyArray(): void
    {
        $request = new Request('GET', '/');

        self::assertSame([], $request->cookies());
        self::assertNull($request->cookie('session'));
    }

    public function testCookieLookupReturnsValue(): void
    {
        $request = new Request('GET', '/', '', [], '', null, null, null, ['session' => 'abc123']);

        self::assertSame('abc123', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
        self::assertSame(['session' => 'abc123'], $request->cookies());
    }

    public function testCsrfTokenDefaultIsNull(): void
    {
        $request = new Request('GET', '/');

        self::assertNull($request->csrfToken());
    }

    public function testWithCsrfTokenReturnsNewInstance(): void
    {
        $original = new Request('GET', '/');
        $modified = $original->withCsrfToken('tok123');

        self::assertNotSame($original, $modified);
        self::assertNull($original->csrfToken());
        self::assertSame('tok123', $modified->csrfToken());
    }

    public function testWithCsrfTokenPreservesOtherProperties(): void
    {
        $original = new Request('POST', '/submit', 'a=1', ['x-foo' => 'bar'], 'body', ['k' => 'v'], ['f' => 'x'], null, ['session' => 'abc']);
        $modified = $original->withCsrfToken('tok');

        self::assertSame('POST', $modified->method);
        self::assertSame('/submit', $modified->path);
        self::assertSame('a=1', $modified->queryString);
        self::assertSame(['x-foo' => 'bar'], $modified->headers);
        self::assertSame('body', $modified->body);
        self::assertSame(['k' => 'v'], $modified->json());
        self::assertSame(['f' => 'x'], $modified->form());
        self::assertSame(['session' => 'abc'], $modified->cookies());
    }

    public function testFromGlobalsParsesCookieHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_COOKIE'] = 'session=abc123; theme=dark; csrf=tok';

        $request = Request::fromGlobals();

        self::assertSame('abc123', $request->cookie('session'));
        self::assertSame('dark', $request->cookie('theme'));
        self::assertSame('tok', $request->cookie('csrf'));

        unset($_SERVER['HTTP_COOKIE']);
    }

    public function testFromGlobalsReturnsEmptyCookiesWhenNoHeader(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_COOKIE']);

        $request = Request::fromGlobals();

        self::assertSame([], $request->cookies());
    }

    public function testIsHttpsAgreesWithIsSecureByDefault(): void
    {
        $request = new Request('GET', '/');

        self::assertSame($request->isSecure(), $request->isHttps());
    }

    public function testIsHttpsDoesNotTrustForwardedProtoWithoutProxyList(): void
    {
        $request = new Request('GET', '/', '', ['x-forwarded-proto' => 'https']);

        self::assertFalse($request->isHttps());
        self::assertFalse($request->isSecure());
    }

    public function testIsHttpsTrustsForwardedProtoFromTrustedProxy(): void
    {
        $request = new Request(
            'GET',
            '/',
            '',
            ['x-forwarded-proto' => 'https'],
            '',
            null,
            null,
            null,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            new \Framework\Http\Request\RequestHost(
                host: 'example.com',
                isSecure: false,
                remoteAddr: '10.0.0.1',
                trustedProxies: ['10.0.0.0/8'],
            ),
        );

        self::assertTrue($request->isHttps());
        self::assertTrue($request->isSecure());
    }

    public function testIsHttpsRejectsMultiValueForwardedProto(): void
    {
        $request = new Request(
            'GET',
            '/',
            '',
            ['x-forwarded-proto' => 'https, http'],
            '',
            null,
            null,
            null,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            new \Framework\Http\Request\RequestHost(
                host: 'example.com',
                isSecure: false,
                remoteAddr: '10.0.0.1',
                trustedProxies: ['10.0.0.0/8'],
            ),
        );

        self::assertFalse($request->isHttps());
        self::assertFalse($request->isSecure());
    }
}
