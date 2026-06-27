<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Middleware;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\PayloadTooLargeHttpException;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(MultipartBodyParser::class)]
final class MultipartBodyParserTest extends TestCase
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
        $this->cleanupTmpUploads();
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

    public function testParsesFormFieldAndFile(): void
    {
        $boundary = 'X-BOUNDARY';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\nAlice\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nfile content here\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $response = $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            $files = $r->files();
            self::assertIsArray($form);
            self::assertIsArray($files);
            self::assertSame('Alice', $form['name']);
            self::assertArrayHasKey('avatar', $files);
            self::assertInstanceOf(UploadedFile::class, $files['avatar']);
            self::assertSame('a.txt', $files['avatar']->name);
            self::assertSame('text/plain', $files['avatar']->type);
            self::assertSame(UPLOAD_ERR_OK, $files['avatar']->error);
            self::assertSame(17, $files['avatar']->size);
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
    }

    public function testParsesMultipleFilesPerSameFieldNameAsList(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"photos\"; filename=\"a.png\"\r\n"
            . "Content-Type: image/png\r\n\r\nAAAA\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"photos\"; filename=\"b.png\"\r\n"
            . "Content-Type: image/png\r\n\r\nBBBB\r\n"
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
            self::assertArrayHasKey('photos', $files);
            self::assertIsArray($files['photos']);
            self::assertCount(2, $files['photos']);
            self::assertSame('a.png', $files['photos'][0]->name);
            self::assertSame('b.png', $files['photos'][1]->name);
            self::assertSame(4, $files['photos'][0]->size);
            self::assertSame(4, $files['photos'][1]->size);
            return Response::json(['ok' => true]);
        });
    }

    public function testThrowsOnMissingBoundaryInContentType(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data'],
            'whatever',
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('missing boundary');

        $this->middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testThrowsOnMalformedBodyWithoutClosingBoundary(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\nAlice\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no closing boundary');

        $this->middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testThrowsWhenBoundaryMissingFromBody(): void
    {
        $boundary = 'UNIQUE-12345';
        $body = "this body has no multipart delimiters at all";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('no opening boundary');

        $this->middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testSkipsNonMultipartContentType(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'application/json'],
            '{"a":1}',
        );

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
            'files' => $r->files(),
        ]));

        self::assertInstanceOf(Response::class, $response);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertNull($body['form']);
        self::assertNull($body['files']);
    }

    public function testSkipsSafeMethods(): void
    {
        $request = new Request(
            'GET',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
            'form' => $r->form(),
            'files' => $r->files(),
        ]));

        self::assertInstanceOf(Response::class, $response);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertNull($body['form']);
        self::assertNull($body['files']);
    }

    public function testSkipsDeleteAndOptions(): void
    {
        foreach (['DELETE', 'OPTIONS', 'HEAD'] as $method) {
            $request = new Request(
                $method,
                '/upload',
                '',
                ['content-type' => 'multipart/form-data; boundary=X'],
                'whatever',
            );

            $response = $this->middleware->process($request, static fn(Request $r): Response => Response::json([
                'form' => $r->form(),
                'files' => $r->files(),
            ]));

            self::assertInstanceOf(Response::class, $response);
            $body = json_decode($response->body, true);
            self::assertIsArray($body);
            self::assertNull($body['form'], "{$method} should skip");
            self::assertNull($body['files'], "{$method} should skip");
        }
    }

    public function testIsIdempotentWhenFilesAlreadyParsed(): void
    {
        $request = (new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            'irrelevant',
        ))->withFiles(['preset' => new UploadedFile('a', 'text/plain', sys_get_temp_dir() . '/fake_x', 0, 1)]);

        $this->middleware->process($request, static function (Request $r): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertCount(1, $files);
            self::assertInstanceOf(UploadedFile::class, $files['preset']);
            self::assertSame('a', $files['preset']->name);
            return Response::json([]);
        });
    }

    public function testEmptyBodyProducesEmptyFormAndFiles(): void
    {
        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary=X'],
            '',
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            $files = $r->files();
            self::assertIsArray($form);
            self::assertIsArray($files);
            self::assertSame([], $form);
            self::assertSame([], $files);
            return Response::json([]);
        });
    }

    public function testOnlyFormFieldsYieldsEmptyFiles(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"b\"\r\n\r\n2\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            $files = $r->files();
            self::assertIsArray($form);
            self::assertIsArray($files);
            self::assertSame('1', $form['a']);
            self::assertSame('2', $form['b']);
            self::assertSame([], $files);
            return Response::json([]);
        });
    }

    public function testOnlyFilesYieldsEmptyForm(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"doc\"; filename=\"d.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\npayload\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            $files = $r->files();
            self::assertIsArray($form);
            self::assertIsArray($files);
            self::assertSame([], $form);
            self::assertCount(1, $files);
            self::assertInstanceOf(UploadedFile::class, $files['doc']);
            self::assertSame('d.bin', $files['doc']->name);
            return Response::json([]);
        });
    }

    public function testMultipleFormFieldsWithSameNameProduceList(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n\r\nfirst\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"tag\"\r\n\r\nsecond\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            self::assertIsArray($form['tag']);
            self::assertSame(['first', 'second'], $form['tag']);
            return Response::json([]);
        });
    }

    public function testFilePartWithoutContentTypeDefaultsToOctetStream(): void
    {
        $boundary = 'B';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"x\"; filename=\"x.bin\"\r\n\r\nRAW\r\n"
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
            self::assertInstanceOf(UploadedFile::class, $files['x']);
            self::assertSame('application/octet-stream', $files['x']->type);
            return Response::json([]);
        });
    }

    public function testQuotedBoundaryValueIsUnquoted(): void
    {
        $boundary = 'Q-BOUND';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => 'multipart/form-data; boundary="' . $boundary . '"'],
            $body,
        );

        $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            self::assertSame('v', $form['k']);
            return Response::json([]);
        });
    }

    public function testUsesDefaultTmpDir(): void
    {
        $defaultDir = realpath(__DIR__ . '/../../../../var/tmp');
        self::assertNotFalse($defaultDir, 'Project var/tmp/ must exist (it is gitkeep-tracked)');

        $middleware = new MultipartBodyParser();
        $request = $this->multipartRequestWithFile('hello default');

        $middleware->process($request, static function (Request $r) use ($defaultDir): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file'] ?? null);
            self::assertSame($defaultDir, dirname($files['file']->tmpPath));
            self::assertFileExists($files['file']->tmpPath);
            self::assertSame('hello default', file_get_contents($files['file']->tmpPath));
            @unlink($files['file']->tmpPath);
            return Response::json([]);
        });
    }

    public function testUsesCustomTmpDir(): void
    {
        $customDir = sys_get_temp_dir() . '/mwb_custom_' . uniqid();
        mkdir($customDir, 0777, true);
        $resolvedDir = realpath($customDir);
        self::assertNotFalse($resolvedDir);
        try {
            $middleware = new MultipartBodyParser($customDir);
            $request = $this->multipartRequestWithFile('hello custom');

            $middleware->process($request, static function (Request $r) use ($resolvedDir): Response {
                $files = $r->files();
                self::assertIsArray($files);
                self::assertInstanceOf(UploadedFile::class, $files['file'] ?? null);
                self::assertSame($resolvedDir, dirname($files['file']->tmpPath));
                self::assertFileExists($files['file']->tmpPath);
                self::assertSame('hello custom', file_get_contents($files['file']->tmpPath));
                @unlink($files['file']->tmpPath);
                return Response::json([]);
            });
        } finally {
            @rmdir($customDir);
        }
    }

    public function testCreatesTmpDirIfMissing(): void
    {
        $nestedDir = sys_get_temp_dir() . '/mwb_nested_' . uniqid() . '/sub/dir';
        self::assertDirectoryDoesNotExist($nestedDir);

        $middleware = new MultipartBodyParser($nestedDir);
        $request = $this->multipartRequestWithFile('hello nested');

        $middleware->process($request, static function (Request $r) use ($nestedDir): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file'] ?? null);
            self::assertFileExists($files['file']->tmpPath);
            self::assertSame('hello nested', file_get_contents($files['file']->tmpPath));
            self::assertSame(realpath($nestedDir), realpath(dirname($files['file']->tmpPath)));
            @unlink($files['file']->tmpPath);
            return Response::json([]);
        });

        self::assertDirectoryExists($nestedDir);
        @rmdir($nestedDir);
        @rmdir(dirname($nestedDir));
        @rmdir(dirname(dirname($nestedDir)));
    }

    public function testThrowsIfCannotCreateTmpDir(): void
    {
        $blockingFile = tempnam(sys_get_temp_dir(), 'mwb_block_');
        self::assertNotFalse($blockingFile);
        try {
            $unwritable = $blockingFile . '/cannot/create/here';
            $middleware = new MultipartBodyParser($unwritable);
            $request = $this->multipartRequestWithFile('will not be written');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot create tmp directory');

            $middleware->process($request, static fn(Request $r): Response => Response::json([]));
        } finally {
            @unlink($blockingFile);
        }
    }

    private function multipartRequestWithFile(string $payload): Request
    {
        $boundary = 'MWB-TEST';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"f.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n{$payload}\r\n"
            . "--{$boundary}--\r\n";

        return new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
        );
    }

    public function testThrowsPayloadTooLargeWhenBodyExceedsConfiguredCap(): void
    {
        $this->expectException(PayloadTooLargeHttpException::class);
        $this->expectExceptionMessage('exceeds cap of 1024 bytes');

        $boundary = 'CAP-BOUND';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"big.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('A', 4096) . "\r\n"
            . "--{$boundary}--\r\n";

        new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
            maxBodyBytes: 1024,
        );
    }

    public function testAcceptsBodyUnderConfiguredCap(): void
    {
        $middleware = new MultipartBodyParser(null, maxBodyBytes: 4096);
        $request = $this->multipartRequestWithFile('small payload');

        $middleware->process($request, static function (Request $r): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['file'] ?? null);
            return Response::json([]);
        });
    }

    public function testDefaultCapMatchesRequestMaxBodyBytes(): void
    {
        $middleware = new MultipartBodyParser();

        $reflection = new \ReflectionClass($middleware);
        $prop = $reflection->getProperty('maxBodyBytes');
        $value = $prop->getValue($middleware);

        self::assertSame(Request::MAX_BODY_BYTES, $value);
    }

    public function testThrowsPayloadTooLargeWhenTwoPartsCumulateBeyondCap(): void
    {
        $boundary = 'PART-CAP';
        $sixMb = str_repeat('A', 6 * 1024 * 1024);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"first\"; filename=\"a.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$sixMb}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"second\"; filename=\"b.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$sixMb}\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
            maxBodyBytes: PHP_INT_MAX,
        );

        $middleware = new MultipartBodyParser(null, maxBodyBytes: 10 * 1024 * 1024);

        $this->expectException(PayloadTooLargeHttpException::class);

        $middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testAcceptsSingleLargePartUnderCap(): void
    {
        $boundary = 'UNDER-CAP';
        $nineMb = str_repeat('B', 9 * 1024 * 1024);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"big\"; filename=\"big.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$nineMb}\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
            maxBodyBytes: PHP_INT_MAX,
        );

        $middleware = new MultipartBodyParser(null, maxBodyBytes: 10 * 1024 * 1024);

        $response = $middleware->process($request, static function (Request $r) use ($nineMb): Response {
            $files = $r->files();
            self::assertIsArray($files);
            self::assertInstanceOf(UploadedFile::class, $files['big'] ?? null);
            self::assertSame(strlen($nineMb), $files['big']->size);
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
    }

    public function testThrowsPayloadTooLargeWhenSinglePartExceedsCap(): void
    {
        $boundary = 'OVER-CAP';
        $elevenMb = str_repeat('C', 11 * 1024 * 1024);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"huge\"; filename=\"huge.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$elevenMb}\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            ['content-type' => "multipart/form-data; boundary={$boundary}"],
            $body,
            maxBodyBytes: PHP_INT_MAX,
        );

        $middleware = new MultipartBodyParser(null, maxBodyBytes: 10 * 1024 * 1024);

        $this->expectException(PayloadTooLargeHttpException::class);

        $middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testThrowsBadRequestWhenContentLengthMismatchesBody(): void
    {
        $boundary = 'CL-MISMATCH';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => "multipart/form-data; boundary={$boundary}",
                'content-length' => (string) (strlen($body) + 100),
            ],
            $body,
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Content-Length ' . (strlen($body) + 100) . ' does not match actual body length ' . strlen($body));

        $this->middleware->process($request, static fn(Request $r): Response => Response::json([]));
    }

    public function testAcceptsMatchingContentLength(): void
    {
        $boundary = 'CL-OK';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request(
            'POST',
            '/upload',
            '',
            [
                'content-type' => "multipart/form-data; boundary={$boundary}",
                'content-length' => (string) strlen($body),
            ],
            $body,
        );

        $response = $this->middleware->process($request, static function (Request $r): Response {
            $form = $r->form();
            self::assertIsArray($form);
            self::assertSame('v', $form['k']);
            return Response::json(['ok' => true]);
        });

        self::assertSame(200, $response->status);
    }

    public function testFallsBackToPostSuperglobalWhenBodyEmptyAndNoUploads(): void
    {
        $request = new Request(
            'POST',
            '/login',
            '',
            ['content-type' => 'multipart/form-data; boundary=IRRELEVANT'],
            '',
        );

        $response = $this->withSuperglobals(
            ['email' => 'alice@example.com', 'password' => 's3cret'],
            [],
            fn(): ResponseInterface => $this->middleware->process($request, static function (Request $r): Response {
                $form = $r->form();
                $files = $r->files();
                self::assertIsArray($form);
                self::assertIsArray($files);
                self::assertSame('alice@example.com', $form['email']);
                self::assertSame('s3cret', $form['password']);
                self::assertSame([], $files);
                return Response::json(['ok' => true]);
            }),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
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
