<?php

declare(strict_types=1);

namespace Framework\Filesystem;

/**
 * Handle to an advisory `flock(LOCK_EX)` acquired by
 * {@see AtomicFilesystem::lock()}.
 *
 * The handle owns the underlying file resource; {@see self::release()}
 * closes it. `release()` is idempotent — a second call is a no-op
 * and never throws. The destructor calls `release()` as a safety
 * net for the "forgot the finally" path, so a leaked Lock still
 * eventually returns the OS-level lock to the pool.
 *
 * Mutable on purpose: `$_released` flips on the first `release()` so
 * the second call is a no-op. Everything else is the file resource
 * the handle is bound to.
 */
final class Lock
{
    /** @var resource */
    private $stream;

    private bool $_released = false;

    /**
     * @param resource $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function release(): void
    {
        if ($this->_released) {
            return;
        }
        $this->_released = true;

        if (is_resource($this->stream)) {
            // Best-effort unlock + close. flock() may fail on NFS / FUSE
            // (returns false); we deliberately do not throw — the
            // destructor / GC path must be safe to run during shutdown.
            @flock($this->stream, LOCK_UN);
            @fclose($this->stream);
        }
    }

    public function isHeld(): bool
    {
        return !$this->_released;
    }

    public function __destruct()
    {
        $this->release();
    }
}
