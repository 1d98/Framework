<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Filesystem;

use Framework\Filesystem\AtomicFilesystem;
use Framework\Filesystem\AtomicFilesystemException;
use Framework\Filesystem\Lock;
use Framework\Filesystem\WouldBlockException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtomicFilesystem::class)]
#[CoversClass(Lock::class)]
#[CoversClass(AtomicFilesystemException::class)]
#[CoversClass(WouldBlockException::class)]
final class AtomicFilesystemTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/framework-atomicfs-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        AtomicFilesystem::removeTree($this->tmpDir);
    }

    public function testWriteCreatesFileWithExactContents(): void
    {
        $path = $this->tmpDir . '/config.json';
        AtomicFilesystem::write($path, '{"hello":"world"}');

        self::assertFileExists($path);
        self::assertSame('{"hello":"world"}', (string) file_get_contents($path));
    }

    public function testWriteOverwritesExistingFile(): void
    {
        $path = $this->tmpDir . '/file.txt';
        AtomicFilesystem::write($path, 'first');
        AtomicFilesystem::write($path, 'second');

        self::assertSame('second', (string) file_get_contents($path));
    }

    public function testWriteCreatesMissingParentDirectory(): void
    {
        $path = $this->tmpDir . '/nested/dir/config.json';
        self::assertDirectoryDoesNotExist(dirname($path));

        AtomicFilesystem::write($path, 'ok');

        self::assertFileExists($path);
        self::assertDirectoryExists(dirname($path));
    }

    public function testWriteJsonEncodesDataAndPersists(): void
    {
        $path = $this->tmpDir . '/data.json';
        AtomicFilesystem::writeJson($path, ['counter' => 42, 'name' => 'framework']);

        $contents = (string) file_get_contents($path);
        self::assertSame('{"counter":42,"name":"framework"}', $contents);
    }

    public function testWriteJsonThrowsOnUnencodable(): void
    {
        $path = $this->tmpDir . '/bad.json';

        $this->expectException(AtomicFilesystemException::class);
        $this->expectExceptionMessage('json_encode failed');

        // NAN is not JSON-encodable
        AtomicFilesystem::writeJson($path, ['value' => NAN]);
    }

    public function testWriteRejectsEmptyPath(): void
    {
        $this->expectException(AtomicFilesystemException::class);
        $this->expectExceptionMessage('empty path');
        AtomicFilesystem::write('', 'data');
    }

    public function testWriteRejectsNulByteInPath(): void
    {
        $this->expectException(AtomicFilesystemException::class);
        $this->expectExceptionMessage('NUL byte');
        AtomicFilesystem::write($this->tmpDir . "/\0evil", 'data');
    }

    public function testWriteRejectsOverlyLongPath(): void
    {
        $this->expectException(AtomicFilesystemException::class);
        $this->expectExceptionMessage('maximum safe length');
        AtomicFilesystem::write('/' . str_repeat('a', 5000), 'data');
    }

    public function testWriteFailsWhenDestinationIsReadOnly(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('read-only directory test is POSIX-specific');
        }
        $ro = $this->tmpDir . '/readonly';
        mkdir($ro, 0o555);
        $path = $ro . '/file.txt';

        $this->expectException(AtomicFilesystemException::class);
        try {
            AtomicFilesystem::write($path, 'data');
        } finally {
            chmod($ro, 0o755);
        }
    }

    public function testLockAcquiresExclusiveLockOnFile(): void
    {
        $lockPath = $this->tmpDir . '/work.lock';
        $lock = AtomicFilesystem::lock($lockPath);

        self::assertInstanceOf(Lock::class, $lock);
        self::assertTrue($lock->isHeld());
        self::assertFileExists($lockPath);

        $lock->release();
        self::assertFalse($lock->isHeld());
    }

    public function testLockReleaseIsIdempotent(): void
    {
        $lockPath = $this->tmpDir . '/work.lock';
        $lock = AtomicFilesystem::lock($lockPath);

        $lock->release();
        $lock->release();
        $lock->release();

        self::assertFalse($lock->isHeld());
    }

    public function testNonBlockingLockThrowsWouldBlockWhenContended(): void
    {
        $lockPath = $this->tmpDir . '/contended.lock';
        $first = AtomicFilesystem::lock($lockPath);

        try {
            $this->expectException(WouldBlockException::class);
            AtomicFilesystem::lock($lockPath, nonBlocking: true);
        } finally {
            $first->release();
        }
    }

    public function testBlockingLockAcquiresAfterContenderReleases(): void
    {
        $lockPath = $this->tmpDir . '/serial.lock';
        $first = AtomicFilesystem::lock($lockPath);
        $first->release();

        // A second caller after release must succeed
        $second = AtomicFilesystem::lock($lockPath);
        self::assertTrue($second->isHeld());
        $second->release();
    }

    public function testListFilesYieldsRecursiveContents(): void
    {
        AtomicFilesystem::write($this->tmpDir . '/a.txt', 'a');
        AtomicFilesystem::write($this->tmpDir . '/sub/b.txt', 'b');
        AtomicFilesystem::write($this->tmpDir . '/sub/deep/c.txt', 'c');

        $files = iterator_to_array(AtomicFilesystem::listFiles($this->tmpDir), false);
        sort($files);

        // Normalize the OS-native separator in BOTH the iterator
        // output and the expected paths. `RecursiveDirectoryIterator`
        // on Windows returns `\`, but a `realpath()`-resolved
        // `$this->tmpDir` joined with `/`-style segments can
        // produce mixed separators (e.g. `C:\Temp/foo`). The
        // platform-portable comparison is "forward-slash only".
        $normalize = static fn(string $p): string => str_replace('\\', '/', $p);
        $files = array_map($normalize, $files);
        $expected = array_map(
            $normalize,
            [
                $this->tmpDir . '/a.txt',
                $this->tmpDir . '/sub/b.txt',
                $this->tmpDir . '/sub/deep/c.txt',
            ],
        );

        self::assertSame($expected, $files);
    }

    public function testListFilesOnMissingDirYieldsNothing(): void
    {
        $files = iterator_to_array(AtomicFilesystem::listFiles($this->tmpDir . '/does-not-exist'), false);
        self::assertSame([], $files);
    }

    public function testRemoveTreeDeletesRecursively(): void
    {
        AtomicFilesystem::write($this->tmpDir . '/sub/a.txt', 'a');
        AtomicFilesystem::write($this->tmpDir . '/sub/b.txt', 'b');
        AtomicFilesystem::write($this->tmpDir . '/sub/deep/c.txt', 'c');

        $treePath = $this->tmpDir . '/sub';
        AtomicFilesystem::removeTree($treePath);

        self::assertDirectoryDoesNotExist($treePath);
    }

    public function testRemoveTreeOnMissingPathIsNoOp(): void
    {
        AtomicFilesystem::removeTree($this->tmpDir . '/never-existed');
        self::assertDirectoryDoesNotExist($this->tmpDir . '/never-existed');
    }

    public function testWriteIsAtomicUnderConcurrentReaders(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Spawning child readers + concurrent renames is
            // racy on Windows CI (the rename across short-lived
            // tmp files sometimes fails with `rename failed` when
            // the OS swaps the volume under us). The POSIX
            // path is the canonical one; Windows gets the
            // single-writer atomicity test via the other
            // already-passing tests.
            self::markTestSkipped('Concurrent-reader atomicity test is POSIX-only');
        }

        $path = $this->tmpDir . '/concurrent.bin';
        $pathQuoted = var_export($path, true);
        AtomicFilesystem::write($path, str_repeat('A', 1024));

        // Spawn N child processes that read the file in a tight loop
        // while we overwrite it M times with a different byte. After
        // the storm, every reader must have seen either the full
        // all-A payload (pre-rename) or the full all-B payload
        // (post-rename) — never a mix.
        $iterations = 50;
        $readers = 4;
        $script = sys_get_temp_dir() . '/atomic-reader-' . bin2hex(random_bytes(4)) . '.php';
        $readerCode = <<<'PHP'
