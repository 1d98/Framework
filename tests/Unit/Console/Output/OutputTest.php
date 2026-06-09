<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Output;

use Framework\Console\Output\Output;
use Framework\Tests\Support\MemoryStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Output::class)]
final class OutputTest extends TestCase
{
    private string|false $previousNoColor;

    protected function setUp(): void
    {
        $this->previousNoColor = getenv('NO_COLOR');
    }

    protected function tearDown(): void
    {
        if ($this->previousNoColor === false) {
            putenv('NO_COLOR');
        } else {
            putenv('NO_COLOR=' . $this->previousNoColor);
        }
    }

    public function testWriteGoesToStdout(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $out = new Output($stdout, $stderr);
        $out->write('hello');
        self::assertSame("hello\n", MemoryStream::contents($stdout));
    }

    public function testWriteWithoutNewline(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $out = new Output($stdout, $stderr);
        $out->write('hello', false);
        self::assertSame('hello', MemoryStream::contents($stdout));
    }

    public function testErrorGoesToStderr(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $out = new Output($stdout, $stderr);
        $out->error('boom');
        self::assertSame("boom\n", MemoryStream::contents($stderr));
    }

    public function testSuccessEmitsGreenCheckmarkWhenAnsiEnabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: true);
        $out->success('done');
        self::assertSame("\033[32m✓ done\033[0m\n", MemoryStream::contents($stdout));
    }

    public function testInfoEmitsBlueIconWhenAnsiEnabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: true);
        $out->info('note');
        self::assertSame("\033[34mℹ note\033[0m\n", MemoryStream::contents($stdout));
    }

    public function testWarningEmitsYellowExclamationWhenAnsiEnabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: true);
        $out->warning('caution');
        self::assertSame("\033[33m! caution\033[0m\n", MemoryStream::contents($stdout));
    }

    public function testDangerEmitsRedCrossWhenAnsiEnabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: true);
        $out->danger('fail');
        self::assertSame("\033[31m✗ fail\033[0m\n", MemoryStream::contents($stdout));
    }

    public function testSuccessEmitsPlainTextWhenAnsiDisabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: false);
        $out->success('done');
        self::assertSame("✓ done\n", MemoryStream::contents($stdout));
    }

    public function testInfoEmitsPlainTextWhenAnsiDisabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: false);
        $out->info('note');
        self::assertSame("ℹ note\n", MemoryStream::contents($stdout));
    }

    public function testWarningEmitsPlainTextWhenAnsiDisabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: false);
        $out->warning('caution');
        self::assertSame("! caution\n", MemoryStream::contents($stdout));
    }

    public function testDangerEmitsPlainTextWhenAnsiDisabled(): void
    {
        [$out, $stdout] = $this->make(useAnsi: false);
        $out->danger('fail');
        self::assertSame("✗ fail\n", MemoryStream::contents($stdout));
    }

    public function testWithAnsiReturnsNewInstanceAndKeepsOriginal(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $off = new Output($stdout, $stderr, useAnsi: false);
        $on = $off->withAnsi(true);

        self::assertFalse($off->usesAnsi());
        self::assertTrue($on->usesAnsi());
        self::assertNotSame($off, $on);
        self::assertSame($stdout, $on->stdout());
        self::assertSame($stderr, $on->stderr());
    }

    public function testNoColorEnvDisablesAnsiOnTtyLikeStream(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        putenv('NO_COLOR=1');
        $out = new Output($stdout, $stderr);
        self::assertFalse($out->usesAnsi());
        $out->success('done');
        self::assertSame("✓ done\n", MemoryStream::contents($stdout));
    }

    public function testNoColorEnvSetToZeroDisablesAnsiPerSpec(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        putenv('NO_COLOR=0');
        $out = new Output($stdout, $stderr);
        self::assertFalse($out->usesAnsi());
    }

    public function testNoColorEnvSetToEmptyStringKeepsAnsiPerSpec(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        putenv('NO_COLOR=');
        $out = new Output($stdout, $stderr, useAnsi: true);
        self::assertTrue($out->usesAnsi());
        $out->success('done');
        self::assertSame("\033[32m✓ done\033[0m\n", MemoryStream::contents($stdout));
    }

    public function testNoColorEnvUnsetFallsBackToTtyDetection(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        putenv('NO_COLOR');
        $out = new Output($stdout, $stderr);
        self::assertFalse($out->usesAnsi(), 'memory stream is not a TTY');
    }

    public function testExplicitTrueOverridesNoColorEnv(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        putenv('NO_COLOR=1');
        $out = new Output($stdout, $stderr, useAnsi: true);
        self::assertTrue($out->usesAnsi());
    }

    public function testMemoryStreamIsNotTtySoAnsiDefaultsToOff(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $out = new Output($stdout, $stderr);
        self::assertFalse($out->usesAnsi());
    }

    public function testTableWithHeadersAndRows(): void
    {
        [$out, $stdout] = $this->make();
        $rows = [['1', '2'], ['3', '4']];
        $out->table(['A', 'B'], $rows);
        $expected = "+---+---+\n"
            . "| A | B |\n"
            . "+---+---+\n"
            . "| 1 | 2 |\n"
            . "| 3 | 4 |\n";
        self::assertSame($expected, MemoryStream::contents($stdout));
    }

    public function testTableWithVariableColumnWidths(): void
    {
        [$out, $stdout] = $this->make();
        $rows = [['app', 'framework'], ['version', '0.4.0']];
        $out->table(['Key', 'Value'], $rows);
        $output = MemoryStream::contents($stdout);
        self::assertStringContainsString('| Key     | Value     |', $output);
        self::assertStringContainsString('| app     | framework |', $output);
        self::assertStringContainsString('| version | 0.4.0     |', $output);
    }

    public function testTableWithEmptyRows(): void
    {
        [$out, $stdout] = $this->make();
        $out->table(['A', 'B'], []);
        $expected = "+---+---+\n"
            . "| A | B |\n"
            . "+---+---+\n";
        self::assertSame($expected, MemoryStream::contents($stdout));
    }

    public function testTableWithMismatchedColumnCount(): void
    {
        [$out, $stdout] = $this->make();
        $rows = [['1', '2']];
        $out->table(['A', 'B', 'C'], $rows);
        $output = MemoryStream::contents($stdout);
        self::assertStringContainsString('| 1 | 2 |   |', $output);
    }

    public function testTableWithNonScalarCellsCastsToString(): void
    {
        [$out, $stdout] = $this->make();
        $obj = new class () {
            public function __toString(): string
            {
                return 'obj';
            }
        };
        $out->table(['A'], [[$obj]]);
        self::assertStringContainsString('| obj |', MemoryStream::contents($stdout));
    }

    public function testStdoutStderrAccessorsReturnResources(): void
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $out = new Output($stdout, $stderr);
        self::assertSame($stdout, $out->stdout());
        self::assertSame($stderr, $out->stderr());
    }

    /**
     * @return array{0: Output, 1: resource}
     */
    private function make(?bool $useAnsi = null): array
    {
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        return [new Output($stdout, $stderr, $useAnsi), $stdout];
    }
}
