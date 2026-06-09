<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class InRule implements RuleInterface
{
    /** @var list<mixed> */
    private array $values;

    /**
     * @param list<mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = array_values($values);
    }

    public function validate(mixed $value, array $params): ?string
    {
        if ($value === null) {
            return null;
        }
        $values = $params['values'] ?? $this->values;
        if (!is_array($values)) {
            return 'InRule requires list of values';
        }
        return in_array($value, array_values($values), true)
            ? null
            : 'Field value is not in the allowed list';
    }

    public function name(): string
    {
        return 'in';
    }

    public function params(): array
    {
        return ['values' => $this->values];
    }
}
