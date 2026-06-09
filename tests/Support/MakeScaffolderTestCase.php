<?php

declare(strict_types=1);

namespace Framework\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

abstract class MakeScaffolderTestCase extends TestCase
{
    protected string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/framework-make-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmpDir);
    }

    protected function tmpFile(string $relativePath): string
    {
        return $this->tmpDir . '/' . ltrim($relativePath, '/');
    }

    protected function pathInTmp(string $relativePath): string
    {
        return $this->tmpFile($relativePath);
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
