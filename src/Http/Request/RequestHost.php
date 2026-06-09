<?php

declare(strict_types=1);

namespace Framework\Http\Request;

use Framework\Http\CidrMatcher;
use Framework\Http\Exception\BadRequestHttpException;
use LogicException;

/**
 * Host-related value object extracted from {@see Request} as part of the
 * A3 god-class split (RB-5). Owns:
 *  - the raw `Host` header (validated, with a CRLF / NUL guard),
 *  - the snapshot of the underlying transport-level HTTPS state
 *    (so `isSecure()` / `scheme()` have a cheap fallback for the
 *    "no trusted-proxy trust list configured" case),
 *  - the immediate-connection `REMOTE_ADDR`,
 *  - the trusted-proxy list (CIDRs / exact IPs) consulted by the
 *    `X-Forwarded-Proto` and `X-Forwarded-For` heuristics.
 *
 * Lives off-`Request` so the request DTO can stay `final readonly` and
 * its constructor can shed the host-related parameter it used to
 * carry. Pure value object — no SAPI reads happen after construction.
 *
 * Default behavior (no trusted-proxies list passed at the call site
 * and none stored on the instance) is **strict**: `X-Forwarded-Proto`
 * and `X-Forwarded-For` are NEVER honored, and the answers are
 * driven entirely by the SAPI snapshot captured at construction.
 *
 * The forwarded headers (`X-Forwarded-Proto` / `X-Forwarded-For`)
 * are passed in as method arguments by the delegating
 * {@see Request} — `RequestHost` itself never reads them from
 * `$_SERVER`. This keeps the VO pure (no global SAPI reads at call
 * time) while still supporting both factory-built requests and
 * direct-constructed test fixtures.
 */
