<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Input;

use Framework\Console\Input\Input;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Input::class)]
final class InputTest extends TestCase
{
    public function testEmptyInputHasNoCommand(): void
    {
        $input = new Input();
        self::assertNull($input->command());
        self::assertSame([], $input->args);
        self::assertSame([], $input->options);
        self::assertSame([], $input->flags);
    }

    public function testArgReturnsFirstPositional(): void
    {
        $input = new Input(args: ['list', 'extra']);
        self::assertSame('list', $input->arg(0));
        self::assertSame('extra', $input->arg(1));
    }

    public function testArgReturnsDefaultWhenOutOfRange(): void
    {
        $input = new Input(args: ['list']);
        self::assertSame('fallback', $input->arg(99, 'fallback'));
        self::assertNull($input->arg(99));
    }

    public function testCommandReturnsFirstArg(): void
    {
        $input = new Input(args: ['config:show', '--key', 'value']);
        self::assertSame('config:show', $input->command());
    }

    public function testOptionReturnsLongOptionValue(): void
    {
        $input = new Input(options: ['bytes' => '64']);
        self::assertSame('64', $input->option('bytes'));
    }

    public function testOptionReturnsDefaultWhenMissing(): void
    {
        $input = new Input();
        self::assertNull($input->option('missing'));
        self::assertSame('default', $input->option('missing', 'default'));
    }

    public function testOptionFallsBackToShortOptions(): void
    {
        $input = new Input(shortOptions: ['k' => 'value']);
        self::assertSame('value', $input->option('k'));
    }

    public function testFlagReturnsTrueWhenSet(): void
    {
        $input = new Input(flags: ['verbose' => true]);
        self::assertTrue($input->flag('verbose'));
    }

    public function testFlagReturnsFalseWhenMissing(): void
    {
        $input = new Input();
        self::assertFalse($input->flag('verbose'));
    }

    public function testHasOptionTrueForLongOption(): void
    {
        $input = new Input(options: ['bytes' => '32']);
        self::assertTrue($input->hasOption('bytes'));
    }

    public function testHasOptionTrueForShortOption(): void
    {
        $input = new Input(shortOptions: ['k' => 'value']);
        self::assertTrue($input->hasOption('k'));
    }

    public function testHasOptionFalseWhenMissing(): void
    {
        $input = new Input();
        self::assertFalse($input->hasOption('missing'));
    }
}
