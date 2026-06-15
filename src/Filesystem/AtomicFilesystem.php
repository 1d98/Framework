<?php

declare(strict_types=1);

namespace Framework\Filesystem;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Two small filesystem primitives that every "batteries-included"
 * project eventually re-implements: an atomic write (tmp + rename
 * so a reader never sees a half-written file) and an exclusive
 * `flock` wrapper (so two workers do not trample each other's
 * update). Extracted from `StreamLogger` (which inlined `flock` on
 * every log line) and from `TempFilePool` (which inlined `mkdir(...
 * 0o755, true)`) so future code (cache, idempotency, job queue)
 * can reuse them.
 *
 * **Design notes.**
 *
 * 1. **Atomic write = `tmp` + `fwrite` + `fflush` + `rename`.**
 *    On POSIX filesystems `rename()` is atomic: a concurrent reader
 *    either sees the old file (pre-rename) or the new file
 *    (post-rename), never a partial one. On Windows `rename()`
 *    overwrites the target non-atomically, so the worst case is a
 *    short window where the file is being replaced — acceptable
 *    for a skeleton; the docblock notes the platform difference.
 *
 * 2. **`flock` is advisory, not mandatory.** Processes that do not
 *    take the lock are not blocked; the lock is a convention
 *    between cooperating writers. NFS and some FUSE filesystems
 *    silently ignore `flock` (return `false`); the docblock
 *    states that operators must verify the deployment filesystem
 *    before relying on cross-host coordination.
 *
 * 3. **Path validation is minimal.** Empty path and NUL byte are
 *    rejected; symlink resolution is NOT performed (a caller who
 *    wants to defend against `..` traversal should pass an
 *    absolute path that realpath()'s to a known root before
 *    calling). The class is a primitive, not a sandbox.
 */
final class AtomicFilesystem
{
    private const int MAX_SAFE_PATH_LENGTH = 4096;

    private function __construct()
    {
    }

    /**
     * Write `$contents` to `$path` atomically. The destination either
     * exists with the full new content (on success) or is unchanged
     * (on failure — the tmp file is removed in `finally`).
     *
     * @param int $mode File permission bits (default 0600 — owner
     *     read/write only). Applied via `chmod` after the rename
     *     succeeds. On Windows the mode is a best-effort no-op.
     * @param int $dirMode Permission bits for the parent directory
     *     (default 0700). Created with `mkdir($recursive: true)`
     *     if missing. Idempotent: a `mkdir` race between two
     *     callers that both try to create the same dir is
     *     tolerated (the second call sees `is_dir()` true and
     *     moves on).
     *
     * @throws AtomicFilesystemException on path validation failure,
     *     `mkdir` failure, `fopen` failure, short `fwrite`, or
     *     `rename` failure.
     */
    public static function write(
        string $path,
        string $contents,
        int $mode = 0o600,
        int $dirMode = 0o700,
    ): void {
        self::assertSafePath($path);

        // Normalize to OS-native separators. The caller may pass
        // a path with mixed `\` and `/` (e.g. an absolute path from
        // `realpath` joined with a relative suffix), and Windows
        // `rename()` chokes on the mismatch.
        $path = str_replace('\\', '/', $path);
        $dir = dirname($path);
        self::ensureDirectory($dir, $dirMode);

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            throw new AtomicFilesystemException("fopen failed for tmp file: {$tmp}");
        }

