<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

interface RuleInterface
{
    /**
     * @param mixed $value  Value being validated.
     * @param array<string, mixed> $params  Rule-specific parameters.
     * @return string|null  Error message, or null if valid.
     */
    public function validate(mixed $value, array $params): ?string;

    /** @return string Rule name (e.g. 'required', 'email'). */
    public function name(): string;

    /** @return array<string, mixed> Params baked into this rule instance (default: []). */
    public function params(): array;
}
