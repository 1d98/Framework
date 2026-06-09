<?php

declare(strict_types=1);

namespace Framework\Validation;

use Framework\Validation\Rule\BetweenRule;
use Framework\Validation\Rule\InRule;
use Framework\Validation\Rule\LengthRule;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RegexRule;
use Framework\Validation\Rule\RuleInterface;
use Framework\Validation\Rule\RuleRegistry;
use InvalidArgumentException;

/**
 * Turns string DSL tokens (e.g. `'required|email|min:8'`) into
 * {@see RuleInterface} instances and dispatches them to the
 * {@see RuleRegistry}. The orchestrator {@see Validator} delegates all
 * rule-name resolution and parametric rule construction to this class.
 *
 * Lookup precedence (case-insensitive at every step):
 *
 *   1. Exact name in the registry.
 *   2. Short class name (basename of a FQCN).
 *   3. Name with the trailing `Rule` suffix stripped.
 *   4. Direct registry lookup (throws NotFoundException).
 *
 * Parametric rules (`name:value`) are produced by
 * {@see self::buildParametricRule()}, which consults an internal
 * `$parametricBuilders` map. Plugins can register new parametric rule
 * factories at runtime via {@see self::register()} without touching
 * the `Validator` orchestrator.
 */
final class RuleResolver
{
    /** @var array<string, callable(list<string>): RuleInterface> */
    private array $parametricBuilders;

    public function __construct(private readonly RuleRegistry $registry)
    {
        $this->parametricBuilders = $this->defaultParametricBuilders();
    }

