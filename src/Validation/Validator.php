<?php

declare(strict_types=1);

namespace Framework\Validation;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleInterface;
use Framework\Validation\Rule\RuleRegistry;
use ReflectionClass;
use ReflectionProperty;

/**
 * Thin orchestrator: `validate()` = `check()` + `hydrate()`. Owns
 * the process-wide memoization maps the resolver and hydrator
 * read from; {@see self::clearCaches()} is also called from
 * `Container::wipeGlobalCaches()`.
 *
 * Per-validator parsed-rules cache, keyed by the attribute's rules
 * string. Cleared by {@see self::clearCaches()}. Custom `Rule` instances
 * registered via `register()` are picked up automatically (the cache
 * is keyed on the string DSL, not the registry).
 */
final class Validator
{
    /** @var array<class-string, ReflectionClass<object>> */
    public static array $reflectionCache = [];

    /** @var array<string, bool> */
    public static array $classExistsCache = [];

    /**
     * Process-wide memoization of `RuleResolver::parseRuleSpecs()` results
     * keyed by the string DSL of a `#[Validate]` attribute (e.g.
     * `'required|email|min:3'`). Two `#[Validate]` attributes with the
     * same rules string share the same parsed `list<RuleInterface>`, so
     * the string-DSL re-parse (and the up-to-4 linear scans the resolver
     * does per token) runs at most once per distinct string for the life
     * of the process. Cleared by {@see self::clearCaches()}.
     *
     * @var array<string, list<RuleInterface>>
     */
    private static array $parsedRulesCache = [];

    /**
     * Diagnostic counters incremented on every `parseRuleSpecs` call that
     * misses / hits the cache. Read via {@see self::memoizationStats()};
     * both reset to `0` by {@see self::clearCaches()}.
     */
    private static int $parseRuleSpecsCalls = 0;
    private static int $parsedRulesCacheHits = 0;

    private readonly RuleResolver $resolver;
    private readonly DtoHydrator $hydrator;

    public function __construct(private readonly RuleRegistry $registry)
    {
        $this->resolver = new RuleResolver($registry);
        $this->hydrator = new DtoHydrator($this->resolver);
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<string, mixed> $data
     * @return T
     * @throws ValidationException
     */
    public function validate(string $dtoClass, array $data): object
    {
        $errors = $this->check($dtoClass, $data);
        if (count($errors) > 0) {
            throw new ValidationException($errors);
        }
        return $this->hydrator->hydrate($dtoClass, $data);
    }

    /**
     * @param class-string $dtoClass
     * @param array<string, mixed> $data
     */
    public function check(string $dtoClass, array $data): ValidationErrorCollection
    {
        $errors = new ValidationErrorAccumulator();
        $this->checkInto($dtoClass, $data, $errors, []);
        return $errors->toCollection();
    }

    /**
     * @param class-string $dtoClass
     * @param array<array-key, mixed> $data
     * @param list<string> $path
     */
    private function checkInto(string $dtoClass, array $data, ValidationErrorAccumulator $errors, array $path): void
    {
        $reflection = self::getReflection($dtoClass);

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            $value = $data[$name] ?? null;

            foreach ($property->getAttributes(Validate::class) as $attr) {
                $validate = $attr->newInstance();
                $this->dispatchAttribute($validate, $property, $name, $value, $errors, $path);
            }
        }
    }

    /** @param list<string> $path */
    private function dispatchAttribute(
        Validate $attr,
        ReflectionProperty $property,
        string $propertyName,
        mixed $value,
        ValidationErrorAccumulator $errors,
        array $path,
    ): void {
        if ($attr->items !== null) {
            $this->validateItems($attr->items, $propertyName, $value, $errors, $path);
            return;
        }

        $rules = $attr->rules;

        if (is_string($rules) && self::classExistsCached($rules) && $this->resolver->matchesNestedType($property->getType(), $rules)) {
            $this->validateNested($rules, $propertyName, $value, $errors, $path);
            return;
        }

        $this->runRules($attr, $value, $propertyName, $errors, $path);
    }

