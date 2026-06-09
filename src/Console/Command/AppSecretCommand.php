<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;

final class AppSecretCommand extends Command
{
    public function name(): string
    {
        return 'app:secret';
    }

    public function description(): string
    {
        return 'Generate a cryptographically secure application secret';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $bytes = (int) ($input->option('bytes') ?? '32');
        if ($bytes < 16 || $bytes > 128) {
            $output->danger('Bytes must be between 16 and 128');
            return 1;
        }
        $secret = bin2hex(random_bytes($bytes));
        $output->write($secret);
        return 0;
    }
}
