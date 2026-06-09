<?php

declare(strict_types=1);

namespace Framework\Validation\Rule;

use Framework\Container\NotFoundException;

final class RuleRegistry
{
    /** @var array<string, RuleInterface> */
    private array $rules = [];

    /**
     * @param array<string, RuleInterface> $defaults Pre-registered rules. Defaults: 15 built-ins.
     */
    public function __construct(array $defaults = [])
    {
        if ($defaults === []) {
            $this->registerBuiltins();
        } else {
            foreach ($defaults as $name => $rule) {
                $this->register($name, $rule);
            }
        }
    }

    public function register(string $name, RuleInterface $rule): void
    {
        $this->rules[$name] = $rule;
    }

    public function get(string $name): RuleInterface
    {
        if (!isset($this->rules[$name])) {
            throw new NotFoundException("Validation rule '{$name}' is not registered");
        }
        return $this->rules[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    /**
     * @return array<string, RuleInterface>
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->rules);
    }

    private function registerBuiltins(): void
    {
        $this->register('required', new RequiredRule());
        $this->register('string', new StringRule());
        $this->register('integer', new IntegerRule());
        $this->register('float', new FloatRule());
        $this->register('boolean', new BooleanRule());
        $this->register('array', new ArrayRule());
        $this->register('email', new EmailRule());
        $this->register('url', new UrlRule());
        $this->register('uuid', new UuidRule());
        $this->register('regex', new RegexRule());
        $this->register('min', new MinRule(min: 0));
        $this->register('max', new MaxRule(max: 0));
        $this->register('length', new LengthRule());
        $this->register('between', new BetweenRule(min: 0, max: 0));
        $this->register('in', new InRule(values: []));
    }
}
