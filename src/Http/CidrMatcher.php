<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * IPv4 + IPv6 CIDR matching, in pure PHP. No dependencies.
 *
 * Both inputs (network + candidate) are accepted in any of these
 * forms:
 *   - Exact IPv4 / IPv6 literal: `192.0.2.1`, `::1`, `2001:db8::1`
 *   - CIDR notation: `10.0.0.0/8`, `192.168.0.0/16`,
 *     `::1/128`, `2001:db8::/32`
 *
 * IPv4-mapped IPv6 candidates (e.g. `::ffff:192.0.2.1` — the form
 * Apache's `[::]:443` listener, Cloudflare, and AWS ALB emit) are
 * normalized to their embedded IPv4 form before matching against
 * an IPv4 network. This means a single v4 CIDR like
 * `10.0.0.0/8` in `APP_TRUSTED_PROXIES` matches both `10.0.0.5`
 * and `::ffff:10.0.0.5` without a separate v6 entry. Pure v6
 * candidates (no `::ffff:` prefix) are passed through unchanged.
 *
 * IPv4 and IPv6 are kept strictly separate: an IPv4 candidate
 * never matches an IPv6 network, and vice versa. The original
 * `REMOTE_ADDR` is never normalized in the response — callers
 * get back exactly what their server recorded.
 */
final class CidrMatcher
{
    /**
     * Test whether `$candidate` belongs to `$network`. `$network`
     * is a CIDR (e.g. `10.0.0.0/8`) or a bare address
     * (equivalent to `/32` for IPv4 or `/128` for IPv6).
     *
     * @param non-empty-string $network  CIDR or exact address.
     * @param non-empty-string $candidate  Address to test.
     */
    public static function matches(string $network, string $candidate): bool
    {
        [$netAddr, $netPrefix] = self::parseCidr($network);

        $netIsV6 = str_contains($netAddr, ':');
        $candidate = $netIsV6 ? $candidate : self::normalizeIpv4Mapped($candidate);

        $candidateIsV6 = str_contains($candidate, ':');

        if ($candidateIsV6 !== $netIsV6) {
            return false;
        }

        $candidateBytes = self::addressToBytes($candidate, $candidateIsV6);
        if ($candidateBytes === null) {
            return false;
        }

        $netBytes = self::addressToBytes($netAddr, $netIsV6);
        if ($netBytes === null) {
            return false;
        }

        $totalBits = $netIsV6 ? 128 : 32;
        $prefix = $netPrefix ?? $totalBits;

        $fullBytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if ($netBytes[$i] !== $candidateBytes[$i]) {
                return false;
            }
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;
        return ($netBytes[$fullBytes] & $mask) === ($candidateBytes[$fullBytes] & $mask);
    }

    /**
     * Test whether `$candidate` matches **any** entry in `$networks`.
     * Empty / blank entries are ignored.
     *
     * @param list<string> $networks
     * @param non-empty-string $candidate
     */
    public static function matchesAny(array $networks, string $candidate): bool
    {
        foreach ($networks as $network) {
            if (!is_string($network)) {
                continue;
            }
            $network = trim($network);
            if ($network === '') {
                continue;
            }
            if (self::matches($network, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function parseCidr(string $network): array
    {
        if (!str_contains($network, '/')) {
            return [$network, null];
        }
        [$addr, $prefix] = explode('/', $network, 2);
        if ($prefix === '' || !ctype_digit($prefix)) {
            return [$network, null];
        }
        return [$addr, (int) $prefix];
    }

    /**
     * Normalize an IPv4-mapped IPv6 candidate to its embedded IPv4
     * form. `::ffff:192.0.2.1` → `192.0.2.1`. The original form is
     * widely emitted by IPv6 listeners (Apache `Listen [::]:443`,
     * Cloudflare, AWS ALB) and by reverse proxies that preserve
     * the v4-in-v6 wrapping from the upstream socket. Matching the
     * embedded v4 against a v4 CIDR in `APP_TRUSTED_PROXIES` is
     * almost always what the operator intends.
     *
     * If the candidate does not have the `::ffff:` prefix, or the
     * embedded part is not a valid IPv4 literal, the candidate is
     * returned unchanged. The check is case-insensitive on the
     * prefix; the embedded v4 is validated via `inet_pton`.
     */
    private static function normalizeIpv4Mapped(string $candidate): string
    {
        if (!str_starts_with(strtolower($candidate), '::ffff:')) {
            return $candidate;
        }
        $embedded = substr($candidate, 7);
        $packed = @inet_pton($embedded);
        if (!is_string($packed) || strlen($packed) !== 4) {
            return $candidate;
        }
        return $embedded;
    }

    /**
     * @return list<int>|null
     */
    private static function addressToBytes(string $address, bool $isV6): ?array
    {
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return null;
        }
        if ($isV6 && strlen($packed) !== 16) {
            return null;
        }
        if (!$isV6 && strlen($packed) !== 4) {
            return null;
        }
        $unpacked = unpack('C*', $packed);
        if (!is_array($unpacked)) {
            return null;
        }
        /** @var list<int> $list */
        $list = array_values($unpacked);
        return $list;
    }
}
