<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\TraceContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TraceContext::class)]
final class TraceContextTest extends TestCase
{
    public function testMintGeneratesValidHex(): void
    {
        $ctx = TraceContext::mint();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $ctx->traceId);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $ctx->spanId);
        self::assertSame(1, $ctx->flags);
    }

    public function testMintGeneratesUniqueTraceIds(): void
    {
        $a = TraceContext::mint();
        $b = TraceContext::mint();
        self::assertNotSame($a->traceId, $b->traceId);
    }

    public function testFromTraceparentHeaderParsesValid(): void
    {
        $ctx = TraceContext::fromTraceparentHeader('00-aaaabbbbccccddddeeeeffffaaaabbbb-1111222233334444-01');

        self::assertSame('aaaabbbbccccddddeeeeffffaaaabbbb', $ctx->traceId);
        self::assertSame('1111222233334444', $ctx->spanId);
        self::assertSame(1, $ctx->flags);
    }

    public function testFromTraceparentHeaderMintsOnNull(): void
    {
        $ctx = TraceContext::fromTraceparentHeader(null);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $ctx->traceId);
    }

    public function testFromTraceparentHeaderMintsOnEmpty(): void
    {
        $ctx = TraceContext::fromTraceparentHeader('   ');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $ctx->traceId);
    }

    public function testFromTraceparentHeaderMintsOnMalformed(): void
    {
        $ctx = TraceContext::fromTraceparentHeader('garbage');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $ctx->traceId);
    }

    public function testFromTraceparentHeaderMintsOnWrongLength(): void
    {
        $ctx = TraceContext::fromTraceparentHeader('00-aaaa-1111-01');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $ctx->traceId);
    }

    public function testToTraceparentFormat(): void
    {
        $ctx = new TraceContext(
            traceId: 'aaaabbbbccccddddeeeeffffaaaabbbb',
            spanId: '1111222233334444',
            flags: 1,
        );
        self::assertSame(
            '00-aaaabbbbccccddddeeeeffffaaaabbbb-1111222233334444-01',
            $ctx->toTraceparent(),
        );
    }

    public function testConstructorRejectsBadTraceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TraceContext(traceId: 'not-hex', spanId: '1111222233334444');
    }

    public function testConstructorRejectsShortTraceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TraceContext(traceId: str_repeat('a', 30), spanId: '1111222233334444');
    }

    public function testConstructorRejectsUppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TraceContext(
            traceId: strtoupper(bin2hex(random_bytes(16))),
            spanId: '1111222233334444',
        );
    }

    public function testConstructorRejectsBadSpanId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TraceContext(
            traceId: str_repeat('a', 32),
            spanId: 'not-hex',
        );
    }

    public function testConstructorRejectsFlagsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TraceContext(
            traceId: str_repeat('a', 32),
            spanId: str_repeat('b', 16),
            flags: 256,
        );
    }

    public function testToW3CHeadersContainsTraceparent(): void
    {
        $ctx = TraceContext::mint();
        $headers = $ctx->toW3CHeaders();

        self::assertArrayHasKey('traceparent', $headers);
        self::assertSame($ctx->toTraceparent(), $headers['traceparent']);
    }
}
