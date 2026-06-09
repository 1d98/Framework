<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultipartBodyParser::class)]
final class MultipartBodyParserCleanupTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mwb_cleanup_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/upl_*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testCleansUpTempFilesWhenCapExceededMidParse(): void
    {
        $boundary = 'CAP-MID';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"a\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('A', 100) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"b\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('B', 100) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"c\"; filename=\"c.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('C', 100) . "\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $middleware = new MultipartBodyParser($this->tmpDir, maxBodyBytes: 250);

        $before = $this->countUploadFiles();

        try {
            $middleware->process($request, static fn(Request $r): Response => Response::json([]));
            self::fail('Expected PayloadTooLargeHttpException');
        } catch (PayloadTooLargeHttpException) {
            $after = $this->countUploadFiles();
            self::assertSame(
                $before,
                $after,
                "Temp files leaked on cap-exceeded failure (before={$before}, after={$after})",
            );
        }
    }

    public function testCleansUpTempFilesOnMalformedMultipart(): void
    {
        $boundary = 'BAD-BOUND';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"a\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\nfirst part payload\r\n"
            . "--WRONG-BOUNDARY\r\n"
            . "Content-Disposition: form-data; name=\"b\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\nsecond part payload\r\n"
            . "--WRONG-BOUNDARY--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $middleware = new MultipartBodyParser($this->tmpDir);

        $before = $this->countUploadFiles();

        try {
            $middleware->process($request, static fn(Request $r): Response => Response::json([]));
            self::fail('Expected BadRequestHttpException');
        } catch (BadRequestHttpException) {
            $after = $this->countUploadFiles();
            self::assertSame(
                $before,
                $after,
                "Temp files leaked on malformed-multipart failure (before={$before}, after={$after})",
            );
        }
    }

    public function testPreservesTempFilesOnSuccessfulParse(): void
    {
        $boundary = 'OK-BOUND';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"a\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\npayload a\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"b\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\npayload b\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $middleware = new MultipartBodyParser($this->tmpDir);
        $before = $this->countUploadFiles();

        $collectedPaths = [];
        $middleware->process($request, static function (Request $r) use (&$collectedPaths): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['a'] ?? null);
            self::assertInstanceOf(UploadedFile::class, $files['b'] ?? null);
            $collectedPaths[] = $files['a']->tmpPath;
            $collectedPaths[] = $files['b']->tmpPath;
            return Response::json([]);
        });

        self::assertCount(2, $collectedPaths);
        foreach ($collectedPaths as $path) {
            self::assertFileExists($path, "Successful parse must preserve temp file: {$path}");
        }
        self::assertGreaterThan(
            $before,
            $this->countUploadFiles(),
            'Successful parse must leave the user\'s temp files in place',
        );

        foreach ($collectedPaths as $path) {
            @unlink($path);
        }
    }

    public function testCleansUpAllTempFilesWhenExceptionFiresAfterSomePartsWritten(): void
    {
        $boundary = 'LEAK-CHECK';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"f1\"; filename=\"f1.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('X', 200) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"f2\"; filename=\"f2.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('Y', 200) . "\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $middleware = new MultipartBodyParser($this->tmpDir, maxBodyBytes: 300);

        try {
            $middleware->process($request, static fn(Request $r): Response => Response::json([]));
            self::fail('Expected PayloadTooLargeHttpException');
        } catch (PayloadTooLargeHttpException) {
            $leaks = glob($this->tmpDir . '/upl_*') ?: [];
            self::assertSame(
                [],
                $leaks,
                'No temp files should remain after cap-exceeded failure: ' . implode(', ', $leaks),
            );
        }
    }

    private function countUploadFiles(): int
    {
        $files = glob($this->tmpDir . '/upl_*') ?: [];
        return count(array_filter($files, 'is_file'));
    }
}