    /**
     * Resolve a single rule token (e.g. `'email'`, `'EmailRule'`,
     * `'min:8'`, `'Framework\Validation\Rule\MinRule:8'`) into a
     * {@see RuleInterface}. Class-FQCN inputs are normalized to their
     * basename first, then the lookup cascade runs.
     */
    public function resolve(string $token): RuleInterface
    {
        $token = $this->stripClassFqcn($token);

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?::(.+))?$/', $token, $m) !== 1) {
            throw new InvalidArgumentException("Invalid rule syntax: '{$token}'");
        }
        $name = $m[1];
        $rawValue = $m[2] ?? null;
        if ($rawValue !== null) {
            return $this->buildParametricRule($name, $this->splitParamValue($rawValue));
        }

        return $this->resolveByName($name);
    }

    /**
     * Parse a `|`-separated string DSL into a list of rules.
     *
     * @return list<RuleInterface>
     */
    public function parseRuleList(string $ruleList): array
    {
        $out = [];
        foreach (explode('|', $ruleList) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $out[] = $this->resolve($token);
        }
        return $out;
    }

    /**
     * Normalize the `$rules` argument of a {@see Validate} attribute
     * (string shorthand, list of strings, list of `RuleInterface`)
     * into a flat list of rules.
     *
     * @param string|array<int|string, string|RuleInterface>|null $rules
     * @return list<RuleInterface>
     */
    public function parseRuleSpecs(string|array|null $rules): array
    {
        $out = [];
        if ($rules === null) {
            return $out;
        }
        if (is_string($rules)) {
            return $this->parseRuleList($rules);
        }
        foreach ($rules as $rule) {
            if ($rule instanceof RuleInterface) {
                $out[] = $rule;
                continue;
            }
            if (is_string($rule)) {
                $rule = trim($rule);
                if ($rule === '') {
                    continue;
                }
                $out[] = $this->resolve($rule);
            }
        }
        return $out;
    }

    /**
     * Build a parametric rule (e.g. `min:8`, `between:1,10`,
     * `regex:^a.*$`, `in:a,b,c`, `length:5`) from a pre-split
     * parameter list. Custom parametric rules are dispatched through
     * the `$parametricBuilders` map; the default map covers the
     * built-in shorthand rules. Plugins register new entries via
     * {@see self::register()}.
     *
     * @param list<string> $params
     */
    public function buildParametricRule(string $name, array $params): RuleInterface
    {
        $builder = $this->parametricBuilders[strtolower($name)] ?? null;
        if ($builder !== null) {
            return $builder($params);
        }
        return $this->resolveByName($name);
    }

    /**
     * Register a new parametric rule factory. The factory receives
     * the pre-split parameter list (each segment already trimmed and
     * `=`-coerced via {@see self::splitParamValue()}) and must return
     * a {@see RuleInterface} instance. Useful for plugins that want
     * to extend the DSL without forking `Validator`.
     *
     * @param callable(list<string>): RuleInterface $builder
     */
    public function register(string $name, callable $builder): void
    {
        $this->parametricBuilders[strtolower($name)] = $builder;
    }

    /**
     * True when the property's reflection type is a single non-array
     * object type (e.g. `Address` or `?Address`) that matches the
     * given DTO class. This is the signal that a positional
     * class-string attribute value should be recursed into, not
     * resolved as a rule.
     *
     * @param \ReflectionType|null $type
     */
    public function matchesNestedType(mixed $type, string $nestedClass): bool
    {
        if (!$type instanceof \ReflectionNamedType) {
            return false;
        }
        if ($type->isBuiltin()) {
            return false;
        }
        return $type->getName() === $nestedClass || is_subclass_of($type->getName(), $nestedClass);
    }

    /**
     * If the token is a fully-qualified class name (e.g.
     * `EmailRule::class`), return the short class name. Otherwise
     * return the token unchanged.
     */
    private function stripClassFqcn(string $token): string
    {
        if (Validator::classExistsCached($token)) {
            $pos = strrpos($token, '\\');
            if ($pos !== false) {
                return substr($token, $pos + 1);
            }
        }
        return $token;
    }

    private function resolveByName(string $name): RuleInterface
    {
        if ($this->registry->has($name)) {
            return $this->registry->get($name);
        }

        foreach ($this->registry->names() as $registered) {
            if (strcasecmp($registered, $name) === 0) {
                return $this->registry->get($registered);
            }
        }

        $short = $this->shortClassName($name);
        if ($short !== $name) {
            foreach ($this->registry->names() as $registered) {
                if (strcasecmp($registered, $short) === 0) {
                    return $this->registry->get($registered);
                }
            }
        }

        $withoutSuffix = preg_replace('/Rule$/', '', $name) ?? $name;
        if ($withoutSuffix !== $name) {
            foreach ($this->registry->names() as $registered) {
                if (strcasecmp($registered, $withoutSuffix) === 0) {
                    return $this->registry->get($registered);
                }
            }
        }

        return $this->registry->get($name);
    }

    private function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Split the raw value of a parametric rule into a list of
     * raw segments (each still a string). Supports both `a,b,c`
     * (CSV) and `k=v,k2=v2` (named params). Coercion is the
     * responsibility of the individual builder closures — the
     * shape is preserved so the same raw input that worked
     * with the pre-split implementation still parses the same
     * way (e.g. `length:5` lands as `['5']` and the `length`
     * builder treats it as `min=max=5`).
     *
     * @return list<string>
     */
    private function splitParamValue(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $out[] = $segment;
        }
        return $out;
    }

    /**
     * @return array<string, callable(list<string>): RuleInterface>
     */
    private function defaultParametricBuilders(): array
    {
        return [
            'min' => static function (array $params): MinRule {
                $value = RuleResolver::numericAt($params, 0);
                return new MinRule(min: $value);
            },
            'max' => static function (array $params): MaxRule {
                $value = RuleResolver::numericAt($params, 0);
                return new MaxRule(max: $value);
            },
            'length' => static function (array $params): LengthRule {
                $extra = RuleResolver::paramsToMap($params);
                $lengthRaw = $extra['length'] ?? null;
                if (is_int($lengthRaw) || is_float($lengthRaw)) {
                    return new LengthRule(length: (int) $lengthRaw);
                }
                if (is_string($lengthRaw) && is_numeric($lengthRaw)) {
                    return new LengthRule(length: (int) $lengthRaw);
                }
                $minRaw = $extra['min'] ?? null;
                $maxRaw = $extra['max'] ?? null;
                $min = (is_int($minRaw) || is_float($minRaw)) ? (int) $minRaw : null;
                $max = (is_int($maxRaw) || is_float($maxRaw)) ? (int) $maxRaw : null;
                return new LengthRule(min: $min, max: $max);
            },
            'between' => static function (array $params): BetweenRule {
                $min = RuleResolver::numericAt($params, 0);
                $max = RuleResolver::numericAt($params, 1);
                return new BetweenRule(min: $min, max: $max);
            },
            'regex' => static function (array $params): RegexRule {
                $pattern = RuleResolver::scalarAt($params, 0);
                return new RegexRule(pattern: $pattern);
            },
            'in' => static function (array $params): InRule {
                $values = [];
                foreach ($params as $segment) {
                    $values[] = RuleResolver::coerce($segment);
                }
                return new InRule(values: $values);
            },
        ];
    }

    /**
     * Read a string slot from an untyped list-shaped array. Used by
     * the parametric rule factories to bridge between the
     * `array<...>` they declare in the callable signature and the
     * wider `array` PHP forces on the inner closure parameter.
     *
     * @param array<array-key, mixed> $params
     */
    private static function scalarAt(array $params, int $index): string
    {
        if (!array_key_exists($index, $params)) {
            return '';
        }
        $value = $params[$index];
        return is_string($value) ? $value : '';
    }

    /**
     * Read a numeric slot from a list-shaped array and coerce it
     * through {@see self::coerce()}. Used by the `min` / `max` /
     * `between` factories that need an int|float bound.
     *
     * @param array<array-key, mixed> $params
     */
    private static function numericAt(array $params, int $index): int|float
    {
        if (!array_key_exists($index, $params)) {
            return 0;
        }
        $value = self::coerce($params[$index]);
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        return 0;
    }

    /**
     * Normalize a segment list (output of {@see self::splitParamValue()})
     * into the map shape the original `parseParamValue()` produced.
     * Bare numeric strings become `min=max=N` (matching the pre-split
     * semantics so `length:5` still yields `LengthRule(min: 5, max: 5)`);
     * `k=v` pairs become `k => v`; bare non-numeric strings become
     * `pattern => string` (used by `regex:...`).
     *
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private static function paramsToMap(array $params): array
    {
        $out = [];
        $hasPair = false;
        foreach ($params as $segment) {
            if (!is_string($segment)) {
                continue;
            }
            if (str_contains($segment, '=')) {
                $hasPair = true;
                [$k, $v] = array_pad(explode('=', $segment, 2), 2, '');
                $out[trim($k)] = self::coerce(trim($v));
                continue;
            }
            $out[] = self::coerce($segment);
        }
        if ($hasPair) {
            /** @var array<string, mixed> $out */
            return $out;
        }
        if (count($out) === 1 && is_numeric($out[0])) {
            $num = $out[0] + 0;
            return ['min' => $num, 'max' => $num];
        }
        if (count($out) === 1 && is_string($out[0])) {
            $pattern = $out[0];
            return ['pattern' => $pattern];
        }
        /** @var array<string, mixed> $out */
        return $out;
    }

    /**
     * Coerce a raw token (the user typed `true` / `false` / `null` /
     * `42` / `3.14` in the DSL) into the matching PHP scalar. Used
     * by the parametric rule factories.
     */
    private static function coerce(mixed $v): mixed
    {
        if (!is_string($v)) {
            return $v;
        }
        if ($v === '') {
            return '';
        }
        if ($v === 'true') {
            return true;
        }
        if ($v === 'false') {
            return false;
        }
        if ($v === 'null') {
            return null;
        }
        if (is_numeric($v)) {
            return $v + 0;
        }
        return $v;
    }
}
