<?php

declare(strict_types=1);

namespace Framework\Filesystem;

use Framework\Exception\FrameworkException;
use RuntimeException;

/**
 * Thrown by {@see AtomicFilesystem} when an atomic write or lock
 * cannot be completed. Distinct from generic {@see RuntimeException}
 * so callers (and the kernel) can recognize "filesystem operation
 * failed" without string-matching the message.
 */
final class AtomicFilesystemException extends FrameworkException
{
}
