<?php

declare(strict_types=1);

namespace Framework\Filesystem;

use Framework\Exception\FrameworkException;

/**
 * Thrown by {@see AtomicFilesystem::lock()} when the caller passed
 * `nonBlocking: true` and the lock is currently held by another
 * holder. Distinct from {@see AtomicFilesystemException} so the
 * caller can react with a "try again later" policy (the 429 pattern)
 * rather than treating it as a hard error.
 */
final class WouldBlockException extends FrameworkException
{
}
