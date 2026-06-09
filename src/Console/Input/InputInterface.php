<?php

declare(strict_types=1);

namespace Framework\Console\Input;

interface InputInterface
{
    public function arg(int $index, ?string $default = null): ?string;

    public function option(string $name, ?string $default = null): ?string;

    public function flag(string $name): bool;

    public function hasOption(string $name): bool;

    public function command(): ?string;
}
