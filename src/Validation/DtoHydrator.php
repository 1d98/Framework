<?php

declare(strict_types=1);

namespace Framework\Validation;

use Framework\Validation\Attribute\From;
use Framework\Validation\Attribute\Validate;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Builds a DTO from raw request data, picking one of two strategies
 * per class:
 *
 *   1. Constructor-based hydration (preferred): the DTO declares a
 *      non-empty constructor; the resolver maps each parameter to a
 *      data field by name (case-insensitive) or by an explicit
 *      `#[From('dotted.path')]` attribute, then invokes the
 *      constructor. Nested DTOs and `list<DTO>` items are
 *      recursively hydrated the same way.
 *   2. Property-based hydration (back-compat): a DTO with no
 *      constructor (or a zero-arg one) is built via
 *      `ReflectionClass::newInstanceWithoutConstructor()` and each
 *      property is set via reflection. This is the legacy code path
 *      retained for `final readonly` DTOs that only declare promoted
 *      properties.
 *
 * The hydrator owns the case-insensitive dotted-path walker used by
 * `#[From]` and the type-resolution helpers (`nestedDtoClassFor`,
 * `itemsClassFor`). The orchestrator {@see Validator} delegates
 * `buildDto` here.
 */
final class DtoHydrator
{
    private const string MISSING = "\0__validator_missing__\0";

