<?php

declare(strict_types=1);

namespace Framework\Console\Output;

interface OutputInterface
{
    public function write(string $message, bool $newline = true): void;

    public function withAnsi(bool $useAnsi): self;

    public function useAnsi(): bool;

    public function error(string $message, bool $newline = true): void;

    public function success(string $message): void;

    public function info(string $message): void;

    public function warning(string $message): void;

    public function danger(string $message): void;

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    public function table(array $headers, array $rows): void;
}
