<?php

declare(strict_types=1);

namespace Framework\Http\Idempotency;

use Framework\Exception\FrameworkException;

/**
 * Thrown by {@see \Framework\Http\Middleware\IdempotencyKeyMiddleware}
 * when a second request uses an `Idempotency-Key` that was
 * previously bound to a request with a different body hash,
 * method, or path. The caller must pick a fresh `Idempotency-Key`
 * (the standard 422 semantics per Stripe's
 * `idempotency-mismatch` documentation).
 */
final class IdempotencyConflictException extends FrameworkException
{
}
