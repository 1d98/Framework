<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command;

use Framework\Console\Command\AppSecretCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MemoryOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppSecretCommand::class)]
final class AppSecretCommandTest extends TestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new AppSecretCommand(new Container());
        self::assertSame('app:secret', $cmd->name());
        self::assertSame('Generate a cryptographically secure application secret', $cmd->description());
    }

    public function testExecuteGeneratesHexSecretOfDefault32Bytes(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(), $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\n$/', $written);
    }

    public function testExecuteRespectsBytesOption(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = new MemoryOutput();

        $input = new Input(options: ['bytes' => '16']);
        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\n$/', $written);
    }

    public function testExecuteRespectsBytes128(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = new MemoryOutput();

        $input = new Input(options: ['bytes' => '128']);
        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $written = $output->stdoutText();
        self::assertMatchesRegularExpression('/^[0-9a-f]{256}\n$/', $written);
    }

    public function testExecuteReturnsOneWhenBytesTooLow(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = new MemoryOutput();

        $input = new Input(options: ['bytes' => '8']);
        $code = $cmd->execute($input, $output);

        self::assertSame(1, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('Bytes must be between 16 and 128', $written);
    }

    public function testExecuteReturnsOneWhenBytesTooHigh(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = new MemoryOutput();

        $input = new Input(options: ['bytes' => '200']);
        $code = $cmd->execute($input, $output);

        self::assertSame(1, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('Bytes must be between 16 and 128', $written);
    }

    public function testDangerMessageKeepsAnsiWhenAnsiEnabled(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output = (new MemoryOutput())->withAnsi(true);

        $input = new Input(options: ['bytes' => '8']);
        $code = $cmd->execute($input, $output);

        self::assertSame(1, $code);
        $written = $output->stdoutText();
        self::assertStringContainsString('Bytes must be between 16 and 128', $written);
        self::assertStringContainsString("\033[31m", $written);
    }

    public function testEachInvocationProducesUniqueSecret(): void
    {
        $cmd = new AppSecretCommand(new Container());
        $output1 = new MemoryOutput();
        $cmd->execute(new Input(), $output1);
        $secret1 = trim($output1->stdoutText());

        $output2 = new MemoryOutput();
        $cmd->execute(new Input(), $output2);
        $secret2 = trim($output2->stdoutText());

        self::assertNotSame($secret1, $secret2);
    }
}
