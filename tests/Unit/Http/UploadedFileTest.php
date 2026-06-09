<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\UploadedFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir();
    }

    public function testConstructorExposesProperties(): void
    {
        $file = new UploadedFile('photo.png', 'image/png', sys_get_temp_dir() . '/fake_abc', 0, 1024);

        self::assertSame('photo.png', $file->name);
        self::assertSame('image/png', $file->type);
        self::assertSame(sys_get_temp_dir() . '/fake_abc', $file->tmpPath);
        self::assertSame(0, $file->error);
        self::assertSame(1024, $file->size);
    }

    public function testIsValidWhenErrorIsUploadErrOk(): void
    {
        $file = new UploadedFile('a.txt', 'text/plain', sys_get_temp_dir() . '/fake_x', UPLOAD_ERR_OK, 10);
        self::assertTrue($file->isValid());
    }

    public function testIsInvalidForNonOkErrorCodes(): void
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
            UPLOAD_ERR_EXTENSION,
        ];
        foreach ($errors as $err) {
            $file = new UploadedFile('a.txt', 'text/plain', sys_get_temp_dir() . '/fake_x', $err, 0);
            self::assertFalse($file->isValid(), "Expected invalid for error code {$err}");
        }
    }

    public function testMoveToRenamesForSynthesizedUpload(): void
    {
        $sourcePath = tempnam($this->tmpDir, 'uf_src_');
        self::assertNotFalse($sourcePath);
        file_put_contents($sourcePath, 'payload bytes');

        $targetPath = $this->tmpDir . '/uf_target_' . uniqid() . '.dat';
        $file = new UploadedFile('doc.bin', 'application/octet-stream', $sourcePath, UPLOAD_ERR_OK, 13);

        $file->moveTo($targetPath);

        self::assertFileDoesNotExist($sourcePath);
        self::assertFileExists($targetPath);
        self::assertSame('payload bytes', file_get_contents($targetPath));

        @unlink($targetPath);
    }

    public function testMoveToThrowsWhenErrorIsNotOk(): void
    {
        $file = new UploadedFile('a.txt', 'text/plain', sys_get_temp_dir() . '/fake_x', UPLOAD_ERR_NO_FILE, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('error code');

        $file->moveTo(sys_get_temp_dir() . '/fake_dest');
    }

    public function testMoveToThrowsWhenSourceMissing(): void
    {
        $file = new UploadedFile('a.txt', 'text/plain', '/nonexistent/path/' . uniqid(), UPLOAD_ERR_OK, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed to move');

        $file->moveTo(sys_get_temp_dir() . '/fake_dest_' . uniqid());
    }

    public function testConstructorThrowsOnNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('size cannot be negative');

        new UploadedFile('a.txt', 'text/plain', sys_get_temp_dir() . '/fake_x', 0, -1);
    }
}
