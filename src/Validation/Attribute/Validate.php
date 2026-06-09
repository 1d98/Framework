<?php

declare(strict_types=1);

namespace Framework\Validation\Attribute;

use Attribute;
use Framework\Validation\Rule\RuleInterface;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class Validate
{
    /**
     * @param string|list<string|RuleInterface>|null $rules
     *        - string: shorthand `'required|email|min:3|max:50'` (split on `|`, then `name:value`).
     *          A class-string of a DTO whose property type is a single non-array object
     *          is interpreted as a nested DTO recursion target; otherwise it is resolved
     *          as a rule name (basename, case-insensitive).
     *        - list: each entry is `'name'` / `'name:value'` / `RuleInterface` instance.
     *        - null: only meaningful together with `$items` (array-shape DTO validation).
     * @param class-string<object>|null $items
     *        When set, the property is treated as `list<DTO>` and the validator iterates
     *        each element, recursing into the given DTO class.
     */
    public function __construct(
        public readonly string|array|null $rules = null,
        public readonly ?string $items = null,
    ) {
    }
}