final readonly class RequestHost
{
    private const string HTTPS_TRUTHY_DISABLED_VALUE = 'off';

    /**
     * @param list<string>|null $trustedProxies  CIDRs / exact IPs allowed
     *     to set `X-Forwarded-Proto` and `X-Forwarded-For`. `null` and
     *     `[]` both disable header trust.
     */
    public function __construct(
        public ?string $host = null,
        public bool $isSecure = false,
        public ?string $remoteAddr = null,
        public ?array $trustedProxies = null,
    ) {
    }

    /**
     * Return the validated Host header for the request.
     *
     * Without `$trustedHosts` the raw Host value is returned as-is
     * (legacy behavior, kept for backward compatibility). Callers
     * that reflect the result into a `Location:` header, redirect
     * URL, or any other security-sensitive surface MUST pass an
     * explicit list — otherwise an attacker can poison the Host
     * header (DNS rebinding, open redirect).
     *
     * Matching rules when `$trustedHosts` is provided:
     *  - The header is checked for CRLF / NUL injection first;
     *    offenders cause a 400 `BadRequestHttpException` regardless
     *    of the list.
     *  - Any `host:port` form has the port stripped before matching.
     *  - Comparison is case-insensitive (lowercased).
     *  - A pattern of the form `*.example.com` matches `example.com`
     *    itself and any single-label subdomain
     *    (`api.example.com`, `a.b.example.com`).
     *  - A bare label (`example.com`) matches only that exact host.
     *  - If the host does not match any pattern, the first pattern
     *    is returned as a safe default. Passing an empty list
     *    throws, because "trust nothing" + "fall back to a default"
     *    is a contradiction.
     *
     * @param list<string>|null $trustedHosts Optional list of trusted host patterns.
     * @param string|null $hostOverride  Optional override for the
     *     Host value to validate. Defaults to the snapshot stored
     *     on this VO (`$this->host`). Passed in by {@see Request}
     *     when the request was direct-constructed with a custom
     *     `Host` header (e.g. in tests) — the snapshot is empty in
     *     that case.
     * @throws BadRequestHttpException When the Host header contains CRLF / NUL injection.
     * @throws LogicException When `$trustedHosts` is a non-null empty list.
     */
    public function host(?array $trustedHosts = null, ?string $hostOverride = null): string
    {
        $raw = $hostOverride ?? $this->host;
        if ($raw === null || $raw === '') {
            return 'localhost';
        }

        if (str_contains($raw, "\r") || str_contains($raw, "\n") || str_contains($raw, "\0")) {
            throw new BadRequestHttpException('Invalid Host header');
        }

        if ($trustedHosts === null) {
            return $raw;
        }

        if ($trustedHosts === []) {
            throw new LogicException('RequestHost::host() requires a non-empty trusted hosts list when the argument is provided');
        }

        $candidate = strtolower(trim(explode(':', $raw, 2)[0]));

        foreach ($trustedHosts as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if (self::hostMatchesPattern($candidate, $pattern)) {
                return $candidate;
            }
        }

        $fallback = strtolower(trim($trustedHosts[0]));
        if (str_starts_with($fallback, '*.')) {
            $fallback = substr($fallback, 2);
        }
        return $fallback;
    }

    /**
     * Whether the request was received over HTTPS, or terminated at
     * a trusted proxy that asserts HTTPS via `X-Forwarded-Proto`.
     *
     * Default behavior (`$trustedProxies === null`) is **strict**:
     * the `X-Forwarded-Proto` header is NEVER trusted, and the
     * answer is driven entirely by the transport-level snapshot
     * captured at construction. This is the safe default — an
     * attacker setting `X-Forwarded-Proto: https` over a plain-HTTP
     * connection can no longer flip HSTS on, disable the HTTPS
     * redirector, or force the `Secure` cookie flag.
     *
     * When a non-empty `$trustedProxies` list is passed, the
     * immediate connection's `REMOTE_ADDR` is checked against the
     * list (exact IPs and CIDR ranges, IPv4 + IPv6). The
     * `X-Forwarded-Proto` header is honored only on that match.
     * With an empty `$trustedProxies` list, behavior is identical
     * to the strict default.
     *
     * A `X-Forwarded-Proto` header with more than one comma-
     * separated value is NEVER honored, even from a trusted proxy —
     * the closest proxy in a multi-hop chain must be configured to
     * STRIP or REPLACE the header (not append), or the request is
     * treated as untrusted and the actual transport scheme wins.
     *
     * @param list<string>|null $trustedProxies  CIDRs / exact IPs
     *     allowed to set `X-Forwarded-Proto`. `null` (the default)
     *     and `[]` both disable header trust.
     * @param string|null $remoteAddr  Override the immediate
     *     connection address. Defaults to the `REMOTE_ADDR`
     *     snapshot captured at construction.
     * @param string|null $forwardedProto  Value of the
     *     `X-Forwarded-Proto` header. Defaults to `null` (header
     *     absent). Passed in by {@see Request} so the VO stays
     *     SAPI-agnostic.
     */
    public function isSecure(?array $trustedProxies = null, ?string $remoteAddr = null, ?string $forwardedProto = null): bool
    {
        if ($this->isSecure) {
            return true;
        }

        if ($forwardedProto === null || $forwardedProto === '') {
            return false;
        }

        if (str_contains($forwardedProto, ',')) {
            return false;
        }

        $first = strtolower(trim($forwardedProto));
        if ($first !== 'https') {
            return false;
        }

        $trustedProxies ??= $this->trustedProxies;
        if ($trustedProxies === null || $trustedProxies === []) {
            return false;
        }

        $candidate = $remoteAddr ?? $this->remoteAddr ?? '';
        if ($candidate === '') {
            return false;
        }

        return CidrMatcher::matchesAny($trustedProxies, $candidate);
    }

    /**
     * Return the client IP address.
     *
     * Default behavior (`$trustedProxies === null` or `[]`) is
     * **strict**: the value of `X-Forwarded-For` is NEVER
     * consulted, and the answer is the immediate connection's
     * `REMOTE_ADDR` — the SAPI snapshot captured at construction.
     * This is the safe default for a bare app that is not behind a
     * reverse proxy: an attacker setting `X-Forwarded-For: 1.2.3.4`
     * over a direct connection cannot spoof their key in a rate
     * limiter.
     *
     * When a non-empty `$trustedProxies` list is passed, the
     * immediate connection's `REMOTE_ADDR` is checked against the
     * list. Only on that match is `X-Forwarded-For` honored. The
     * returned value is the **leftmost** token of the header — the
     * leftmost address is the originating client, the rightmost
     * is the closest proxy.
     *
     * **Security caveat:** the leftmost token is **only as
     * trustworthy as the edge proxy**. If the edge is
     * misconfigured to APPEND to an inbound `X-Forwarded-For`
     * instead of REPLACE / STRIP, an attacker can prepend an
     * arbitrary value.
     *
     * Returns `null` when `REMOTE_ADDR` is missing or empty
     * (e.g. CLI / built-in server quirk / test fixture without
     * `$_SERVER`).
     *
     * @param list<string>|null $trustedProxies  CIDRs / exact IPs
     *     allowed to set `X-Forwarded-For`. `null` (the default)
     *     and `[]` both disable header trust.
     * @param string|null $remoteAddr  Override the immediate
     *     connection address. Defaults to the `REMOTE_ADDR`
     *     snapshot captured at construction.
     * @param string|null $forwardedFor  Value of the
     *     `X-Forwarded-For` header. Defaults to `null` (header
     *     absent). Passed in by {@see Request} so the VO stays
     *     SAPI-agnostic.
     */
    public function ip(?array $trustedProxies = null, ?string $remoteAddr = null, ?string $forwardedFor = null): ?string
    {
        $immediate = $remoteAddr ?? $this->remoteAddr ?? '';
        if ($immediate === '') {
            return null;
        }

        $trustedProxies ??= $this->trustedProxies;
        if ($trustedProxies === null || $trustedProxies === []) {
            return $immediate;
        }

        if (!CidrMatcher::matchesAny($trustedProxies, $immediate)) {
            return $immediate;
        }

        if ($forwardedFor === null || $forwardedFor === '') {
            return $immediate;
        }

        $first = trim(explode(',', $forwardedFor, 2)[0]);
        return $first === '' ? $immediate : $first;
    }

    /**
     * The actual transport-level scheme for the immediate
     * connection, honoring a trusted proxy's `X-Forwarded-Proto`
     * when the configured trust list matches the remote address.
     *
     * @param list<string>|null $trustedProxies  See {@see self::isSecure()}.
     * @param string|null $remoteAddr  See {@see self::isSecure()}.
     * @param string|null $forwardedProto  See {@see self::isSecure()}.
     */
    public function scheme(?array $trustedProxies = null, ?string $remoteAddr = null, ?string $forwardedProto = null): string
    {
        return $this->isSecure($trustedProxies, $remoteAddr, $forwardedProto) ? 'https' : 'http';
    }

    /**
     * Snapshot of `$_SERVER['HTTPS']` for the constructor. The PHP
     * SAPI sets `HTTPS=on` (or `1`) when the request is on TLS;
     * `off` and missing mean plain HTTP. Pulled out as a static
     * helper so the readonly constructor can call it as a
     * factory-side helper.
     */
    public static function snapshotTransportHttps(): bool
    {
        $value = $_SERVER['HTTPS'] ?? null;
        if (!is_string($value) || $value === '') {
            return false;
        }
        return strtolower($value) !== self::HTTPS_TRUTHY_DISABLED_VALUE;
    }

    /**
     * Snapshot of `$_SERVER['REMOTE_ADDR']` for the constructor.
     * The SAPI always populates this for HTTP requests; CLI / test
     * fixtures may leave it empty.
     */
    public static function snapshotRemoteAddr(): ?string
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Snapshot of `$_SERVER['HTTP_HOST']` for the constructor. The
     * Host header is captured at construction so that direct
     * construction with a custom host (e.g. in tests) does not
     * need `$_SERVER` to be primed.
     */
    public static function snapshotHostHeader(): ?string
    {
        $value = $_SERVER['HTTP_HOST'] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Match a single host against a single pattern. Exposed for
     * callers that want to apply the same matching semantics
     * outside of {@see self::host()} (e.g. tests asserting pattern
     * coverage).
     *
     * - `*.example.com` matches `example.com` itself and any
     *   single-label or multi-label subdomain (`api.example.com`,
     *   `a.b.example.com`).
     * - A bare label (`example.com`) matches only that exact host.
     */
    public static function hostMatchesPattern(string $host, string $pattern): bool
    {
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 2);
            return $host === $suffix || str_ends_with($host, '.' . $suffix);
        }
        return $host === $pattern;
    }
}
