<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class MakeCommandCommand extends Command
{
    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;

final class %class% extends Command
{
    public function name(): string
    {
        return '%name%';
    }

    public function description(): string
    {
        return '%description%';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->success('%class% executed');
        return 0;
    }
}
PHP;

    public function __construct(
        ContainerInterface $container,
        private readonly string $commandsDir,
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
    ) {
        parent::__construct($container);
    }

    public function name(): string
    {
        return 'make:command';
    }

    public function description(): string
    {
        return 'Generate a new console command class';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:command <Name> [--name=cmd:name] [--description="..."]');
            return 1;
        }

        $class = $this->validator->suffixed($raw, 'Command');
        if ($class === '') {
            $output->danger('Invalid command name. Use PascalCase (e.g. Hello, SendEmail).');
            return 1;
        }

        $name = $input->option('name') ?? $this->validator->slug($class, 'Command') . ':run';
        $description = $input->option('description') ?? 'TODO: describe ' . $class;

        $path = rtrim($this->commandsDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        $body = strtr(self::TEMPLATE, [
            '%class%' => $class,
            '%name%' => $name,
            '%description%' => $description,
        ]);

        $written = @file_put_contents($path, $body);
        if ($written === false) {
            $output->danger("Failed to write {$path}");
            return 1;
        }

        $output->success("Created {$path}");
        $output->info('Next: register the command in bin/framework and regenerate the autoloader.');
        return 0;
    }
}
