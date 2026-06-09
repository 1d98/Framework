<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Multipart;

use Framework\Http\Multipart\SuperglobalFormReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuperglobalFormReader::class)]
final class SuperglobalFormReaderTest extends TestCase
{
    /**
     * @var array<mixed>
     */
    private array $previousPost = [];

    /**
     * @var array<mixed>
     */
    private array $previousFiles = [];

    protected function setUp(): void
    {
        $this->previousPost = $_POST;
        $this->previousFiles = $_FILES;
    }

    protected function tearDown(): void
    {
        $_POST = $this->previousPost;
        $_FILES = $this->previousFiles;
    }

    public function testHasUploadsIsTrueWhenFilesSuperglobalNonEmpty(): void
    {
        $_POST = [];
        $_FILES = ['stub' => ['name' => 'a.bin', 'type' => 'application/octet-stream', 'tmp_name' => '/n', 'error' => 0, 'size' => 0]];

        self::assertTrue(SuperglobalFormReader::hasUploads());
    }

    public function testHasFormDataIsTrueWhenPostSuperglobalNonEmpty(): void
    {
        $_POST = ['email' => 'alice@example.com'];
        $_FILES = [];

        self::assertTrue(SuperglobalFormReader::hasFormData());
    }

    public function testHasFormDataIsTrueWhenFilesSuperglobalNonEmpty(): void
    {
        $_POST = [];
        $_FILES = ['stub' => ['name' => 'a.bin', 'type' => 'application/octet-stream', 'tmp_name' => '/n', 'error' => 0, 'size' => 0]];

        self::assertTrue(SuperglobalFormReader::hasFormData());
    }

    public function testHasFormDataIsFalseWhenBothSuperglobalsEmpty(): void
    {
        $_POST = [];
        $_FILES = [];

        self::assertFalse(SuperglobalFormReader::hasFormData());
    }
}
