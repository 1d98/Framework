<?php

declare(strict_types=1);

namespace Framework\Console;

use Framework\Console\Command\CommandInterface;
use Framework\Console\Formatter\StackTraceFormatter;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\Output;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;
use Throwable;

use function getenv;

final class Application
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    private ?InputInterface $input = null;

    private ?OutputInterface $output = null;

    private readonly StackTraceFormatter $traceFormatter;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $name = 'Framework Console',
        private readonly string $version = \Framework\Framework::VERSION,
        private readonly ?OutputInterface $defaultOutput = null,
        private readonly ?bool $debug = null,
        ?StackTraceFormatter $traceFormatter = null,
    ) {
        $this->traceFormatter = $traceFormatter ?? new StackTraceFormatter();
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function add(CommandInterface $command): self
    {
        $this->commands[$command->name()] = $command;
        return $this;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $this->input = ArgvParser::parse($argv);
        $output = $this->defaultOutput ?? Output::stdoutStderr();
        $output = $this->applyAnsiFlags($output);
        $this->output = $output;

        if ($this->input->flag('version')) {
            $output->write($this->name . ' ' . $this->version);
            return 0;
        }

        if ($this->input->flag('help')) {
            $this->printHelp($output);
            return 0;
        }

        $commandName = $this->input->command() ?? 'list';

        if (!isset($this->commands[$commandName])) {
            $output->error("Command not found: {$commandName}");
            $output->write("Run 'list' to see available commands.");
            return 1;
        }

        try {
            $command = $this->commands[$commandName];
            return $command->execute($this->input, $output);
        } catch (Throwable $e) {
            $this->renderException($output, $e);
            return 2;
        }
    }

    public function input(): InputInterface
    {
        if ($this->input === null) {
            throw new \LogicException('Application::input() called before run()');
        }
        return $this->input;
    }

    public function output(): OutputInterface
    {
        if ($this->output === null) {
            throw new \LogicException('Application::output() called before run()');
        }
        return $this->output;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return array<string, string>
     */
    public function commands(): array
    {
        $map = [];
        foreach ($this->commands as $name => $command) {
            $map[$name] = $command->description();
        }
        return $map;
    }

    private function printHelp(OutputInterface $output): void
    {
        $output->write($this->name . ' ' . $this->version);
        $output->write('');
        $output->write('Usage: bin/framework <command> [options]');
        $output->write('');
        $output->write('Options:');
        $output->write('  --version     Show application version');
        $output->write('  --help        Show this help message');
        $output->write('  --ansi        Force ANSI color output');
        $output->write('  --no-ansi     Disable ANSI color output');
        $output->write('');
        $output->write('Commands:');
        foreach ($this->commands as $command) {
            $output->write(sprintf('  %-20s %s', $command->name(), $command->description()));
        }
    }

    private function applyAnsiFlags(OutputInterface $output): OutputInterface
    {
        if ($this->input?->flag('no-ansi') === true) {
            return $output->withAnsi(false);
        }
        if ($this->input?->flag('ansi') === true) {
            return $output->withAnsi(true);
        }
        return $output;
    }

    private function renderException(OutputInterface $output, Throwable $e): void
    {
        $output->error($this->traceFormatter->summary($e));

        if (!$this->isDebug()) {
            return;
        }

        foreach ($this->traceFormatter->frames($e) as $line) {
            $output->error($line);
        }
    }

    private function isDebug(): bool
    {
        if ($this->input?->flag('no-debug') === true) {
            return false;
        }
        if ($this->input?->flag('debug') === true) {
            return true;
        }
        if ($this->debug !== null) {
            return $this->debug;
        }

        return self::envDebug();
    }

    private static function envDebug(): bool
    {
        $value = getenv('APP_DEBUG');
        if ($value === false || $value === '') {
            return false;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
