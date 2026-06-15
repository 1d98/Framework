<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * RFC 7232 entity tag (ETag) value object.
 *
 * An etag is either strong (byte-exact: `"a1b2c3..."`) or weak
 * (semantic-equivalent: `W/"a1b2c3..."`). Strong etags are the
 * default in {@see EtagMiddleware}; the `W/` prefix is reserved
 * for the case where the server cannot guarantee byte-level
 * equivalence (e.g. dynamic HTML pages where two renders with
 * identical content may differ in whitespace).
 *
 * The two {@see self::matches()} semantics follow RFC 7232 §2.3:
 *  - strong comparison (used by `If-Match`): two etags match
 *    only when both are strong AND opaque-tag identical
 *  - weak comparison (used by `If-None-Match`): two etags match
 *    when their opaque-tag is identical, regardless of W/ prefix
 *
 * This class is a value object — the constructor is the only
 * mutator, every property is `readonly`.
 */
final readonly class Etag
{
    public const string WEAK_PREFIX = 'W/';

    public function __construct(
        public string $value,
        public bool $weak = false,
    ) {
        if ($value === '') {
            throw new \InvalidArgumentException('Etag value must be a non-empty string');
        }
        if (str_contains($value, '"') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException(
                'Etag value must not contain DQUOTE or NUL bytes',
            );
        }
    }

    /**
     * Render the etag for the `ETag` response header or for
     * `If-Match` / `If-None-Match` parsing. Strong etags are
     * rendered as `"<value>"`; weak etags are rendered as
     * `W/"<value>"`.
     */
    public function toHeader(): string
    {
        $tag = '"' . $this->value . '"';
        return $this->weak ? self::WEAK_PREFIX . $tag : $tag;
    }

    /**
     * RFC 7232 §2.3.2 weak comparison. Two etags match when their
     * opaque-tag is identical, regardless of the `W/` prefix.
     * This is the comparison used by `If-None-Match`.
     */
    public function weakMatches(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * RFC 7232 §2.3.1 strong comparison. Two etags match only when
     * both are strong AND their opaque-tag is identical. This is
     * the comparison used by `If-Match`.
     */
    public function strongMatches(self $other): bool
    {
        return !$this->weak && !$other->weak && $this->value === $other->value;
    }
}
