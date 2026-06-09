<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseBenchTest extends TestCase
{
    #[Group('bench')]
    public function testWithHeaderChainCompletesInReasonableTime(): void
    {
        $iterations = 10_000;
        $response = Response::text('hi');

        gc_collect_cycles();
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $response = $response->withHeader("X-H-{$i}", "v{$i}");
        }
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf(
            "\n[bench] %d chained withHeader() calls: %.2f ms\n",
            $iterations,
            $elapsedMs,
        ));

        self::assertGreaterThanOrEqual($iterations, count($response->headers));
        self::assertLessThan(
            2000.0,
            $elapsedMs,
            sprintf('10k withHeader() chain took %.2f ms (expected < 2000 ms)', $elapsedMs),
        );
    }

    #[Group('bench')]
    public function testWithCookieChainCompletesInReasonableTime(): void
    {
        $iterations = 10_000;
        $response = Response::text('hi');

        gc_collect_cycles();
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $response = $response->withCookie(new Cookie(name: "c{$i}", value: 'v'));
        }
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf(
            "\n[bench] %d chained withCookie() calls: %.2f ms\n",
            $iterations,
            $elapsedMs,
        ));

        self::assertCount($iterations, $response->cookies());
        self::assertLessThan(
            2000.0,
            $elapsedMs,
            sprintf('10k withCookie() chain took %.2f ms (expected < 2000 ms)', $elapsedMs),
        );
    }
}
