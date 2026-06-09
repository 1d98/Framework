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
}