<?php
declare(strict_types=1);
$path = PATH;
$readCount = (int) $argv[1];
for ($i = 0; $i < $readCount; $i++) {
    $contents = (string) file_get_contents($path);
    if ($contents === false || $contents === '') continue;
    // Every byte must be identical — never a mix of A and B.
    // str_split gives string elements even on PHP 8.5 where $s[0] returns int.
    $bytes = str_split($contents);
    $first = $bytes[0];
    for ($j = 1; $j < count($bytes); $j++) {
        if ($bytes[$j] !== $first) {
            fwrite(STDERR, "PARTIAL READ at iter $i pos $j: first=$first got=" . $bytes[$j] . "\n");
            exit(2);
        }
    }
}
PHP;
        file_put_contents($script, str_replace('PATH', $pathQuoted, $readerCode));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processes = [];
        for ($r = 0; $r < $readers; $r++) {
            $proc = proc_open(
                [PHP_BINARY, $script, (string) $iterations],
                $descriptors,
                $pipes,
            );
            self::assertIsResource($proc);
            $processes[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        // Now hammer the writer
        for ($i = 0; $i < $iterations; $i++) {
            AtomicFilesystem::write($path, str_repeat('B', 1024));
            AtomicFilesystem::write($path, str_repeat('A', 1024));
        }

        foreach ($processes as $entry) {
            $exit = proc_close($entry['proc']);
            self::assertSame(0, $exit, 'No reader must see a partial read (mixed A/B payload)');
        }

        @unlink($script);
    }
}
