<?php

declare(strict_types=1);

namespace Framework\Config;

use Framework\Exception\ConfigException;

final readonly class Config implements ConfigInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException("Config file not found: {$path}");
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new ConfigException("Config file must return an array: {$path}");
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): static
    {
        return new self([...$this->data, ...$overrides]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
