<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Idempotency;

use Framework\Filesystem\AtomicFilesystem;
use Framework\Http\Idempotency\FilesystemIdempotencyStore;
use Framework\Http\Idempotency\IdempotencyConflictException;
use Framework\Http\Idempotency\IdempotencyEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemIdempotencyStore::class)]
final class FilesystemIdempotencyStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fw-idem-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            AtomicFilesystem::removeTree($this->dir);
        }
    }

    public function testGetMissingReturnsNull(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        self::assertNull($store->get('K', 'POST', '/x', 'hash'));
    }

    public function testPutThenGetRoundTrips(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $entry = new IdempotencyEntry(201, '{"ok":true}', ['Content-Type' => 'application/json'], [], time());
        $store->put('K', 'POST', '/x', 'hash', $entry);

        $read = $store->get('K', 'POST', '/x', 'hash');
        self::assertNotNull($read);
        self::assertSame(201, $read->status);
        self::assertSame('{"ok":true}', $read->body);
        self::assertSame(['Content-Type' => 'application/json'], $read->headers);
    }

    public function testGetWithDifferentBodyThrowsConflict(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $store->put('K', 'POST', '/x', 'hash-A', new IdempotencyEntry(200, 'first', [], [], time()));

        $this->expectException(IdempotencyConflictException::class);
        $store->get('K', 'POST', '/x', 'hash-B');
    }

    public function testGetReadsBackCreatedAt(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $now = time();
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], $now));

        $read = $store->get('K', 'POST', '/x', 'hash');
        self::assertNotNull($read);
        self::assertSame($now, $read->createdAt);
    }

    public function testTryReserveOnMissingKeyWins(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        self::assertTrue($store->tryReserve('K', 'POST', '/x', 'hash'));
    }

    public function testTryReserveOnExistingEntryLoses(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));

        // Entry exists → caller is replaying, not racing. The
        // in-memory adapter returns true in this case; the
        // filesystem adapter mirrors that.
        self::assertTrue($store->tryReserve('K', 'POST', '/x', 'hash'));
    }

    public function testSweepRemovesExpiredEntries(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));

        // Use a 0-second TTL to force expiry
        $removed = $store->sweep(0);
        self::assertSame(1, $removed);
        self::assertNull($store->get('K', 'POST', '/x', 'hash'));
    }

    public function testSweepKeepsRecentEntries(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));

        $removed = $store->sweep(3600);
        self::assertSame(0, $removed);
        self::assertNotNull($store->get('K', 'POST', '/x', 'hash'));
    }

    public function testCorruptEntryIsTreatedAsMissing(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));
        // Find the entry file and overwrite it with garbage.
        $files = iterator_to_array(AtomicFilesystem::listFiles($this->dir), false);
        self::assertNotEmpty($files);
        file_put_contents($files[0], 'this is not json');

        self::assertNull($store->get('K', 'POST', '/x', 'hash'));
    }

    public function testGetBeforePutReturnsNullWhenEntryFileIsMissing(): void
    {
        $store = new FilesystemIdempotencyStore($this->dir);
        // The path through `get` on a key that has never been
        // stored must return null (not throw).
        self::assertNull($store->get('never-stored', 'POST', '/x', 'hash'));
    }
}
