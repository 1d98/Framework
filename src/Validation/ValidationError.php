<?php

declare(strict_types=1);

namespace Framework\Validation;

final readonly class ValidationError
{
    /**
     * @param list<string> $path JSON-Pointer-style path segments (e.g. `['address', 'email']`).
     *                          Empty for top-level properties.
     */
    public function __construct(
        public string $property,
        public string $rule,
        public string $message,
        public mixed $value = null,
        public array $path = [],
    ) {
    }

    public function property(): string
    {
        return $this->property;
    }

    public function rule(): string
    {
        return $this->rule;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public function path(): array
    {
        return $this->path;
    }

    /**
     * RFC 7807 JSON Pointer for this error (e.g. `/address/email`).
     * Combines enclosing path segments with the leaf property name. Always
     * starts with `/`. When the leaf property is empty (e.g. a structural
     * type error on a non-leaf location), the trailing segment is dropped.
     */
    public function pointer(): string
    {
        $segments = $this->property === '' ? $this->path : [...$this->path, $this->property];
        return '/' . implode('/', $segments);
    }

    /**
     * @return array{property: string, rule: string, message: string, value?: mixed, pointer: string, path: list<string>}
     */
    public function toArray(): array
    {
        $out = [
            'property' => $this->property,
            'rule' => $this->rule,
            'message' => $this->message,
            'pointer' => $this->pointer(),
            'path' => $this->path,
        ];
        if ($this->value !== null) {
            $out['value'] = $this->value;
        }
        return $out;
    }
}
