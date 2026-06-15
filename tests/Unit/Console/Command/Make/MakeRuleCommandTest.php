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
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
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
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Usage', $output->stdoutText());
        self::assertCount(0, glob($this->tmpFile('*.php')) ?: []);
    }

    public function testFailsOnInvalidName(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', '!!!']);

        self::assertSame(1, $cmd->execute($input, $output));
        self::assertStringContainsString('Invalid', $output->stdoutText());
    }

    public function testIdempotentRuleSuffix(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();

        $code = $cmd->execute(new Input(args: ['make:rule', 'SlugRule']), $output);

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpFile('SlugRule.php'));
    }

    public function testRefusesOverwrite(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
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
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
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

    public function testDescriptionWithDocBlockCloserIsSanitized(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:rule', 'Injected'],
            options: [
                'name' => 'injected',
                'description' => "harmless */\nclass Evil {} /*",
            ],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $path = $this->tmpFile('InjectedRule.php');
        $contents = (string) file_get_contents($path);

        self::assertTrue(PhpLinter::check($path), 'Generated file must be valid PHP');
        self::assertStringContainsString('class InjectedRule', $contents);
        self::assertStringNotContainsString('class Evil implements', $contents);

        $docStart = strpos($contents, '/**');
        self::assertNotFalse($docStart);
        $docEnd = strpos($contents, '*/', $docStart + 3);
        self::assertNotFalse($docEnd);
        $interior = substr($contents, $docStart + 3, $docEnd - $docStart - 3);
        self::assertStringNotContainsString('/*', $interior);
        self::assertStringNotContainsString('*/', $interior);
    }

    public function testDescriptionWithOnlyMetaCharsProducesNoDocblock(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:rule', 'NoDoc'],
            options: [
                'name' => 'no-doc',
                'description' => "*/\r\0/*",
            ],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $contents = (string) file_get_contents($this->tmpFile('NoDocRule.php'));

        self::assertStringNotContainsString('/**', $contents);
        self::assertStringNotContainsString('/*', $contents);
        self::assertStringNotContainsString('*/', $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('NoDocRule.php')));
    }

    public function testFallsBackToAppNamespaceWhenNoComposerJson(): void
    {
        $cmd = new MakeRuleCommand(new Container(), $this->tmpDir);
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', 'Fallback']);

        self::assertSame(0, $cmd->execute($input, $output));
        $contents = (string) file_get_contents($this->tmpFile('FallbackRule.php'));
        self::assertStringContainsString('namespace App', $contents);
    }

    public function testMultilineDescriptionPreservesLineBreaks(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:rule', 'Multi'],
            options: [
                'name' => 'multi',
                'description' => "first line\nsecond line\nthird line",
            ],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $contents = (string) file_get_contents($this->tmpFile('MultiRule.php'));
        self::assertStringContainsString('first line', $contents);
        self::assertStringContainsString('second line', $contents);
        self::assertStringContainsString('third line', $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('MultiRule.php')));
    }

    public function testCarriageReturnLineFeedIsStripped(): void
    {
        $cmd = new MakeRuleCommand(
            new Container(),
            $this->tmpDir,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(
            args: ['make:rule', 'Crlf'],
            options: [
                'name' => 'crlf',
                'description' => "first\r\nsecond",
            ],
        );

        $code = $cmd->execute($input, $output);

        self::assertSame(0, $code);
        $contents = (string) file_get_contents($this->tmpFile('CrlfRule.php'));
        self::assertStringNotContainsString("first\r", $contents, 'CR between "first" and "second" must be stripped from the description');
        self::assertStringNotContainsString("\rsecond", $contents, 'CR before "second" must be stripped from the description');
        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
        self::assertTrue(PhpLinter::check($this->tmpFile('CrlfRule.php')));
    }

    public function testCreatesMissingTargetDirectory(): void
    {
        $nested = $this->tmpDir . '/Validation/Rule';
        self::assertDirectoryDoesNotExist($nested);

        $cmd = new MakeRuleCommand(
            new Container(),
            $nested,
            namespaceOverride: 'Framework\\Validation\\Rule',
        );
        $output = new MemoryOutput();
        $input = new Input(args: ['make:rule', 'Slug']);

        self::assertSame(0, $cmd->execute($input, $output));
        self::assertDirectoryExists($nested);
        self::assertFileExists($nested . '/SlugRule.php');
        self::assertStringContainsString('Created', $output->stdoutText());
    }
}
