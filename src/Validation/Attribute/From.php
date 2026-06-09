<?php

declare(strict_types=1);

namespace Framework\Validation\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class From
{
    /**
     * Dotted path into the request data, e.g. `'user.email'` reads
     * `$data['user']['email']`. Plain names like `'email'` are also
     * valid. A leading `data.` prefix is allowed for symmetry with
     * fully-qualified paths but is treated identically to the bare
     * suffix.
     */
    public function __construct(public readonly string $path)
    {
    }

    /**
     * @return list<string>
     */
    public function segments(): array
    {
        $path = $this->path;
        if (str_starts_with($path, 'data.')) {
            $path = substr($path, 5);
        }
        return $path === '' ? [] : explode('.', $path);
    }
}
