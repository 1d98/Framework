<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Request\Request;
use InvalidArgumentException;

final class SignedCookieJar
{
    public const DEFAULT_ALGORITHM = 'sha256';

    /**
     * Hash algorithms permitted for HMAC signing. Narrower than
     * `hash_algos()` on purpose — we want every cookie signed by
     * this class to be verifiable on any PHP 8.5+ install,
     * regardless of whether `sodium_*` / `xxhash` / `mhash` / etc.
     * are compiled in. Removing an algorithm from this list is a
     * BC break for any in-flight signed cookies; add new ones,
     * don't reorder.
     *
     * @var list<string>
     */
    public const array ALLOWED_ALGORITHMS = [
        'sha256',
        'sha384',
        'sha512',
        'sha3-256',
        'sha3-384',
        'sha3-512',
    ];

    /**
     * Minimum secret length in bytes. Anything shorter is rejected
     * at construction so that an operator who copy-pastes a stub
     * value (e.g. `"change-me"`) fails fast at boot, not at the
     * first forged-cookie incident. 16 bytes (128 bits) is the
     * floor for any HMAC use.
     */
    public const int MIN_SECRET_BYTES = 16;

    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = self::DEFAULT_ALGORITHM,
    ) {
        if (trim($this->secret) === '') {
            throw new InvalidArgumentException('SignedCookieJar: secret cannot be empty');
        }

        if (strlen($this->secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'SignedCookieJar: secret is too short (got %d bytes, minimum is %d). '
                . 'Generate a fresh secret with `php bin/framework app:secret`.',
                strlen($this->secret),
                self::MIN_SECRET_BYTES,
            ));
        }

        if (!in_array($this->algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw new InvalidArgumentException(sprintf(
                'SignedCookieJar: unsupported algorithm "%s" (allowed: %s)',
                $this->algorithm,
                implode(', ', self::ALLOWED_ALGORITHMS),
            ));
        }
    }

    public function sign(string $value): string
    {
        $sig = hash_hmac($this->algorithm, $value, $this->secret, true);
        return $value . '.' . $this->base64UrlEncode($sig);
    }

    /**
     * Check whether the raw cookie value is a properly signed payload.
     * Returns false if the signature is missing, malformed, base64-invalid,
     * or does not match the payload under the configured secret. Never throws.
     */
    public function verify(string $raw): bool
    {
        return $this->payload($raw) !== null;
    }

    /**
     * Extract the original payload from a signed cookie value, or null if
     * the value is missing a separator, has an invalid signature, or the
     * signature was produced under a different secret.
     */
    public function payload(string $signed): ?string
    {
        if (!str_contains($signed, '.')) {
            return null;
        }

        $parts = explode('.', $signed, 2);
        $value = $parts[0];
        $sigB64 = $parts[1];

        $sig = $this->base64UrlDecode($sigB64);
        if ($sig === null) {
            return null;
        }

        $expected = hash_hmac($this->algorithm, $value, $this->secret, true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return $value;
    }

    public function makeCookie(
        string $name,
        string $value,
        int $expiresAt = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): Cookie {
        return new Cookie(
            $name,
            $this->sign($value),
            $expiresAt,
            $path,
            $domain,
            $secure,
            $httpOnly,
            $sameSite,
        );
    }

    public function read(Request $request, string $name): ?string
    {
        $raw = $request->cookie($name);
        if ($raw === null) {
            return null;
        }

        return $this->payload($raw);
    }

    private function base64UrlEncode(string $data): string
    {
        $b64 = base64_encode($data);
        return rtrim(strtr($b64, '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): ?string
    {
        $b64 = strtr($data, '-_', '+/');
        $padding = strlen($b64) % 4;
        if ($padding > 0) {
            $b64 .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }
}
