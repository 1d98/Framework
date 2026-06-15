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
        return 'Show all registered HTTP routes (--json for machine-readable export)';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->router->allDetailed();

        $jsonFlag = $input->option('json');
        if (in_array($jsonFlag, ['true', '1'], true)) {
            $output->write(json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '');
            return 0;
        }

        $rows = array_map(
            static fn(array $r): array => [(string) $r['method'], (string) $r['path']],
            $routes,
        );
        $output->table(['Method', 'Path'], $rows);
        return 0;
    }
}
