<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Http\Request\RequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestFactory::class)]
#[CoversClass(Request::class)]
final class RequestFactoryTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    public function testFactoryBuildsRequestFromPopulatedSuperglobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/users?q=1';
        $_SERVER['HTTP_COOKIE'] = 'session=abc';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'corr-123';

        $request = RequestFactory::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame('/users', $request->path);
        self::assertSame('q=1', $request->queryString);
        self::assertSame('abc', $request->cookie('session'));
        self::assertSame('https', $request->header('X-Forwarded-Proto'));
        self::assertSame('corr-123', $request->id);
    }

    public function testFactoryFallsBackToSafeDefaultsWhenSuperglobalsAreEmpty(): void
    {
        $_SERVER = [];

        $request = RequestFactory::fromGlobals();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
        self::assertSame('', $request->queryString);
        self::assertSame([], $request->headers);
        self::assertSame('', $request->body);
        self::assertSame([], $request->cookies());
    }

    public function testRequestFromGlobalsMatchesFactoryOutputShape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload?id=42';
        $_SERVER['HTTP_COOKIE'] = 'a=1; b=2';
        $_SERVER['HTTP_X_CORRELATION_ID'] = 'corr-9';

        $viaRequest = Request::fromGlobals(2048);
        $viaFactory = RequestFactory::fromGlobals(maxBodyBytes: 2048);

        self::assertSame($viaFactory->method, $viaRequest->method);
        self::assertSame($viaFactory->path, $viaRequest->path);
        self::assertSame($viaFactory->queryString, $viaRequest->queryString);
        self::assertSame($viaFactory->headers, $viaRequest->headers);
        self::assertSame($viaFactory->body, $viaRequest->body);
        self::assertSame($viaFactory->cookies(), $viaRequest->cookies());
        self::assertSame($viaFactory->id, $viaRequest->id);
        self::assertSame($viaFactory->maxBodyBytes(), $viaRequest->maxBodyBytes());
    }

    public function testFactoryAcceptsExplicitIdAndAttributes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $request = RequestFactory::fromGlobals(
            id: 'explicit-id',
            attributes: ['k' => 'v'],
        );

        self::assertSame('explicit-id', $request->id);
        self::assertSame(['k' => 'v'], $request->attributes());
    }
}
