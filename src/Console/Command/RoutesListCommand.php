<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;
use Framework\Http\Router\Router;

final class RoutesListCommand extends Command
{
    public function __construct(
        ContainerInterface $c,
        private readonly Router $router,
    ) {
        parent::__construct($c);
    }

    public function name(): string
    {
        return 'routes:list';
    }

    public function description(): string
    {
        return 'Show all registered HTTP routes';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->all();
        $rows = array_map(
            static fn(array $r): array => [(string) $r['method'], (string) $r['path']],
            $routes,
        );
        $output->table(['Method', 'Path'], $rows);
        return 0;
    }
}
