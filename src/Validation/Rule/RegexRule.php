<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

final class RegexRule implements RuleInterface
{
    public function __construct(private string $pattern = '')
    {
    }

    public function validate(mixed $value, array $params): ?string
    {
        $pattern = $params['pattern'] ?? $this->pattern;
        if (!is_string($pattern) || $pattern === '') {
            return 'RegexRule requires a pattern';
        }
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return 'Field does not match pattern';
        }
        $matched = @preg_match($pattern, (string) $value);
        if ($matched === false) {
            return 'RegexRule has an invalid pattern';
        }
        return $matched === 1 ? null : 'Field does not match pattern';
    }

    public function name(): string
    {
        return 'regex';
    }

    public function params(): array
    {
        return $this->pattern === '' ? [] : ['pattern' => $this->pattern];
    }
}
