<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Console\Command\Make;

use Framework\Console\Command\Make\MakeRuleCommand;
use Framework\Console\Input\Input;
use Framework\Container\Container;
use Framework\Tests\Support\MakeScaffolderTestCase;
use Framework\Tests\Support\MemoryOutput;
use Framework\Tests\Support\PhpLinter;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MakeRuleCommand::class)]
final class MakeRuleCommandTest extends MakeScaffolderTestCase
{
    public function testNameAndDescription(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        self::assertSame('make:rule', $cmd->name());
        self::assertStringContainsString('rule', $cmd->description());
    }

    public function testGeneratesClassFile(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', 'Slug']);

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('SlugRule.php');
        self::assertFileExists($path);

        $contents = (string) file_get_contents($path);
        self::assertStringContainsString('namespace Framework\Validation\Rule;', $contents);
        self::assertStringContainsString('class SlugRule implements RuleInterface', $contents);
        self::assertStringContainsString("return 'slug';", $contents);
        self::assertStringContainsString('public function validate(mixed $value, array $params): ?string', $contents);
        self::assertStringContainsString('public function params(): array', $contents);
        self::assertStringNotContainsString('ValidationError', $contents);
        self::assertTrue(PhpLinter::check($path));
    }

    public function testFailsWithoutArg(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentRuleSuffix(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(args: ['make:rule', 'SlugRule']), $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('SlugRule.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();

        $first = $cmd->execute(new Input(args: ['make:rule', 'Slug']), $output);
        self::assertSame(0, $first);

        $output2 = new MemoryOutput();
        $second = $cmd->execute(new Input(args: ['make:rule', 'Slug']), $output2);

        self::assertSame(1, $second);
        self::assertStringContainsString('already exists', $output2->stdoutText());
    }

    public function testCustomNameAndDescription(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:rule', 'EmailDomain'],
            options: ['name' => 'email-domain', 'description' => "Rejects free-mail providers"],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $contents = (string) file_get_contents($this->tmpFile('EmailDomainRule.php'));
        self::assertStringContainsString("return 'email-domain';", $contents);
        self::assertStringContainsString('Rejects free-mail providers', $contents);
    }
}
