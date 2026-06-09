<?php

declare(strict_types=1);

namespace Framework\Tests\Unit;

use Framework\Logging\StreamLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StreamLogger::class)]
final class StreamLoggerHardeningTest extends TestCase
{
    /** @var resource */
    private $stream;

    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'log_h_');
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

    private function contents(): string
    {
        $contents = file_get_contents($this->logFile);
        self::assertIsString($contents);
        return $contents;
    }

    public function testAcceptsStringPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'log_path_');
        self::assertIsString($path);

        try {
            $logger = new StreamLogger($path);
            $logger->info('via path');
            self::assertFileExists($path);
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('INFO via path', $contents);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testStringPathInvalidThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $dir = sys_get_temp_dir();
        $existingDir = $dir . '/stream_logger_test_dir_' . uniqid();
        mkdir($existingDir);
        try {
            $previous = error_reporting(0);
            try {
                new StreamLogger($existingDir);
            } finally {
                error_reporting($previous);
            }
        } finally {
            rmdir($existingDir);
        }
    }

    public function testInvalidMinLevelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentional invalid level for test */
        new StreamLogger($this->stream, 'verbose');
    }

    public function testLevelFilterDropsBelowThreshold(): void
    {
        $logger = new StreamLogger($this->stream, 'warning');
        $logger->debug('drop-debug');
        $logger->info('drop-info');
        $logger->warning('keep-warning');
        $logger->error('keep-error');

        $contents = $this->contents();
        self::assertStringNotContainsString('drop-debug', $contents);
        self::assertStringNotContainsString('drop-info', $contents);
        self::assertStringContainsString('] WARNING keep-warning', $contents);
        self::assertStringContainsString('] ERROR keep-error', $contents);
    }

    public function testErrorLevelFilterPassesOnlyError(): void
    {
        $logger = new StreamLogger($this->stream, 'error');
        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        $contents = $this->contents();
        self::assertStringNotContainsString('] DEBUG', $contents);
        self::assertStringNotContainsString('] INFO', $contents);
        self::assertStringNotContainsString('] WARNING', $contents);
        self::assertStringContainsString('] ERROR e', $contents);
    }

    public function testDefaultMinLevelIsDebug(): void
    {
        $logger = new StreamLogger($this->stream);
        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        $contents = $this->contents();
        self::assertStringContainsString('] DEBUG d', $contents);
        self::assertStringContainsString('] INFO i', $contents);
        self::assertStringContainsString('] WARNING w', $contents);
        self::assertStringContainsString('] ERROR e', $contents);
    }

    public function testWithMsTrueProducesMillisecondPrecision(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', true);
        $logger->info('ms-test');

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] INFO ms-test\n$/',
            $this->contents(),
        );
    }

    public function testWithMsFalseProducesSecondPrecision(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $logger->info('sec-test');

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO sec-test\n$/',
            $this->contents(),
        );
    }

    public function testMillisecondFractionHasExactlyThreeDigits(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', true);
        $logger->info('three-digits');

        self::assertMatchesRegularExpression(
            '/\d{2}:\d{2}:\d{2}\.\d{3}/',
            $this->contents(),
        );
    }

    public function testCircularReferenceInContextDoesNotCrash(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $context = ['self' => null];
        $context['self'] = &$context;

        $logger->info('circular', $context);

        $contents = $this->contents();
        self::assertStringContainsString('INFO circular', $contents);
        self::assertStringContainsString('"unencodable"', $contents);
    }

    public function testCircularContextMarkerUsesTypeNameNotRecursiveValue(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $context = ['self' => null];
        $context['self'] = &$context;

        $logger->info('circular', $context);

        $contents = $this->contents();
        self::assertStringContainsString('"unencodable":"array"', $contents);
    }

    public function testValidContextStillEncodesNormally(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $logger->info('valid', ['k' => 'v', 'n' => 1]);

        self::assertStringContainsString('INFO valid {"k":"v","n":1}', $this->contents());
    }

    public function testBackwardCompatSingleArgConstructor(): void
    {
        $logger = new StreamLogger($this->stream);
        $logger->debug('d');
        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');

        $contents = $this->contents();
        self::assertStringContainsString('] DEBUG d', $contents);
        self::assertStringContainsString('] INFO i', $contents);
        self::assertStringContainsString('] WARNING w', $contents);
        self::assertStringContainsString('] ERROR e', $contents);
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/',
            $contents,
        );
    }

    public function testResourceInContextDoesNotPropagateJsonException(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        $logger->info('res', ['handle' => $resource]);

        fclose($resource);

        $contents = $this->contents();
        self::assertStringContainsString('INFO res', $contents);
        self::assertStringContainsString('"unencodable":"array"', $contents);
    }

    public function testNanInContextDoesNotPropagateJsonException(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);

        $logger->warning('nan', ['value' => NAN]);

        $contents = $this->contents();
        self::assertStringContainsString('WARNING nan', $contents);
        self::assertStringContainsString('"unencodable":"array"', $contents);
    }

    public function testDeeplyNestedCircularReferenceFallsBackWithoutException(): void
    {
        $logger = new StreamLogger($this->stream, 'debug', false);
        $a = ['name' => 'a'];
        $b = ['name' => 'b', 'parent' => &$a];
        $a['child'] = &$b;

        $logger->warning('cycle', $a);

        $contents = $this->contents();
        self::assertStringContainsString('WARNING cycle', $contents);
        self::assertStringContainsString('"unencodable":"array"', $contents);
        self::assertStringNotContainsString('"name":"a"', $contents);
    }
}
