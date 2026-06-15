<?php

declare(strict_types=1);

namespace Framework\Validation;

use Framework\Validation\Rule\RuleInterface;

/**
 * Stand-in {@see RuleInterface} that carries the parser's error
 * message to the validator pipeline. When {@see RuleResolver}
 * throws (unknown rule name, invalid DSL syntax), the orchestrator
 * catches and returns a single `UnresolvedRule` — the value runs
 * through the regular rule loop and surfaces as a
 * {@see ValidationError} (with the parser's message as the failure
 * reason) instead of escaping as a 500 `NotFoundException` /
 * `InvalidArgumentException`. Stable identity (no anonymous
 * class) so {@see Validator::$parsedRulesCache} memoization stays
 * correct across multiple re-binds of the same DSL.
 *
 * @internal Used only by {@see Validator::resolveRuleSpecs()}.
 */
final class UnresolvedRule implements RuleInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function validate(mixed $value, array $params): string
    {
        return $this->message;
    }

    public function name(): string
    {
        return 'unresolved';
    }

    public function params(): array
    {
        return [];
    }
}
