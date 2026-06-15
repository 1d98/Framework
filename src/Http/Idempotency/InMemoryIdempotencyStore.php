<?php

declare(strict_types=1);

namespace Framework\Http\Idempotency;

use Framework\Http\Cookie\Cookie;

/**
 * In-process implementation of {@see IdempotencyStoreInterface}.
 *
 * **In-process scope.** State lives in a static `array` on the
 * class, so it is shared across all requests in the same PHP
 * process (PHP-FPM worker, Octane worker, etc.). Behind a load
 * balancer, each worker has an independent copy of the store;
 * the docblock on the interface spells out the multi-instance
 * caveat. For the skeleton's "batteries-included" purpose, this
 * is the right default — no filesystem permissions, no
 * dependency on shared storage, predictable test behaviour.
 *
 * **Concurrency.** Single-threaded PHP means no read-modify-write
 * race within one process. The `tryReserve` "is in flight"
 * state is a per-key boolean that flips from `false` → `true`
 * inside `tryReserve`; the subsequent `put` flips it back to
 * `false` after the response is recorded.
 */
final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /**
     * Per-slot state. Keyed by `<method>:<path>:<key>`.
     *
     * @var array<string, array{
     *     method: string,
     *     path: string,
     *     bodyHash: string,
     *     entry: ?IdempotencyEntry,
     *     reserved: bool,
     * }>
     */
    private static array $slots = [];

    /**
     * Reverse index: logical `Idempotency-Key` → list of slot
     * keys that share it. Used to detect "same key, different
     * method/path" conflicts in O(1) instead of scanning the
     * full slot map.
     *
     * @var array<string, list<string>>
     */
    private static array $keysToSlots = [];

    public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry
    {
        $slotKey = $this->slotKey($key, $method, $path);
        $slot = self::$slots[$slotKey] ?? null;

        if ($slot === null || $slot['entry'] === null) {
            // Slot missing OR reserved-but-not-yet-put — but
            // the logical key may be in use under a different
            // (method, path) pair. If so, it is a conflict
            // (per RFC 7231 §4.2.2: an Idempotency-Key
            // identifies a single logical request).
            $otherSlots = self::$keysToSlots[$key] ?? [];
            foreach ($otherSlots as $otherSlotKey) {
                if ($otherSlotKey === $slotKey) {
                    continue;
                }
                $other = self::$slots[$otherSlotKey] ?? null;
                if ($other === null || $other['entry'] === null) {
                    continue;
                }
                throw new IdempotencyConflictException(
                    "Idempotency-Key '{$key}' was previously used with {$other['method']} {$other['path']}, "
                    . "not {$method} {$path}",
                );
            }
            return null;
        }

        if ($slot['bodyHash'] !== $bodyHash) {
            throw new IdempotencyConflictException(
                "Idempotency-Key '{$key}' was previously used with a different request body",
            );
        }
        return $slot['entry'];
    }

    public function put(
        string $key,
        string $method,
        string $path,
        string $bodyHash,
        IdempotencyEntry $entry,
    ): void {
        $slotKey = $this->slotKey($key, $method, $path);
        self::$slots[$slotKey] = [
            'method' => $method,
            'path' => $path,
            'bodyHash' => $bodyHash,
            'entry' => $entry,
            'reserved' => false,
        ];
        self::$keysToSlots[$key][] = $slotKey;
    }

    public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool
    {
        $slotKey = $this->slotKey($key, $method, $path);
        $existing = self::$slots[$slotKey] ?? null;

        if ($existing === null) {
            self::$slots[$slotKey] = [
                'method' => $method,
                'path' => $path,
                'bodyHash' => $bodyHash,
                'entry' => null,
                'reserved' => true,
            ];
            return true;
        }

        // Different request shape — let the middleware see the
        // conflict via get() rather than racing the handler.
        if ($existing['bodyHash'] !== $bodyHash) {
            return true;
        }

        // Same request shape — if a concurrent request is in
        // flight, refuse (409 Conflict via the middleware).
        if ($existing['reserved']) {
            return false;
        }

        // A stored entry exists; the caller is going to replay,
        // not re-execute. Re-marking reserved here is a no-op
        // because the middleware short-circuits in get() before
        // reaching put(). We return true (we "won") for
        // symmetry, but the slot is already fully populated.
        return true;
    }

    public function sweep(int $olderThanSeconds): int
    {
        $now = time();
        $removed = 0;
        foreach (self::$slots as $slotKey => $slot) {
            if ($slot['entry'] !== null && ($now - $slot['entry']->createdAt) >= $olderThanSeconds) {
                unset(self::$slots[$slotKey]);
                $removed++;
            }
        }
        return $removed;
    }

    private function slotKey(string $key, string $method, string $path): string
    {
        return $method . ':' . $path . ':' . $key;
    }

    /**
     * Reset the in-memory store. Test-only hook; production
     * code never calls this (the store is supposed to live
     * for the lifetime of the PHP process).
     */
    public static function reset(): void
    {
        self::$slots = [];
        self::$keysToSlots = [];
    }
}
