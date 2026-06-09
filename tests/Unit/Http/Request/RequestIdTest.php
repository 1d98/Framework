<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestIdTest extends TestCase
{
    public function testIdIs16HexCharsByDefault(): void
    {
        $request = new Request('GET', '/');

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
    }

    public function testIdIsUniqueAcrossRequests(): void
    {
        $a = new Request('GET', '/');
        $b = new Request('GET', '/');

        self::assertNotSame($a->id, $b->id);
    }

    public function testHonorsXRequestIdHeader(): void
    {
        $request = new Request('GET', '/', '', ['x-request-id' => 'caller-supplied-123']);

        self::assertSame('caller-supplied-123', $request->id);
    }

    public function testHonorsXCorrelationIdHeaderWhenXRequestIdMissing(): void
    {
        $request = new Request('GET', '/', '', ['x-correlation-id' => 'corr-xyz']);

        self::assertSame('corr-xyz', $request->id);
    }

    public function testXRequestIdTakesPrecedenceOverXCorrelationId(): void
    {
        $request = new Request('GET', '/', '', [
            'x-request-id' => 'rid',
            'x-correlation-id' => 'cid',
        ]);

        self::assertSame('rid', $request->id);
    }

    public function testMalformedIdWithNewlinesFallsBackToGenerated(): void
    {
        $malicious = "abc\nINFO fake log line\n[2026-01-01 00:00:00]";
        $request = new Request('GET', '/', '', ['x-request-id' => $malicious]);

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
        self::assertNotSame($malicious, $request->id);
        self::assertStringNotContainsString("\n", $request->id);
    }

    public function testOversizedIdFallsBackToGenerated(): void
    {
        $oversized = str_repeat('a', 200);
        $request = new Request('GET', '/', '', ['x-request-id' => $oversized]);

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
    }

    public function testIdWithDisallowedCharsFallsBackToGenerated(): void
    {
        $request = new Request('GET', '/', '', ['x-request-id' => 'has space and !@#']);

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
    }

    public function testEmptyHeaderFallsBackToGenerated(): void
    {
        $request = new Request('GET', '/', '', ['x-request-id' => '']);

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
    }

    public function testConstructorAcceptsExplicitId(): void
    {
        $request = new Request('GET', '/', '', [], '', null, null, null, [], null, null, null, 'fixed-id-001');

        self::assertSame('fixed-id-001', $request->id);
    }

    public function testConstructorExplicitIdOverridesHeader(): void
    {
        $request = new Request('GET', '/', '', ['x-request-id' => 'from-header'], '', null, null, null, [], null, null, null, 'explicit-wins');

        self::assertSame('explicit-wins', $request->id);
    }

    public function testWithIdReturnsNewInstanceWithGivenId(): void
    {
        $original = new Request('GET', '/');
        $modified = $original->withId('cloned-id-007');

        self::assertNotSame($original, $modified);
        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $original->id);
        self::assertSame('cloned-id-007', $modified->id);
    }

    public function testWithIdPreservesAllOtherProperties(): void
    {
        $original = new Request('POST', '/submit', 'a=1', ['x-foo' => 'bar'], 'body', ['k' => 'v'], ['f' => 'x']);
        $modified = $original->withId('new-id');

        self::assertSame($original->method, $modified->method);
        self::assertSame($original->path, $modified->path);
        self::assertSame($original->queryString, $modified->queryString);
        self::assertSame($original->headers, $modified->headers);
        self::assertSame($original->body, $modified->body);
        self::assertSame($original->json(), $modified->json());
        self::assertSame($original->form(), $modified->form());
    }

    public function testWithJsonPreservesId(): void
    {
        $original = new Request('POST', '/api', '', [], '', null, null, null, [], null, null, null, 'stable');
        $modified = $original->withJson(['x' => 1]);

        self::assertSame('stable', $modified->id);
    }

    public function testWithFormPreservesId(): void
    {
        $original = new Request('POST', '/api', '', [], '', null, null, null, [], null, null, null, 'stable');
        $modified = $original->withForm(['x' => '1']);

        self::assertSame('stable', $modified->id);
    }

    public function testWithFilesPreservesId(): void
    {
        $original = new Request('POST', '/upload', '', [], '', null, null, null, [], null, null, null, 'stable');
        $modified = $original->withFiles([]);

        self::assertSame('stable', $modified->id);
    }

    public function testWithCsrfTokenPreservesId(): void
    {
        $original = new Request('POST', '/api', '', [], '', null, null, null, [], null, null, null, 'stable');
        $modified = $original->withCsrfToken('tok');

        self::assertSame('stable', $modified->id);
    }

    public function testFromGlobalsHonorsXRequestIdServerVar(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'server-supplied-id-42';

        $request = Request::fromGlobals();

        self::assertSame('server-supplied-id-42', $request->id);

        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function testFromGlobalsGeneratesIdWhenHeaderMissing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_X_CORRELATION_ID']);

        $request = Request::fromGlobals();

        self::assertMatchesRegularExpression('/\A[A-Fa-f0-9]{16}\z/', $request->id);
    }
}