        try {
            $bytesToWrite = strlen($contents);
            $written = fwrite($fp, $contents);
            if ($written === false || $written !== $bytesToWrite) {
                throw new AtomicFilesystemException(
                    "fwrite short for tmp file {$tmp}: expected {$bytesToWrite} bytes, wrote "
                    . ($written === false ? 'false' : (string) $written),
                );
            }
            fflush($fp);
            if (!@rename($tmp, $path)) {
                throw new AtomicFilesystemException("rename failed: {$tmp} -> {$path}");
            }
            @chmod($path, $mode);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            if (is_resource($fp)) {
                fclose($fp);
            }
        }
    }

    /**
     * Same as {@see self::write()} but encodes `$data` as JSON first.
     * `JSON_THROW_ON_ERROR` is always passed; a non-encodable value
     * surfaces as `AtomicFilesystemException` before the file is
     * touched.
     *
     * @param int $flags `json_encode` flags (default UNESCAPED_*
     *     for human-readable on-disk files).
     */
    public static function writeJson(
        string $path,
        mixed $data,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        int $mode = 0o600,
        int $dirMode = 0o700,
    ): void {
        try {
            $encoded = json_encode($data, $flags | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AtomicFilesystemException(
                "json_encode failed for writeJson({$path}): " . $e->getMessage(),
                0,
                $e,
            );
        }
        self::write($path, $encoded, $mode, $dirMode);
    }

    /**
     * Acquire an exclusive advisory lock on the file at `$path` and
     * return a {@see Lock} handle. The lock file is created with
     * mode 0600 if it does not exist; the parent directory is
     * created with 0700.
     *
     * When `$nonBlocking` is `true`, returns immediately with a
     * {@see WouldBlockException} if another holder has the lock.
     * When `false` (default), blocks until the lock is acquired.
     *
     * Always pair with `try { ... } finally { $lock->release(); }`
     * — the destructor is a safety net, but releasing explicitly
     * makes the lock lifetime obvious to readers.
     *
     * @throws WouldBlockException When `$nonBlocking` is `true` and
     *     the lock is currently held.
     * @throws AtomicFilesystemException on path validation failure,
     *     `mkdir` failure, `fopen` failure, or blocking `flock`
     *     failure (e.g. NFS / FUSE that does not support `flock`).
     */
    public static function lock(
        string $path,
        bool $nonBlocking = false,
    ): Lock {
        self::assertSafePath($path);

        $path = str_replace('\\', '/', $path);
        $dir = dirname($path);
        self::ensureDirectory($dir, 0o700);

        $fp = @fopen($path, 'c');
        if ($fp === false) {
            throw new AtomicFilesystemException("fopen failed for lock file: {$path}");
        }

        $op = $nonBlocking ? (LOCK_EX | LOCK_NB) : LOCK_EX;
        if (!flock($fp, $op)) {
            fclose($fp);
            if ($nonBlocking) {
                throw new WouldBlockException("lock contended: {$path}");
            }
            throw new AtomicFilesystemException(
                "flock failed for: {$path} (NFS / FUSE without flock support?)",
            );
        }

        return new Lock($fp);
    }

    /**
     * Iterate the directory tree rooted at `$path`, yielding each
     * file path (not directories) as a string. Used by callers that
     * need to enumerate lock files, idempotency entries, or any
     * other per-key on-disk store.
     *
     * The iterator is lazy (a `Generator`) so a million-file
     * directory does not materialise a million-element array in
     * memory.
     *
     * @return Generator<int, string>
     */
    public static function listFiles(string $path): Generator
    {
        if (!is_dir($path)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iter as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Delete the directory tree at `$path` recursively. The
     * directory itself is removed after its contents. Missing
     * directories are silently ignored.
     */
    public static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * Reject obviously dangerous paths. Empty string, NUL byte, and
     * an over-long path are all caught. Path traversal via `..` is
     * the caller's problem — the helper does not resolve symlinks
     * or check that the result stays under a trusted root.
     */
    private static function assertSafePath(string $path): void
    {
        if ($path === '') {
            throw new AtomicFilesystemException('empty path');
        }
        if (strlen($path) > self::MAX_SAFE_PATH_LENGTH) {
            throw new AtomicFilesystemException(
                'path exceeds maximum safe length (' . self::MAX_SAFE_PATH_LENGTH . ')',
            );
        }
        if (str_contains($path, "\0")) {
            throw new AtomicFilesystemException('NUL byte in path');
        }
    }

    /**
     * Create `$dir` with `$mode` (recursive) if it does not exist.
     * Tolerates the race where two callers try to create the same
     * dir concurrently: the second `mkdir` returns `false`, but
     * the subsequent `is_dir` confirms the directory now exists.
     */
    private static function ensureDirectory(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new AtomicFilesystemException("mkdir failed: {$dir}");
        }
    }
}
