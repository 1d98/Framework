<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Request\Request;
use Framework\Http\Request\RequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestFactory::class)]
#[CoversClass(Request::class)]
final class RequestFactoryCapTest extends TestCase
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

    public function testFactoryCapIsAuthoritativeWhenDeclaredBodyExceedsIt(): void
    {
        $this->populateServer('POST', '/upload', '2048');

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body too large');

        RequestFactory::fromGlobals(maxBodyBytes: 1024);
    }

    public function testFactoryAcceptsBodyUnderCustomCap(): void
    {
        $cap = 10 * 1024 * 1024;
        $this->populateServer('POST', '/upload', (string) (5 * 1024 * 1024));

        $request = RequestFactory::fromGlobals(maxBodyBytes: $cap);

        self::assertSame($cap, $request->maxBodyBytes());
    }

    public function testDirectConstructionThrowsWhenBodyExceedsDefaultCap(): void
    {
        $oversize = str_repeat('a', Request::MAX_BODY_BYTES + 1);

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body exceeds cap of ' . Request::MAX_BODY_BYTES . ' bytes');

        new Request(method: 'POST', path: '/x', body: $oversize);
    }

    public function testDirectConstructionAcceptsOversizeBodyWhenCapIsExplicitlyInfinite(): void
    {
        $oversize = str_repeat('b', Request::MAX_BODY_BYTES + 1);

        $request = new Request(
            method: 'POST',
            path: '/x',
            body: $oversize,
            maxBodyBytes: PHP_INT_MAX,
        );

        self::assertSame(strlen($oversize), strlen($request->body));
    }

    private function populateServer(string $method, string $uri, ?string $contentLength = null): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        unset($_SERVER['HTTP_COOKIE']);

        if ($contentLength === null) {
            unset($_SERVER['CONTENT_LENGTH']);
        } else {
            $_SERVER['CONTENT_LENGTH'] = $contentLength;
        }
    }
}
