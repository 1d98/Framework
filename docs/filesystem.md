# Atomic filesystem primitives

What this is: two small, dependency-free filesystem helpers — atomic write (`tmp` + `rename`) and exclusive `flock` — extracted from `StreamLogger` and `TempFilePool` so cache, idempotency, and job-queue code can reuse them. Both ship in `src/Filesystem/AtomicFilesystem.php`.

## Why these exist

Most "batteries-included" skeletons need a small cache, a session, a JSON-on-disk config, or an idempotency store. All of them require two primitives that are easy to get wrong:

1. **Atomic write.** A reader must never see a half-written file. The standard pattern is `tmp` + `fwrite` + `fflush` + `rename`: write the new content to `path . '.tmp.<random>'`, flush, then `rename()` over the target. On POSIX filesystems `rename()` is atomic, so a concurrent reader sees either the old file or the new one, never a mix.

2. **Exclusive `flock`.** Two workers updating the same file must not trample each other. `flock(LOCK_EX)` provides this on local filesystems; on NFS / FUSE it may silently fail (see "Caveats" below).

`AtomicFilesystem` provides both with security defaults (mode 0600 for files, 0700 for directories) and an explicit `assertSafePath` (rejects empty paths, NUL bytes, and over-long paths).

## Usage

### Atomic write

```php
use Framework\Filesystem\AtomicFilesystem;

AtomicFilesystem::write(
    path: __DIR__ . '/../var/config.json',
    contents: $json,
    mode: 0o600,
    dirMode: 0o700,
);

AtomicFilesystem::writeJson(
    path: __DIR__ . '/../var/state.json',
    data: ['counter' => 42, 'ts' => time()],
    flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
);
```

The `writeJson` variant runs `json_encode` with `JSON_THROW_ON_ERROR` and surfaces a `JsonException` as `AtomicFilesystemException` *before* the file is touched — a malformed payload never leaves a half-written file behind.

On failure, the `path . '.tmp.<random>'` file is removed in `finally`. The destination file is either unchanged (failure) or fully updated (success); there is no third state.

### Advisory lock

```php
use Framework\Filesystem\AtomicFilesystem;
use Framework\Filesystem\WouldBlockException;

$lock = AtomicFilesystem::lock(__DIR__ . '/../var/work.lock');
try {
    $current = json_decode((string) file_get_contents($path), true);
    $current['counter'] = ($current['counter'] ?? 0) + 1;
    AtomicFilesystem::writeJson($path, $current);
} finally {
    $lock->release();
}
```

`release()` is idempotent — a second call is a no-op and never throws. The destructor calls `release()` as a safety net, but always pair with `finally` for clarity.

For "try once and skip if contended" patterns (the 429 / "back off" pattern):

```php
try {
    $lock = AtomicFilesystem::lock($path, nonBlocking: true);
} catch (WouldBlockException) {
    return Response::json(['error' => 'busy'], 429);
}
```

### Listing and removing trees

```php
// Lazy iteration over a directory tree (yields paths, not file contents)
foreach (AtomicFilesystem::listFiles($dir) as $file) {
    // ...
}

// Recursive delete (no-op if the dir is missing)
AtomicFilesystem::removeTree($dir);
```

## Path validation

`AtomicFilesystem::write()` and `::lock()` reject:

- Empty paths
- Paths longer than 4096 characters
- Paths containing NUL bytes (which are a class of filesystem-injection attack: `fopen` truncates at the first NUL)

Path traversal via `..` segments is **not** validated — the helper is a primitive, not a sandbox. If you need a "writes stay under `/var/data`" guarantee, `realpath` the input first and check that the resolved result is under your trusted root.

## Caveats

### NFS and FUSE

`flock` is advisory, not mandatory. Processes that do not take the lock are not blocked — the lock is a convention between cooperating writers.

Worse, NFS and some FUSE filesystems **silently ignore `flock`** (return `false` instead of blocking). The blocking variant of `AtomicFilesystem::lock()` throws `AtomicFilesystemException` in that case; the non-blocking variant throws `WouldBlockException`. Operators must verify the deployment filesystem before relying on cross-host coordination — for multi-instance deployments, use Redis (`SETNX` with TTL) or a real database lock.

### Windows

- `rename()` on Windows is **not** atomic in the POSIX sense: a reader may briefly see a partial file during the replace. The skeleton accepts this trade-off; if you need POSIX-grade atomicity, run on a POSIX filesystem.
- `chmod` is a no-op on Windows. The `mode` and `dirMode` arguments are accepted but ignored; the OS default permissions apply.
- `LOCK_NB` semantics differ on Windows. The PHP `flock` wrapper normalizes this; the skeleton does not need special handling.

### Mode bits

`mode: 0o600` makes the file owner read/write only — the right default for cache and state files that may contain user data. `dirMode: 0o700` makes the parent directory owner-only. Tighten further (`0o400` for read-only, etc.) by passing the value explicitly.

## What this is NOT

- **Not a key-value store.** No `get` / `put` / `delete` / `list-keys` API. The Idempotency store (`src/Http/Idempotency/`) and a future Cache package build on top of `AtomicFilesystem`, but neither assumes the other exists.
- **Not a database.** No transactions, no concurrency control beyond `flock`, no crash recovery. For "I need a real database", reach for SQLite/PostgreSQL/MySQL.
- **Not a sandbox.** Path validation is minimal; symlinks are not resolved; `..` segments are not rejected. Use it with absolute paths inside a directory you control.
- **Not a drop-in for `file_put_contents`.** The atomic write is meaningfully slower (it allocates a tmp file, syncs, and renames). For "write a log line" hot paths, use `fwrite` directly; for "write a config that the next request will read", use `AtomicFilesystem::write`.
