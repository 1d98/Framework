<?php

declare(strict_types=1);

namespace Framework\Config;

interface ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): static;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
