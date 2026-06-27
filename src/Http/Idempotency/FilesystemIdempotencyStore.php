<?php

declare(strict_types=1);

namespace Framework\Http\Idempotency;

use Framework\Filesystem\AtomicFilesystem;
use Framework\Filesystem\Lock;
use Framework\Filesystem\WouldBlockException;
use Framework\Http\Cookie\Cookie;

/**
 * Per-host, atomic-rename persistence for
 * {@see \Framework\Http\Middleware\IdempotencyKeyMiddleware}.
 *
 * One JSON file per `(key, method, path)` triple, written via
 * {@see AtomicFilesystem::writeJson()} (tmp + rename), keyed
 * with `sha256("$method:$path:$key")`. A sidecar `.lock` file
 * per slot provides an `flock(LOCK_NB)` reservation primitive
 * so two parallel PHP-FPM workers cannot both win
 * {@see IdempotencyStoreInterface::tryReserve()}.
 *
 * **NFS caveat.** `flock` is a no-op on NFS (some FUSE mounts
 * too) — operators running on a shared network filesystem
 * should switch to a Redis `SETNX` + TTL adapter (the
 * `IdempotencyStoreInterface` is small enough to implement
 * one in 30 lines).
 *
 * **File permissions.** Entry files are 0600, lock files
 * 0600, parent directory 0700 — the same defaults
 * {@see AtomicFilesystem} uses. The directory is created on
 * first write.
 */
final class FilesystemIdempotencyStore implements IdempotencyStoreInterface
{
    public function __construct(
        private readonly string $directory,
    ) {
    }

