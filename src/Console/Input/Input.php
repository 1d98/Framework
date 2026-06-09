<?php

declare(strict_types=1);

namespace Framework\Console\Input;

final readonly class Input implements InputInterface
{
    /**
     * @param list<string>          $args
     * @param array<string, string> $options
     * @param array<string, true>   $flags
     * @param array<string, string> $shortOptions
     */
    public function __construct(
        public array $args = [],
        public array $options = [],
        public array $flags = [],
        public array $shortOptions = [],
    ) {
    }

    public function arg(int $index, ?string $default = null): ?string
    {
        return $this->args[$index] ?? $default;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        if (array_key_exists($name, $this->options)) {
            return $this->options[$name];
        }
        if (array_key_exists($name, $this->shortOptions)) {
            return $this->shortOptions[$name];
        }
        return $default;
    }

    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->flags);
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options)
            || array_key_exists($name, $this->shortOptions);
    }

    public function command(): ?string
    {
        return $this->args[0] ?? null;
    }
}
