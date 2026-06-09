<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
#[CoversClass(MultipartBodyParser::class)]
final class ChunkedEncodingTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $serverBackup = [];

    private string $projectTmpDir = '';

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->projectTmpDir = realpath(__DIR__ . '/../../../../var/tmp') ?: (__DIR__ . '/../../../../var/tmp');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $this->cleanupTmpUploads();
    }

    public function testRejectsChunkedEncodingOnNonMultipartRequest(): void
    {
        $this->populateServer(
            method: 'POST',
            uri: '/api/echo',
            contentType: 'application/json',
            contentLength: null,
            transferEncoding: 'chunked',
        );

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Chunked Transfer-Encoding is not supported for non-multipart requests');

        Request::fromGlobals();
    }

    public function testRejectsChunkedEncodingOnPlainTextRequest(): void
    {
        $this->populateServer(
            method: 'POST',
            uri: '/submit',
            contentType: 'text/plain',
            contentLength: null,
            transferEncoding: 'chunked',
        );

        $this->expectException(PayloadTooLargeHttpException::class);

        Request::fromGlobals();
    }

    public function testRejectsChunkedEncodingWhenListedAlongsideOtherTokens(): void
    {
        $this->populateServer(
            method: 'POST',
            uri: '/api/echo',
            contentType: 'application/json',
            contentLength: null,
            transferEncoding: 'gzip, chunked',
        );

        $this->expectException(PayloadTooLargeHttpException::class);

        Request::fromGlobals();
    }

    public function testAllowsNonChunkedTransferEncoding(): void
    {
        $this->populateServer(
            method: 'POST',
            uri: '/api/echo',
            contentType: 'application/json',
            contentLength: '5',
            transferEncoding: 'gzip',
        );

        $request = Request::fromGlobals();

        self::assertSame('POST', $request->method);
    }

    public function testPreservesExistingBehaviorWhenContentLengthPresentAndNoChunked(): void
    {
        $this->populateServer(
            method: 'POST',
            uri: '/upload',
            contentType: 'application/json',
            contentLength: '1000',
            transferEncoding: null,
        );

        $request = Request::fromGlobals(2048);

        self::assertSame('', $request->body);
    }

    public function testAllowsChunkedEncodingForMultipartRequestAtGlobalLevel(): void
    {
        $boundary = 'CHUNK-MP';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n\r\nhello\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"b\"\r\n\r\nworld\r\n"
            . "--{$boundary}--\r\n";

        $request = $this->requestFromBody(
            'POST',
            '/upload',
            'multipart/form-data; boundary=' . $boundary,
            $body,
            transferEncoding: 'chunked',
        );

        $middleware = new MultipartBodyParser(null, maxBodyBytes: 10 * 1024 * 1024);
        $response = $middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            self::assertSame('hello', $form['a']);
            self::assertSame('world', $form['b']);
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
    }

    public function testMultipartChunkedStillFiresPerPartCapWhenCumulatingBeyondCap(): void
    {
        $boundary = 'CHUNK-CAP';
        $sixMb = str_repeat('A', 6 * 1024 * 1024);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"first\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$sixMb}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"second\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$sixMb}\r\n"
            . "--{$boundary}--\r\n";

        $request = $this->requestFromBody(
            'POST',
            '/upload',
            'multipart/form-data; boundary=' . $boundary,
            $body,
            transferEncoding: 'chunked',
            maxBodyBytes: PHP_INT_MAX,
        );

        $middleware = new MultipartBodyParser(null, maxBodyBytes: 10 * 1024 * 1024);

        $this->expectException(PayloadTooLargeHttpException::class);

        $middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testRequestWithFilesAlreadySetIsNotRecappedByChunkedRule(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => 'application/json',
                'transfer-encoding' => 'chunked',
            ],
            '',
        )->withFiles(['preset' => new UploadedFile('a', 'text/plain', sys_get_temp_dir() . '/fake', 0, 1)]);

        $files = $request->files();
        self::assertIsArray($files);
        self::assertArrayHasKey('preset', $files);
        self::assertInstanceOf(UploadedFile::class, $files['preset']);
        self::assertSame('a', $files['preset']->name);
    }

    private function populateServer(
        string $method,
        string $uri,
        string $contentType,
        ?string $contentLength,
        ?string $transferEncoding,
    ): void {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['CONTENT_TYPE'] = $contentType;
        unset($_SERVER['HTTP_COOKIE']);

        if ($contentLength === null) {
            unset($_SERVER['CONTENT_LENGTH']);
        } else {
            $_SERVER['CONTENT_LENGTH'] = $contentLength;
        }

        if ($transferEncoding === null) {
            unset($_SERVER['HTTP_TRANSFER_ENCODING']);
        } else {
            $_SERVER['HTTP_TRANSFER_ENCODING'] = $transferEncoding;
        }
    }

    private function requestFromBody(
        string $method,
        string $uri,
        string $contentType,
        string $body,
        ?string $transferEncoding = null,
        ?int $maxBodyBytes = null,
    ): Request {
        $headers = ['content-type' => $contentType];
        if ($transferEncoding !== null) {
            $headers['transfer-encoding'] = $transferEncoding;
        }

        return new Request(
            $method,
            $uri,
            '',
            $headers,
            $body,
            null,
            null,
            null,
            [],
            null,
            null,
            $maxBodyBytes,
        );
    }

    private function cleanupTmpUploads(): void
    {
        if ($this->projectTmpDir === '' || !is_dir($this->projectTmpDir)) {
            return;
        }
        foreach (glob($this->projectTmpDir . '/upl_*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