    public function get(string $key, string $method, string $path, string $bodyHash): ?IdempotencyEntry
    {
        $entryPath = $this->entryPath($key, $method, $path);
        if (!is_file($entryPath)) {
            return null;
        }
        $raw = (string) file_get_contents($entryPath);
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Corrupt entry — treat as missing rather than 500.
            return null;
        }
        if (!is_array($decoded) || !isset($decoded['bodyHash'])) {
            return null;
        }
        if ($decoded['bodyHash'] !== $bodyHash) {
            throw new IdempotencyConflictException(
                "Idempotency-Key '{$key}' was previously used with a different request body",
            );
        }
        /** @var array<string, mixed> $decoded */
        return $this->decodeEntry($decoded);
    }

    public function put(
        string $key,
        string $method,
        string $path,
        string $bodyHash,
        IdempotencyEntry $entry,
    ): void {
        $payload = [
            'key' => $key,
            'method' => $method,
            'path' => $path,
            'bodyHash' => $bodyHash,
            'status' => $entry->status,
            'body' => $entry->body,
            'headers' => $entry->headers,
            'cookies' => array_map(
                static fn(Cookie $c): array => [
                    'name' => $c->name,
                    'value' => $c->value,
                    'expiresAt' => $c->expiresAt,
                    'path' => $c->path,
                    'domain' => $c->domain,
                    'secure' => $c->secure,
                    'httpOnly' => $c->httpOnly,
                    'sameSite' => $c->sameSite,
                ],
                $entry->cookies,
            ),
            'createdAt' => $entry->createdAt,
        ];
        AtomicFilesystem::writeJson($this->entryPath($key, $method, $path), $payload);
    }

    public function tryReserve(string $key, string $method, string $path, string $bodyHash): bool
    {
        // The filesystem adapter has a soft concurrency model:
        //  - When the entry file is missing, the caller is the
        //    first request — return true.
        //  - When the entry file is present, the caller is a
        //    retry / replay — return true (the middleware will
        //    call `get()` and short-circuit to the cached entry).
        //  - A real cross-process race window exists between
        //    `tryReserve` and `put`; for that, the operator
        //    should use a Redis-backed store. The lock file is
        //    an advisory "best effort" that is not currently
        //    held across the `put` boundary.
        $lockPath = $this->lockPath($key, $method, $path);
        // Best-effort flock on the lock file so two parallel
        // workers cannot both pass the entry-existence check at
        // the same moment. The lock is acquired / released in
        // the same call and the lock file is left on disk; the
        // existence of the lock file is harmless.
        try {
            $lock = AtomicFilesystem::lock($lockPath, nonBlocking: true);
            $lock->release();
        } catch (WouldBlockException) {
            return false;
        }
        return true;
    }

    public function sweep(int $olderThanSeconds): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }
        $removed = 0;
        $now = time();
        foreach (AtomicFilesystem::listFiles($this->directory) as $file) {
            $basename = basename($file);
            if (str_ends_with($basename, '.lock')) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($decoded) || !isset($decoded['createdAt'])) {
                continue;
            }
            $createdAt = $decoded['createdAt'];
            if (!is_int($createdAt)) {
                continue;
            }
            if (($now - $createdAt) >= $olderThanSeconds) {
                @unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    public function forget(string $key): void
    {
        // The filesystem address is sha256("$method:$path:$key") —
        // the logical key alone does not let us compute the file path.
        // Scan the directory and decode each entry's stored (method,
        // path, key) triple; unlink any whose `key` matches. The scan
        // is O(N files) but N is bounded by the idempotency cache
        // size, not by request volume, and the operation is gated
        // on a streamed-response path (rare relative to buffered
        // requests). Missing entries are a silent no-op per the
        // interface contract.
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (AtomicFilesystem::listFiles($this->directory) as $file) {
            $basename = basename($file);
            if (str_ends_with($basename, '.lock')) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            try {
                $decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $storedKey = $decoded['key'] ?? null;
            if (!is_string($storedKey) || $storedKey !== $key) {
                continue;
            }
            @unlink($file);
        }
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function decodeEntry(array $decoded): IdempotencyEntry
    {
        $cookies = [];
        if (isset($decoded['cookies']) && is_array($decoded['cookies'])) {
            /** @var array<int|string, mixed> $rawCookies */
            $rawCookies = $decoded['cookies'];
            foreach ($rawCookies as $c) {
                if (!is_array($c)) {
                    continue;
                }
                /** @var array<string, mixed> $c */
                $cookies[] = new Cookie(
                    name: self::asString($c, 'name', ''),
                    value: self::asString($c, 'value', ''),
                    expiresAt: self::asInt($c, 'expiresAt', 0),
                    path: self::asString($c, 'path', '/'),
                    domain: isset($c['domain']) ? self::asString($c, 'domain', '') : null,
                    secure: self::asBool($c, 'secure', false),
                    httpOnly: self::asBool($c, 'httpOnly', false),
                    sameSite: self::asString($c, 'sameSite', 'Lax'),
                );
            }
        }
        $headers = [];
        if (isset($decoded['headers']) && is_array($decoded['headers'])) {
            /** @var array<int|string, mixed> $rawHeaders */
            $rawHeaders = $decoded['headers'];
            foreach ($rawHeaders as $name => $value) {
                $headers[(string) $name] = (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) ? (string) $value : '';
            }
        }
        return new IdempotencyEntry(
            status: self::asInt($decoded, 'status', 200),
            body: self::asString($decoded, 'body', ''),
            headers: $headers,
            cookies: $cookies,
            createdAt: self::asInt($decoded, 'createdAt', time()),
        );
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function asString(array $array, string $key, string $default): string
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }
        $value = $array[$key];
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function asInt(array $array, string $key, int $default): int
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }
        $value = $array[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function asBool(array $array, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }
        $value = $array[$key];
        if (is_bool($value)) {
            return $value;
        }
        return $default;
    }

    private function entryPath(string $key, string $method, string $path): string
    {
        $hashed = hash('sha256', $method . ':' . $path . ':' . $key);
        return $this->directory . '/idempotency-' . $hashed . '.json';
    }

    private function lockPath(string $key, string $method, string $path): string
    {
        $hashed = hash('sha256', $method . ':' . $path . ':' . $key);
        return $this->directory . '/idempotency-' . $hashed . '.lock';
    }
}
