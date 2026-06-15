<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Idempotency;

use Framework\Http\Idempotency\IdempotencyConflictException;
use Framework\Http\Idempotency\IdempotencyEntry;
use Framework\Http\Idempotency\InMemoryIdempotencyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryIdempotencyStore::class)]
#[CoversClass(IdempotencyEntry::class)]
#[CoversClass(IdempotencyConflictException::class)]
final class InMemoryIdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    protected function tearDown(): void
    {
        InMemoryIdempotencyStore::reset();
    }

    public function testGetMissingReturnsNull(): void
    {
        $store = new InMemoryIdempotencyStore();
        self::assertNull($store->get('K', 'POST', '/x', 'hash'));
    }

    public function testPutThenGetRoundTrips(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(
            status: 201,
            body: '{"ok":true}',
            headers: ['Content-Type' => 'application/json'],
            cookies: [],
            createdAt: time(),
        ));

        $entry = $store->get('K', 'POST', '/x', 'hash');
        self::assertNotNull($entry);
        self::assertSame(201, $entry->status);
        self::assertSame('{"ok":true}', $entry->body);
    }

    public function testGetWithDifferentBodyThrowsConflict(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->put('K', 'POST', '/x', 'hash-A', new IdempotencyEntry(200, 'first', [], [], time()));

        $this->expectException(IdempotencyConflictException::class);
        $store->get('K', 'POST', '/x', 'hash-B');
    }

    public function testTryReserveNewKeyWins(): void
    {
        $store = new InMemoryIdempotencyStore();
        self::assertTrue($store->tryReserve('K', 'POST', '/x', 'hash'));
    }

    public function testSweepRemovesExpiredEntries(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));

        $removed = $store->sweep(0);
        self::assertSame(1, $removed);
    }

    public function testSweepKeepsRecentEntries(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->put('K', 'POST', '/x', 'hash', new IdempotencyEntry(200, 'b', [], [], time()));

        $removed = $store->sweep(3600);
        self::assertSame(0, $removed);
    }
}
