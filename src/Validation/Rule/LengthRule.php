<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class LengthRule implements RuleInterface
{
    public function __construct(
        private ?int $length = null,
        private ?int $min = null,
        private ?int $max = null,
    ) {
    }

    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return 'LengthRule requires a string';
        }
        $len = strlen($value);
        $length = $params['length'] ?? $this->length;
        $min = $params['min'] ?? $this->min;
        $max = $params['max'] ?? $this->max;
        if (is_int($length)) {
            return $len === $length ? null : "Field must be exactly {$length} characters";
        }
        if (is_int($min) && is_int($max)) {
            if ($len < $min) {
                return "Field must be at least {$min} characters";
            }
            if ($len > $max) {
                return "Field must be at most {$max} characters";
            }
            return null;
        }
        return 'LengthRule requires length OR (min AND max) params';
    }

    public function name(): string
    {
        return 'length';
    }

    public function params(): array
    {
        $out = [];
        if ($this->length !== null) {
            $out['length'] = $this->length;
        }
        if ($this->min !== null) {
            $out['min'] = $this->min;
        }
        if ($this->max !== null) {
            $out['max'] = $this->max;
        }
        return $out;
    }
}
