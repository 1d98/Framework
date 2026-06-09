<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Formatter;

use Framework\Console\Formatter\StackTraceFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StackTraceFormatter::class)]
final class StackTraceFormatterTest extends TestCase
{
    public function testSummaryStartsWithErrorPrefix(): void
    {
        $formatter = new StackTraceFormatter();
        $summary = $formatter->summary(new RuntimeException('kaboom'));
        self::assertSame('Error: RuntimeException: kaboom', $summary);
    }

    public function testFramesAreList(): void
    {
        $formatter = new StackTraceFormatter();
        $frames = $formatter->frames(new RuntimeException('kaboom'));
        self::assertNotEmpty($frames);
        foreach ($frames as $line) {
            self::assertMatchesRegularExpression('/^#\d+ /', $line);
        }
    }

    public function testShortenPathsUsesBasename(): void
    {
        $formatter = new StackTraceFormatter(shortenPaths: true);
        $frames = $formatter->frames(new RuntimeException('kaboom'));
        foreach ($frames as $line) {
            self::assertDoesNotMatchRegularExpression('/#\d+ \/.*:\d+/', $line);
        }
    }

    public function testFullPathsWhenShorteningDisabled(): void
    {
        $exception = new RuntimeException('kaboom');
        $frame = $exception->getTrace()[0] ?? null;
        if ($frame === null || !isset($frame['file']) || !str_contains($frame['file'], '/')) {
            self::markTestSkipped('Top frame has no absolute file path');
        }

        $formatter = new StackTraceFormatter(shortenPaths: false);
        $frames = $formatter->frames($exception);
        self::assertStringContainsString($frame['file'], implode("\n", $frames));
    }
}
