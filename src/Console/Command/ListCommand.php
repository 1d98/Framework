<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Application;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class ListCommand extends Command
{
    public function __construct(
        ContainerInterface $c,
        private readonly Application $app,
    ) {
        parent::__construct($c);
    }

    public function name(): string
    {
        return 'list';
    }

    public function description(): string
    {
        return 'Show all registered commands';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write($this->app->name() . ' ' . $this->app->version() . ' — available commands:');
        foreach ($this->app->commands() as $name => $description) {
            $output->write(sprintf('  %-20s %s', $name, $description));
        }
        return 0;
    }
}
