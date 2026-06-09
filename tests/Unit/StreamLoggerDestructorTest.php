<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Logging\StreamLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamLogger::class)]
final class StreamLoggerDestructorTest extends TestCase
{
    private int $before = 0;

    protected function setUp(): void
    {
        $this->before = $this->countStreams();
    }

    public function testStringPathClosesOwnedFileHandleOnUnset(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log_destruct_');
        self::assertIsString($path);
        unlink($path);
        self::assertFileDoesNotExist($path);

        $logger = new StreamLogger($path);
        $logger->info('leaky');

        self::assertFileExists($path);

        $duringOwned = $this->countStreams();
        self::assertGreaterThan($this->before, $duringOwned, 'Logger should hold an open stream while alive');

        unset($logger);

        $afterUnset = $this->countStreams();
        self::assertSame(
            $this->before,
            $afterUnset,
            'Owned file handle must be closed when the logger is destroyed',
        );

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringContainsString('INFO leaky', $contents);

        unlink($path);
    }

    public function testStringPathCanBeReopenedAfterDestructor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log_reopen_');
        self::assertIsString($path);
        unlink($path);

        $logger = new StreamLogger($path);
        $logger->info('first');
        unset($logger);

        $reopened = fopen($path, 'a');
        self::assertNotFalse($reopened, 'Path must remain valid after destructor closes the FD');
        fwrite($reopened, "second\n");
        fclose($reopened);

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringContainsString('INFO first', $contents);
        self::assertStringContainsString('second', $contents);

        unlink($path);
    }

    public function testPassedInResourceIsNotClosedByDestructor(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        $logger = new StreamLogger($stream);
        $logger->info('borrowed');
        unset($logger);

        self::assertTrue(is_resource($stream), 'Caller-owned resource must stay open after logger is destroyed');

        rewind($stream);
        $data = stream_get_contents($stream);
        self::assertIsString($data);
        self::assertStringContainsString('INFO borrowed', $data);

        fwrite($stream, "still-usable\n");
        rewind($stream);
        $readBack = stream_get_contents($stream);
        self::assertIsString($readBack);
        self::assertStringContainsString('INFO borrowed', $readBack);
        self::assertStringContainsString('still-usable', $readBack);

        fclose($stream);
    }

    public function testBorrowedResourceSurvivesLoggerDestruct(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log_borrow_');
        self::assertIsString($path);
        file_put_contents($path, "caller-marker\n");

        $caller = fopen($path, 'a+');
        self::assertNotFalse($caller);

        $logger = new StreamLogger($caller);
        $logger->warning('via-borrowed');
        unset($logger);

        self::assertTrue(is_resource($caller));

        rewind($caller);
        $contents = stream_get_contents($caller);
        self::assertIsString($contents);
        self::assertStringContainsString('caller-marker', $contents);
        self::assertStringContainsString('WARNING via-borrowed', $contents);

        fclose($caller);
        unlink($path);
    }

    /**
     * @return int Count of currently-open resources of type "stream".
     */
    private function countStreams(): int
    {
        $resources = get_resources();
        $count = 0;
        foreach ($resources as $resource) {
            if (get_resource_type($resource) === 'stream') {
                $count++;
            }
        }
        return $count;
    }
}
