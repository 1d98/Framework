<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MultipartBodyParser::class)]
final class MultipartBodyParserSanitizationTest extends TestCase
{
    private MultipartBodyParser $middleware;

    private string $projectTmpDir = '';

    protected function setUp(): void
    {
        $this->middleware = new MultipartBodyParser();
        $this->projectTmpDir = realpath(__DIR__ . '/../../../../var/tmp') ?: (__DIR__ . '/../../../../var/tmp');
    }

    protected function tearDown(): void
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

    public function testPostSuperglobalExceedingKeyCapIsRejected(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Too many form fields in $_POST (max 1000)');

        $this->withSuperglobals(
            $this->buildPost(5001),
            $this->dummyFileEntry(),
            fn(): Response => $this->middleware->process($request, static fn(Request $r): Response => Response::json([])),
        );
    }

    public function testPostSuperglobalWithOversizeValueIsRejected(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Form value exceeds 65536 bytes');

        $this->withSuperglobals(
            ['huge' => str_repeat('A', 100 * 1024)],
            $this->dummyFileEntry(),
            fn(): Response => $this->middleware->process($request, static fn(Request $r): Response => Response::json([])),
        );
    }

    public function testFilesSuperglobalExceedingKeyCapIsRejected(): void
    {
        $files = $this->dummyFileEntry();
        for ($i = 0; $i < 1000; $i++) {
            $files["f{$i}"] = [
                'name' => "f{$i}.bin",
                'type' => 'application/octet-stream',
                'tmp_name' => '/tmp/none',
                'error' => 0,
                'size' => 0,
            ];
        }

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Too many form fields in $_FILES (max 1000)');

        $this->withSuperglobals(
            [],
            $files,
            fn(): Response => $this->middleware->process($request, static fn(Request $r): Response => Response::json([])),
        );
    }

    public function testPostSuperglobalWithNestedOversizeValueIsRejected(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('Form value exceeds 65536 bytes');

        $this->withSuperglobals(
            ['nested' => ['inner' => str_repeat('B', 70 * 1024)]],
            $this->dummyFileEntry(),
            fn(): Response => $this->middleware->process($request, static fn(Request $r): Response => Response::json([])),
        );
    }

    public function testPostSuperglobalUnderKeyCapIsAccepted(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $response = $this->withSuperglobals(
            $this->buildPost(1000),
            $this->dummyFileEntry(),
            fn(): Response => $this->middleware->process($request, static function (Request $r): Response {
                $form = $r->form();
                self::assertIsArray($form);
                self::assertCount(1000, $form);
                self::assertSame('v', $form['k0']);
                self::assertSame('v', $form['k999']);
                return Response::json(['ok' => true]);
            }),
        );

        self::assertSame(200, $response->status);
    }

    public function testMultipartFilenameWithCrlfIsSanitized(): void
    {
        $boundary = 'CRLF';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"evil\r\nSet-Cookie: pwned=1\"\r\n"
            . "Content-Type: text/plain\r\n\r\npayload\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file']);
            $name = $files['file']->name;
            self::assertStringNotContainsString("\r", $name);
            self::assertStringNotContainsString("\n", $name);
            self::assertStringNotContainsString('Set-Cookie', $name);
            self::assertStringNotContainsString(': ', $name);
            return Response::json(['ok' => true]);
        });
    }

    public function testMultipartFilenameWithNulByteIsSanitized(): void
    {
        $boundary = 'NUL';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"safe\0bad.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\npayload\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file']);
            $name = $files['file']->name;
            self::assertStringNotContainsString("\0", $name);
            self::assertSame('safebad.txt', $name);
            return Response::json(['ok' => true]);
        });
    }

    public function testMultipartFilenameLongerThan200IsTruncated(): void
    {
        // Filenames are sanitized by {@see FilenameSanitizer}, which
        // truncates to {@see FilenameSanitizer::MAX_FILENAME_BYTES}
        // (200 bytes). The old 255-byte cap is gone — keeping it would
        // make the sanitized name reject on every supported filesystem.
        $boundary = 'LONG';
        $longName = str_repeat('a', 500);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$longName}\"\r\n"
            . "Content-Type: text/plain\r\n\r\npayload\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file']);
            self::assertSame(\Framework\Http\Multipart\FilenameSanitizer::MAX_FILENAME_BYTES, strlen($files['file']->name));
            self::assertSame(str_repeat('a', \Framework\Http\Multipart\FilenameSanitizer::MAX_FILENAME_BYTES), $files['file']->name);
            return Response::json(['ok' => true]);
        });
    }

    public function testMultipartFilenameAtBoundaryIsKept(): void
    {
        // Exactly MAX_FILENAME_BYTES bytes is the upper bound that
        // survives sanitization without truncation. Above this length
        // the sanitizer truncates; at or below it the name is kept as-is.
        $boundary = 'EXACT';
        $exactName = str_repeat('b', \Framework\Http\Multipart\FilenameSanitizer::MAX_FILENAME_BYTES);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"{$exactName}\"\r\n"
            . "Content-Type: text/plain\r\n\r\npayload\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r) use ($exactName): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file']);
            self::assertSame($exactName, $files['file']->name);
            return Response::json(['ok' => true]);
        });
    }

    /**
     * @return array<string, string>
     */
    private function buildPost(int $count): array
    {
        $post = [];
        for ($i = 0; $i < $count; $i++) {
            $post["k{$i}"] = 'v';
        }
        return $post;
    }

    /**
     * Minimal valid `$_FILES` entry so `hasSuperglobalUploads()`
     * returns true and the parser actually consumes the superglobal
     * path. The `tmp_name` is bogus on purpose — these tests assert
     * on the cap exception, never on the file object itself.
     *
     * @return array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    private function dummyFileEntry(): array
    {
        return [
            'stub' => [
                'name' => 'stub.bin',
                'type' => 'application/octet-stream',
                'tmp_name' => '/nonexistent/stub',
                'error' => 0,
                'size' => 0,
            ],
        ];
    }

    /**
     * @template T
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param callable(): T $body
     * @return T
     */
    private function withSuperglobals(array $post, array $files, callable $body): mixed
    {
        $previousPost = $_POST;
        $previousFiles = $_FILES;
        $_POST = $post;
        $_FILES = $files;
        try {
            return $body();
        } finally {
            $_POST = $previousPost;
            $_FILES = $previousFiles;
        }
    }
}
