<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Logging\StreamLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamLogger::class)]
final class StreamLoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'log_');
        $stream = fopen($this->logFile, 'w');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testConstructorRejectsNonResource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentional invalid type for test */
        new StreamLogger(['not', 'a', 'resource', 'or', 'path']);
    }

    public function testInfoWritesFormattedLineWithTimestamp(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $logger->info('hello world');

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO hello world\n$/',
            $contents,
        );
    }

    public function testAllLevelsWrite(): void
    {
        $logger = new StreamLogger($this->stream);
        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('] DEBUG d', $contents);
        self::assertStringContainsString('] INFO i', $contents);
        self::assertStringContainsString('] WARNING w', $contents);
        self::assertStringContainsString('] ERROR e', $contents);
    }

    public function testContextIsJsonEncodedWhenPresent(): void
    {
        $logger = new StreamLogger($this->stream);
        $logger->info('event', ['user_id' => 42, 'ip' => '127.0.0.1']);

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('event {"user_id":42,"ip":"127.0.0.1"}', $contents);
    }

    public function testEmptyContextOmitsJsonPart(): void
    {
        $logger = new StreamLogger($this->stream);
        $logger->info('plain message');

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringEndsWith("INFO plain message\n", $contents);
    }

    public function testLocksWhenWithLockEnabled(): void
    {
        $logger = new StreamLogger($this->logFile, 'debug', true, true);
        $logger->info('locked');

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('INFO locked', $contents);
    }

    public function testWithLockDefaultIsTrueForFilesystemPaths(): void
    {
        $logger = new StreamLogger($this->logFile);
        $logger->info('default-locked');

        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        self::assertStringContainsString('INFO default-locked', $contents);
    }

    public function testWithLockDefaultIsFalseForStdoutStream(): void
    {
        $logger = new StreamLogger(\STDOUT);
        $ref = new \ReflectionProperty(StreamLogger::class, 'withLock');
        self::assertFalse($ref->getValue($logger));
    }

    public function testWithLockDefaultIsTrueForFilesystemPathsReflectively(): void
    {
        $logger = new StreamLogger($this->logFile);
        $ref = new \ReflectionProperty(StreamLogger::class, 'withLock');
        self::assertTrue($ref->getValue($logger));
    }

    public function testLockedWriteFallsBackToUnlockedWhenFlockRejected(): void
    {
        FlockFailingStream::register();
        try {
            $stream = fopen('flockfail://test', 'w');
            self::assertIsResource($stream);
            $logger = new StreamLogger($stream, 'debug', true, true);
            $logger->info('written despite flock rejection');

            $contents = FlockFailingStream::contents();
            self::assertStringContainsString('INFO written despite flock rejection', $contents);
        } finally {
            FlockFailingStream::unregister();
        }
    }
}

/**
 * Test-only stream wrapper whose `stream_lock` returns `false` to
 * simulate filesystems (NFS, FUSE) that do not support `flock`.
 * Drives the `StreamLogger::lockedWrite()` fail-soft branch —
 * the line is still written, just without inter-process mutual
 * exclusion.
 */
final class FlockFailingStream
{
    /** @var resource */
    public $context;
    private static string $buffer = '';
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        stream_wrapper_register('flockfail', self::class);
        self::$registered = true;
        self::$buffer = '';
    }

    public static function unregister(): void
    {
        if (!self::$registered) {
            return;
        }
        stream_wrapper_unregister('flockfail');
        self::$registered = false;
    }

    public static function contents(): string
    {
        return self::$buffer;
    }

    public function stream_open(string $path, string $mode, int $options, ?int &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$buffer .= $data;
        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_lock(int $operation): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }
}
