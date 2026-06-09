<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

abstract class Command implements CommandInterface
{
    public function __construct(protected readonly ContainerInterface $container)
    {
    }

    abstract public function name(): string;

    abstract public function description(): string;

    abstract public function execute(InputInterface $input, OutputInterface $output): int;
}
