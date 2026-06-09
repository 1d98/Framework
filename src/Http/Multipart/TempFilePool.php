<?php

declare(strict_types=1);

namespace Framework\Http\Multipart;

use RuntimeException;

/**
 * Thin temp-file lifecycle helper used by middleware that needs to
 * flush parsed parts to disk. Tracks every path it hands out so a
 * `release()` call can clean up partial state on exception.
 *
 * Keeping the lifecycle in one place lets middleware delegate the
 * "create / write / remember / clean up" pattern without each
 * body-parser re-implementing `tempnam` + `file_put_contents` +
 * `unlink` + `rmdir` plumbing.
 */
final class TempFilePool
{
    /** @var list<string> */
    private array $paths = [];

    public function __construct(
        private readonly string $tmpDir,
    ) {
    }

    public function directory(): string
    {
        return $this->tmpDir;
    }

    /**
     * Write $payload to a fresh temp file in {@see directory()}.
     *
     * The returned entry must be appended to the {@see ParsedMultipart}
     * cleanup ledger so {@see release()} can unlink it on failure.
     *
     * @return array{path: string, size: int, error: ?string}
     */
    public function write(string $payload): array
    {
        $this->ensureDirExists();

        $path = @tempnam($this->tmpDir, 'upl_');
        if ($path === false) {
            return ['path' => '', 'size' => 0, 'error' => 'tempnam_failed'];
        }

        $bytes = @file_put_contents($path, $payload);
        if ($bytes === false) {
            @unlink($path);
            return ['path' => '', 'size' => 0, 'error' => 'write_failed'];
        }

        $this->paths[] = $path;

        return ['path' => $path, 'size' => $bytes, 'error' => null];
    }

    /**
     * Unlink every tracked path. Used by the middleware's catch
     * block to make a half-parsed request disappear cleanly.
     *
     * @param list<string> $extra Paths written outside the pool
     *                            (e.g. when {@see write()} returned
     *                            an error and the pool didn't track
     *                            them). Best-effort; the next
     *                            request's tmp rotation will sweep
     *                            anything left behind.
     */
    public function release(array $extra = []): void
    {
        $parent = null;
        foreach ([...$this->paths, ...$extra] as $path) {
            if ($path === '') {
                continue;
            }
            if ($parent === null) {
                $parent = dirname($path);
            }
            @unlink($path);
        }
        if ($parent !== null && is_dir($parent)) {
            $remaining = @scandir($parent);
            if (is_array($remaining) && count($remaining) <= 2) {
                @rmdir($parent);
            }
        }
    }

    private function ensureDirExists(): void
    {
        if (is_dir($this->tmpDir)) {
            return;
        }

        $created = @mkdir($this->tmpDir, 0777, true);
        if (!$created && !is_dir($this->tmpDir)) {
            throw new RuntimeException(
                'Cannot create tmp directory for uploads: ' . $this->tmpDir,
            );
        }
    }
}
