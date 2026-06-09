<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Request\Request;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestBodySizeCapTest extends TestCase
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

    public function testMaxBodyBytesConstantIs10MiB(): void
    {
        self::assertSame(10 * 1024 * 1024, Request::MAX_BODY_BYTES);
    }

    public function testConstructorAcceptsCustomMaxBodyBytes(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/upload',
            body: 'small body',
            maxBodyBytes: 1024,
        );

        self::assertSame(1024, $request->maxBodyBytes());
    }

    public function testMaxBodyBytesGetterFallsBackToConstantWhenNull(): void
    {
        $request = new Request('GET', '/');

        self::assertSame(Request::MAX_BODY_BYTES, $request->maxBodyBytes());
    }

    public function testWithMethodsPreserveMaxBodyBytes(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/x',
            body: 'b',
            maxBodyBytes: 512,
        );

        $with = $request
            ->withJson(['k' => 'v'])
            ->withForm(['f' => 'x'])
            ->withFiles([])
            ->withCsrfToken('tok')
            ->withValidator(new Validator(new RuleRegistry()));

        self::assertSame(512, $with->maxBodyBytes());
    }

    public function testDirectConstructionThrows413WhenBodyExceedsDefaultCap(): void
    {
        $oversize = str_repeat('a', Request::MAX_BODY_BYTES + 1);

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body exceeds cap of ' . Request::MAX_BODY_BYTES . ' bytes');

        new Request(method: 'POST', path: '/x', body: $oversize);
    }

    public function testDirectConstructionHonorsExplicitMaxBodyBytesOverride(): void
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

    public function testDirectConstructionHonorsSmallerExplicitMaxBodyBytes(): void
    {
        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body exceeds cap of 10 bytes');

        new Request(method: 'POST', path: '/x', body: '0123456789AB', maxBodyBytes: 10);
    }

    public function testDirectConstructionAcceptsBodyAtExactCap(): void
    {
        $request = new Request(method: 'POST', path: '/x', body: 'a');

        self::assertSame('a', $request->body);
    }

    public function testDirectConstructionSkipsCapCheckForEmptyBody(): void
    {
        $request = new Request(method: 'GET', path: '/x', body: '');

        self::assertSame('', $request->body);
    }

    public function testDirectConstructionCapCheckIsSkippedWhenExplicitOverrideIsInfinite(): void
    {
        $huge = str_repeat('c', 50 * 1024 * 1024);

        $request = new Request(
            method: 'POST',
            path: '/x',
            body: $huge,
            maxBodyBytes: PHP_INT_MAX,
        );

        self::assertSame(strlen($huge), strlen($request->body));
    }

    public function testFromGlobalsAcceptsBodyUnderDefaultCap(): void
    {
        $this->populateServer('POST', '/upload', '11');

        $request = Request::fromGlobals();

        self::assertSame('', $request->body);
    }

    public function testFromGlobalsThrows413WhenContentLengthExceedsDefaultCap(): void
    {
        $oversize = (string) (Request::MAX_BODY_BYTES + 1);
        $this->populateServer('POST', '/upload', $oversize);

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body too large');

        Request::fromGlobals();
    }

    public function testFromGlobalsThrows413With20MiBHeaderAnd10MiBCap(): void
    {
        $this->populateServer('POST', '/upload', '20971520');

        $this->expectException(PayloadTooLargeHttpException::class);

        Request::fromGlobals(10 * 1024 * 1024);
    }

    public function testFromGlobalsAcceptsBodyAtExactCap(): void
    {
        $this->populateServer('POST', '/upload', '1024');

        $request = Request::fromGlobals(1024);

        self::assertSame('', $request->body);
    }

    public function testFromGlobalsHonorsCustomCapSmallerThanDefault(): void
    {
        $this->populateServer('POST', '/upload', '2048');

        $this->expectException(PayloadTooLargeHttpException::class);

        Request::fromGlobals(1024);
    }

    public function testFromGlobalsIgnoresNonDigitContentLengthAndStreamsBody(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload';
        $_SERVER['CONTENT_LENGTH'] = 'not-a-number';
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        unset($_SERVER['HTTP_COOKIE']);

        $request = Request::fromGlobals(1024);

        self::assertSame('', $request->body);
    }

    public function testReadStreamWithCapReturnsContentWhenUnderCap(): void
    {
        $payload = 'small body content';
        $stream = $this->streamOf($payload);

        $body = Request::readStreamWithCap($stream, 1024);

        self::assertSame($payload, $body);
    }

    public function testReadStreamWithCapReturnsEmptyForEmptyStream(): void
    {
        $stream = $this->streamOf('');

        self::assertSame('', Request::readStreamWithCap($stream, 1024));
    }

    public function testReadStreamWithCapAcceptsBodyAtExactCap(): void
    {
        $payload = str_repeat('a', 1024);
        $stream = $this->streamOf($payload);

        self::assertSame($payload, Request::readStreamWithCap($stream, 1024));
    }

    public function testReadStreamWithCapThrowsWhenBodyExceedsCap(): void
    {
        $payload = str_repeat('x', 2048);
        $stream = $this->streamOf($payload);

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Request body too large');

        Request::readStreamWithCap($stream, 1024);
    }

    public function testReadStreamWithCapSimulatesChunkedOversizeBody(): void
    {
        $payload = str_repeat("\x00", 5 * 1024);
        $stream = $this->streamOf($payload);

        $this->expectException(PayloadTooLargeHttpException::class);

        Request::readStreamWithCap($stream, 1024);
    }

    public function testReadStreamWithCapDoesNotConsumePastCapPlusOne(): void
    {
        $huge = str_repeat('a', 10_000);
        $stream = $this->streamOf($huge);

        try {
            Request::readStreamWithCap($stream, 1024);
            self::fail('Expected PayloadTooLargeHttpException');
        } catch (PayloadTooLargeHttpException) {
            self::assertSame(10_000, strlen($huge));
        }
    }

    /**
     * @return resource
     */
    private function streamOf(string $payload)
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, $payload);
        rewind($stream);
        return $stream;
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
