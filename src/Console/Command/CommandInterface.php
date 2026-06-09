<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;

interface CommandInterface
{
    public function name(): string;

    public function description(): string;

    public function execute(InputInterface $input, OutputInterface $output): int;
}