    public function __construct(
        private readonly RuleResolver $resolver,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $dtoClass
     * @param array<array-key, mixed> $data
     * @return T
     */
    public function hydrate(string $dtoClass, array $data): object
    {
        $reflection = Validator::getReflection($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $this->hydrateViaPropertySet($reflection, $data);
        }

        $args = $this->resolveConstructorArgs($constructor, $data, $dtoClass);

        /** @var T $instance */
        $instance = $reflection->newInstanceArgs($args);
        return $instance;
    }

    /**
     * @template T of object
     * @param ReflectionClass<T> $reflection
     * @param array<array-key, mixed> $data
     * @return T
     */
    private function hydrateViaPropertySet(ReflectionClass $reflection, array $data): object
    {
        /** @var T $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            $value = array_key_exists($name, $data) ? $data[$name] : null;
            $this->hydrateProperty($property, $instance, $value);
        }

        return $instance;
    }

    /**
     * @param ReflectionMethod $constructor
     * @param array<array-key, mixed> $data
     * @param class-string $dtoClass
     * @return array<string, mixed>
     */
    private function resolveConstructorArgs(ReflectionMethod $constructor, array $data, string $dtoClass): array
    {
        $args = [];
        $errors = new ValidationErrorAccumulator();
        foreach ($constructor->getParameters() as $param) {
            try {
                $args[$param->getName()] = $this->resolveConstructorArg($param, $data, $dtoClass, $errors);
            } catch (ValidationException $e) {
                foreach ($e->errors()->all() as $nested) {
                    $errors->add($nested);
                }
            }
        }
        $collected = $errors->toCollection();
        if (!$collected->isEmpty()) {
            throw new ValidationException($collected);
        }
        return $args;
    }

    /**
     * @param ReflectionParameter $param
     * @param array<array-key, mixed> $data
     * @param class-string $dtoClass
     */
    private function resolveConstructorArg(ReflectionParameter $param, array $data, string $dtoClass, ValidationErrorAccumulator $errors): mixed
    {
        $value = $this->lookupParamValue($param, $data);

        if ($value === self::MISSING) {
            $displayName = $this->fromPathFor($param) ?? $param->getName();
            if ($param->isDefaultValueAvailable()) {
                if ($param->isDefaultValueConstant()) {
                    $constName = $param->getDefaultValueConstantName();
                    if (!is_string($constName)) {
                        $errors->add(new ValidationError(
                            property: $displayName,
                            rule: 'required',
                            message: "missing required property '{$displayName}' for {$dtoClass}",
                            value: null,
                            path: [],
                        ));
                        return null;
                    }
                    return constant($constName);
                }
                return $param->getDefaultValue();
            }
            $errors->add(new ValidationError(
                property: $displayName,
                rule: 'required',
                message: "missing required property '{$displayName}' for {$dtoClass}",
                value: null,
                path: [],
            ));
            return null;
        }

        if (is_array($value)) {
            $nestedDto = $this->nestedDtoClassFor($param, $value);
            if ($nestedDto !== null) {
                return $this->hydrate($nestedDto, $value);
            }
            $itemsClass = $this->itemsClassFor($param);
            if ($itemsClass !== null && array_is_list($value)) {
                $items = [];
                foreach ($value as $item) {
                    if (is_array($item) && Validator::classExistsCached($itemsClass)) {
                        $items[] = $this->hydrate($itemsClass, $item);
                    } else {
                        $items[] = $item;
                    }
                }
                return $items;
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function lookupParamValue(ReflectionParameter $param, array $data): mixed
    {
        foreach ($param->getAttributes(From::class) as $attr) {
            $from = $attr->newInstance();
            if (!$from instanceof From) {
                continue;
            }
            $resolved = $this->walkDottedPath($data, $from->segments());
            if ($resolved !== self::MISSING) {
                return $resolved;
            }
            return self::MISSING;
        }

        $name = $param->getName();
        if (array_key_exists($name, $data)) {
            return $data[$name];
        }
        foreach (array_keys($data) as $key) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return $data[$key];
            }
        }
        return self::MISSING;
    }

    /**
     * Walk a dotted `#[From]` path into `$data`. Each segment is
     * looked up case-insensitively: an exact key match wins,
     * otherwise the first key that equals the segment under
     * `strcasecmp` is used. This mirrors the case-insensitive
     * parameter-name fallback in `lookupParamValue()` so that
     * `#[From('user.email')]` resolves against payloads shaped
     * like `{"User": {"Email": "x"}}`.
     *
     * Public so other consumers (e.g. handler-level transformers)
     * can reuse the same walk.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $segments
     */
    public function walkDottedPath(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return self::MISSING;
            }
            $key = $this->findKeyInsensitive($current, $segment);
            if ($key === null) {
                return self::MISSING;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Locate `$segment` in `$data` case-insensitively. An exact key
     * match is preferred; otherwise the first key that compares
     * equal to `$segment` under `strcasecmp` is returned. Numeric
     * keys are skipped because `strcasecmp` is undefined on
     * integers.
     *
     * @param array<array-key, mixed> $data
     */
    private function findKeyInsensitive(array $data, string $segment): ?string
    {
        if (array_key_exists($segment, $data)) {
            return $segment;
        }
        foreach (array_keys($data) as $key) {
            if (is_string($key) && strcasecmp($key, $segment) === 0) {
                return $key;
            }
        }
        return null;
    }

    /**
     * @param ReflectionParameter $param
     * @param array<array-key, mixed> $value
     * @return class-string<object>|null
     */
    public function nestedDtoClassFor(ReflectionParameter $param, array $value): ?string
    {
        unset($value);

        foreach ($param->getAttributes(Validate::class) as $attr) {
            $validate = $attr->newInstance();
            if (!is_string($validate->rules)) {
                continue;
            }
            if (!Validator::classExistsCached($validate->rules)) {
                continue;
            }
            if ($this->paramTypeMatches($param, $validate->rules)) {
                return $validate->rules;
            }
        }
        return null;
    }

    /**
     * @return class-string<object>|null
     */
    public function itemsClassFor(ReflectionParameter $param): ?string
    {
        foreach ($param->getAttributes(Validate::class) as $attr) {
            $validate = $attr->newInstance();
            if ($validate->items !== null && Validator::classExistsCached($validate->items)) {
                return $validate->items;
            }
        }
        return null;
    }

    /**
     * Return the raw `#[From]` path attached to the parameter, or
     * `null` when no `#[From]` is present. Used to surface the path
     * the resolver actually looked at in "missing required
     * property" errors.
     */
    private function fromPathFor(ReflectionParameter $param): ?string
    {
        $attrs = $param->getAttributes(From::class);
        if ($attrs === []) {
            return null;
        }
        $from = $attrs[0]->newInstance();
        return $from instanceof From ? $from->path : null;
    }

    private function paramTypeMatches(ReflectionParameter $param, string $class): bool
    {
        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType) {
            return false;
        }
        if ($type->isBuiltin()) {
            return false;
        }
        $name = $type->getName();
        return $name === $class || is_subclass_of($name, $class);
    }

    private function hydrateProperty(ReflectionProperty $property, object $instance, mixed $value): void
    {
        $attributes = $property->getAttributes(Validate::class);
        if ($attributes === []) {
            $property->setValue($instance, $value);
            return;
        }

        foreach ($attributes as $attr) {
            $validate = $attr->newInstance();

            if ($validate->items !== null) {
                if (is_array($value) && array_is_list($value)) {
                    $itemClass = $validate->items;
                    $items = [];
                    foreach ($value as $item) {
                        if (is_array($item) && Validator::classExistsCached($itemClass)) {
                            $items[] = $this->hydrate($itemClass, $item);
                        } else {
                            $items[] = $item;
                        }
                    }
                    $property->setValue($instance, $items);
                } else {
                    $property->setValue($instance, $value);
                }
                return;
            }

            $rules = $validate->rules;
            if (is_string($rules) && Validator::classExistsCached($rules) && $this->resolver->matchesNestedType($property->getType(), $rules)) {
                if (is_array($value)) {
                    $property->setValue($instance, $this->hydrate($rules, $value));
                } else {
                    $property->setValue($instance, $value);
                }
                return;
            }
        }

        $property->setValue($instance, $value);
    }
}
