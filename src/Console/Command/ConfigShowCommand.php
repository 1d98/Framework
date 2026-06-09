<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Config\ConfigInterface;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class ConfigShowCommand extends Command
{
    public function __construct(
        ContainerInterface $c,
        private readonly ConfigInterface $config,
    ) {
        parent::__construct($c);
    }

    public function name(): string
    {
        return 'config:show';
    }

    public function description(): string
    {
        return 'Display all loaded configuration values';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->config->all();
        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = [
                (string) $key,
                is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            ];
        }
        $output->table(['Key', 'Value'], $rows);
        return 0;
    }
}
