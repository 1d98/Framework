<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class BetweenRule implements RuleInterface
{
    public function __construct(
        private int|float $min,
        private int|float $max,
    ) {
    }

    public function validate(mixed $value, array $params): ?string
    {
        $min = $params['min'] ?? $this->min;
        $max = $params['max'] ?? $this->max;
        if (!is_int($min) && !is_float($min)) {
            return 'BetweenRule requires int|float min';
        }
        if (!is_int($max) && !is_float($max)) {
            return 'BetweenRule requires int|float max';
        }
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return ($value >= $min && $value <= $max)
                ? null
                : "Field must be between {$min} and {$max}";
        }
        if (is_string($value)) {
            $len = strlen($value);
            return ($len >= $min && $len <= $max)
                ? null
                : "Field length must be between {$min} and {$max}";
        }
        if (is_array($value)) {
            $count = count($value);
            return ($count >= $min && $count <= $max)
                ? null
                : "Field must have between {$min} and {$max} items";
        }
        return 'Field type not supported by BetweenRule';
    }

    public function name(): string
    {
        return 'between';
    }

    public function params(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
