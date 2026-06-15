<?php

declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;

/**
 * Parser for the `If-Match` and `If-None-Match` request headers
 * (RFC 7232 §3.1, §3.2).
 *
 * The header value is a comma-separated list of etags with
 * optional `W/` prefixes; the special token `*` matches any
 * existing representation (used to assert "resource exists").
 *
 * Examples:
 *  - `If-None-Match: "abc123"`
 *  - `If-None-Match: "abc123", W/"def456"`
 *  - `If-Match: *`
 *  - `If-Match: "abc123", W/"def456"`
 *
 * The class is a value object built once by {@see self::parse()};
 * {@see self::contains()} answers the RFC 7232 §2.3 weak-match
 * question.
 */
final readonly class EtagList
{
    /**
     * @param list<Etag> $etags
     * @param bool $wildcard True when the header was `*` (matches
     *     "any" representation per RFC 7232 §3.1).
     */
    private function __construct(
        public array $etags,
        public bool $wildcard = false,
    ) {
    }

    /**
     * Parse a header value. Returns an empty list for `null`,
     * empty, or whitespace-only input. Bad tokens are silently
     * dropped (the RFC permits the server to ignore etags it
     * cannot parse — a strict server SHOULD log a 412, but
     * silently dropping is a common, well-defined behavior).
     */
    public static function parse(?string $header): self
    {
        if ($header === null) {
            return new self([]);
        }
        $header = trim($header);
        if ($header === '') {
            return new self([]);
        }
        if ($header === '*') {
            return new self([], wildcard: true);
        }

        $etags = [];
        foreach (explode(',', $header) as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $weak = false;
            if (str_starts_with($raw, Etag::WEAK_PREFIX)) {
                $weak = true;
                $raw = substr($raw, strlen(Etag::WEAK_PREFIX));
            }
            if (!str_starts_with($raw, '"') || !str_ends_with($raw, '"')) {
                // Malformed — drop per RFC 7232 §3.1 ("A recipient
                // MUST ignore the If-Match header field ... that
                // contains any invalid entity-tag").
                continue;
            }
            $opaque = substr($raw, 1, -1);
            if ($opaque === '') {
                continue;
            }
            try {
                $etags[] = new Etag($opaque, $weak);
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        return new self($etags);
    }

    /**
     * True if `$candidate` matches any etag in this list under
     * RFC 7232 §2.3.2 weak comparison. A list with `wildcard: true`
     * matches any non-empty etag candidate (RFC 7232 §3.2: `*`
     * matches if "the origin server has a current representation
     * for the target resource"; in this middleware, "exists" is
     * proxied by "the candidate is non-null and non-empty").
     */
    public function contains(Etag $candidate): bool
    {
        if ($this->wildcard) {
            return true;
        }
        foreach ($this->etags as $etag) {
            if ($etag->weakMatches($candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strict-match version of {@see self::contains()}, used by
     * `If-Match`. A `*` wildcard matches any candidate under
     * strong comparison as well (the wildcard asserts "any
     * existing resource" and is satisfied by any actual etag).
     */
    public function containsStrict(Etag $candidate): bool
    {
        if ($this->wildcard) {
            return true;
        }
        foreach ($this->etags as $etag) {
            if ($etag->strongMatches($candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<Etag>
     */
    public function all(): array
    {
        return $this->etags;
    }

    public function isEmpty(): bool
    {
        return $this->etags === [] && !$this->wildcard;
    }
}