    /**
     * Validate a single nested DTO property.
     *
     * @param class-string<object> $nestedClass
     * @param list<string> $path
     */
    private function validateNested(
        string $nestedClass,
        string $propertyName,
        mixed $value,
        ValidationErrorAccumulator $errors,
        array $path,
    ): void {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $errors->add(new ValidationError('', 'type', 'Field must be an object', $value, [...$path, $propertyName]));
            return;
        }
        $this->checkInto($nestedClass, $value, $errors, [...$path, $propertyName]);
    }

    /**
     * Validate a list-of-DTOs property (`#[Validate(items: SomeDto::class)]`).
     *
     * @param class-string<object> $itemClass
     * @param list<string> $path
     */
    private function validateItems(
        string $itemClass,
        string $propertyName,
        mixed $value,
        ValidationErrorAccumulator $errors,
        array $path,
    ): void {
        if ($value === null) {
            return;
        }
        if (!is_array($value)) {
            $errors->add(new ValidationError('', 'type', 'Field must be an array', $value, [...$path, $propertyName]));
            return;
        }
        if (array_is_list($value) === false) {
            $errors->add(new ValidationError('', 'type', 'Field must be a list', $value, [...$path, $propertyName]));
            return;
        }
        $basePath = [...$path, $propertyName];
        foreach ($value as $index => $item) {
            $itemPath = [...$basePath, (string) $index];
            if (!is_array($item)) {
                $errors->add(new ValidationError('', 'type', 'Field must be an object', $item, $itemPath));
                continue;
            }
            $this->checkInto($itemClass, $item, $errors, $itemPath);
        }
    }

    /** @param list<string> $path */
    private function runRules(Validate $attr, mixed $value, string $propertyName, ValidationErrorAccumulator $errors, array $path): void
    {
        foreach ($this->resolveRuleSpecs($attr->rules) as $rule) {
            /** @var RuleInterface $rule */
            $error = $rule->validate($value, $rule->params());
            if ($error !== null) {
                $errors->add(new ValidationError($propertyName, $rule->name(), $error, $value, $path));
            }
        }
    }

    /**
     * @param string|array<int|string, string|RuleInterface>|null $rules
     * @return list<RuleInterface>
     */
    private function resolveRuleSpecs(string|array|null $rules): array
    {
        if (is_string($rules)) {
            $cached = self::$parsedRulesCache[$rules] ?? null;
            if ($cached !== null) {
                self::$parsedRulesCacheHits++;
                return $cached;
            }
        }
        self::$parseRuleSpecsCalls++;
        $parsed = $this->resolver->parseRuleSpecs($rules);
        if (is_string($rules)) {
            /** @var list<RuleInterface> $parsed */
            self::$parsedRulesCache[$rules] = $parsed;
        }
        return $parsed;
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @return ReflectionClass<T>
     */
    public static function getReflection(string $dtoClass): ReflectionClass
    {
        if (!isset(self::$reflectionCache[$dtoClass])) {
            self::$reflectionCache[$dtoClass] = new ReflectionClass($dtoClass);
        }
        /** @var ReflectionClass<T> $refl */
        $refl = self::$reflectionCache[$dtoClass];
        return $refl;
    }

    /** @phpstan-assert-if-true class-string $fqcn */
    public static function classExistsCached(string $fqcn): bool
    {
        if (!array_key_exists($fqcn, self::$classExistsCache)) {
            self::$classExistsCache[$fqcn] = class_exists($fqcn);
        }
        return self::$classExistsCache[$fqcn];
    }

    public static function clearCaches(): void
    {
        self::$reflectionCache = [];
        self::$classExistsCache = [];
        self::$parsedRulesCache = [];
        self::$parseRuleSpecsCalls = 0;
        self::$parsedRulesCacheHits = 0;
    }

    /**
     * Diagnostic snapshot of the parsed-rules memoization state. Useful
     * for benchmarks and tests; cheap (no reflection, just static reads).
     *
     * Shape:
     *   - `parseRuleSpecsCalls`: total `parseRuleSpecs()` invocations that
     *     actually ran the string DSL parser. Equals the number of
     *     *distinct* string DSLs seen since the last `clearCaches()`.
     *   - `parsedRulesCacheHits`: total cache hits — i.e. re-binds that
     *     were served from the memoized list without re-parsing.
     *   - `parsedRulesCacheSize`: current number of entries in the cache.
     *
     * @return array{parseRuleSpecsCalls: int, parsedRulesCacheHits: int, parsedRulesCacheSize: int}
     */
    public static function memoizationStats(): array
    {
        return [
            'parseRuleSpecsCalls' => self::$parseRuleSpecsCalls,
            'parsedRulesCacheHits' => self::$parsedRulesCacheHits,
            'parsedRulesCacheSize' => count(self::$parsedRulesCache),
        ];
    }
}
