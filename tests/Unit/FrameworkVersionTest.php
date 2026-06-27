<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Framework;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Framework::class)]
final class FrameworkVersionTest extends TestCase
{
    public function testVersionStringMatchesRelease(): void
    {
        self::assertSame('0.6.3', Framework::VERSION);
    }

    public function testVersionTripleMatchesRelease(): void
    {
        self::assertSame([0, 6, 3], Framework::VERSION_TRIPLE);
    }

    public function testVersionStabilityIsPreOne(): void
    {
        self::assertSame('alpha', Framework::VERSION_STABILITY);
    }

    public function testVersionTripleSegmentsAgreeWithVersionString(): void
    {
        $segments = explode('.', Framework::VERSION);

        self::assertCount(3, $segments);
        self::assertSame((int) $segments[0], Framework::VERSION_TRIPLE[0]);
        self::assertSame((int) $segments[1], Framework::VERSION_TRIPLE[1]);
        self::assertSame((int) $segments[2], Framework::VERSION_TRIPLE[2]);
    }
}
