<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console;

use Framework\Console\ArgvParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArgvParser::class)]
final class ArgvParserTest extends TestCase
{
    public function testLongBooleanFlagsDoNotConsumeNextArgv(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--no-ansi', '--verbose']);

        self::assertTrue($input->flag('no-ansi'));
        self::assertTrue($input->flag('verbose'));
        self::assertNull($input->option('no-ansi'));
        self::assertNull($input->option('verbose'));
    }

    public function testLongOptionWithSpaceConsumesNextArgv(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--file', '/tmp/log']);

        self::assertSame('/tmp/log', $input->option('file'));
    }

    public function testLongOptionDoesNotConsumeNextLongFlag(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--file', '--verbose']);

        self::assertTrue($input->flag('file'));
        self::assertTrue($input->flag('verbose'));
        self::assertNull($input->option('file'));
    }

    public function testLongOptionConsumesValueThatLooksLikeNegativeNumber(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--bytes', '-1']);

        self::assertSame('-1', $input->option('bytes'));
    }

    public function testShortBooleanFlagDoesNotConsumeNextArgv(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '-v', '-h']);

        self::assertTrue($input->flag('v'));
        self::assertTrue($input->flag('h'));
        self::assertNull($input->option('v'));
        self::assertNull($input->option('h'));
    }

    public function testShortNonBooleanOptionConsumesNextArgv(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '-k', 'value']);

        self::assertSame('value', $input->option('k'));
    }

    public function testShortFlagAtEndOfArgvIsBoolean(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '-x']);

        self::assertTrue($input->flag('x'));
    }

    public function testShortAttachedValueTreatedAsOptionValue(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '-xvalue']);

        self::assertSame('value', $input->option('x'));
    }

    public function testLongOptionWithEqualsSign(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--key=value']);

        self::assertSame('value', $input->option('key'));
        self::assertFalse($input->flag('key'));
    }

    public function testLongFlagWithoutValueIsBoolean(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--verbose']);

        self::assertTrue($input->flag('verbose'));
    }

    public function testPositionalArgsArePreserved(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', 'arg1', 'arg2']);

        self::assertSame('cmd', $input->command());
        self::assertSame('arg1', $input->arg(1));
        self::assertSame('arg2', $input->arg(2));
    }

    public function testHelpAndVersionBooleanFlags(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '--help', '--version']);

        self::assertTrue($input->flag('help'));
        self::assertTrue($input->flag('version'));
    }

    public function testMixedBooleanAndValueOptions(): void
    {
        $input = ArgvParser::parse([
            'script',
            'cmd',
            '--no-ansi',
            '--file',
            '/tmp/log',
            '--verbose',
        ]);

        self::assertTrue($input->flag('no-ansi'));
        self::assertTrue($input->flag('verbose'));
        self::assertSame('/tmp/log', $input->option('file'));
    }

    public function testShortFlagBeforeLongOptionDoesNotGetConsumed(): void
    {
        $input = ArgvParser::parse(['script', 'cmd', '-v', '--file', '/tmp/log']);

        self::assertTrue($input->flag('v'));
        self::assertSame('/tmp/log', $input->option('file'));
    }
}
