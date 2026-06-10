# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- (next-version items)

## [0.5.2] - 2026-06-10

### Added
- `Vary` value object for HTTP `Vary` header concatenation (`Framework\Http\Response\Vary`)
- `StatusText` value object with `public static function for(int $code): string` (`Framework\Http\Response\StatusText`)
- `ClassNameValidator::suffixed()` and `slug()` helpers (consolidates 4 previous methods)
- `docs/` reference documentation (installation, quickstart, kernel, validation, security, container, value-objects, config, embed guide)
- `CONTRIBUTING.md` with ground rules, local setup, PR checklist
- `bin/framework` is now exposed as `vendor/bin/framework` for Composer installs
- 6 agent-specific overlay files under `.agents/shared/hot/*/`
- CI runs `composer check` on every push/PR

### Changed
- `Response::REASON_PHRASES` extracted into `StatusText` VO; `Response::buildStatusLine()` delegates to it
- `Response::setStatus()` is now a deprecated alias for `withStatus()`
- `SecurityHeadersMiddleware` uses a `cspOverriddenByUser` boolean flag instead of a sentinel `===` comparison
- `RequestLogger` sanitizes exception messages (truncates to 256 chars, strips control chars) for OWASP A9 compliance
- `MultipartBodyParser` throws on malformed `$_FILES` entries instead of silently dropping them
- `MakeExceptionCommand` warns and exits non-zero when the requested name collides with a built-in HTTP exception
- README test count replaced with a link to the CI workflow (live source of truth)

### Fixed
- `examples/full-app.php:26` — broken autoload path (`/vendor` → `/../vendor`)
- `public/index.php` — hardcoded version `'0.4.0'` replaced with `Framework::VERSION` constant
- `src/Http/UploadedFile.php` — `@`-error-suppression removed; failures now throw with the underlying PHP error message
- `src/Http/Middleware/RateLimitMiddleware.php` — `private static array $buckets` initialized to `[]`
- `src/Http/Middleware/CorsMiddleware.php` and `CompressionMiddleware.php` — `Vary` merge logic deduplicated
- Cross-platform: `TempFilePool` no longer normalizes path separators (works identically on Linux/macOS/Windows)
- Cross-platform: `TempFilePool::release()` no longer deletes the parent directory (was a security/portability hazard)
- Cross-platform: `tests/Support/LiveHttpTestCase.php` — `SIGTERM` is now optional on Windows

### Security
- `HttpsRedirectMiddleware` — multi-value `X-Forwarded-Proto` from a trusted proxy is rejected (chain-spoofing defense, covered by a new test)
- `CompressionMiddleware` — `Vary` header is now set correctly to `Accept-Encoding` when compression is on
- `RequestLogger` — exception messages redacted before logging (256-char cap, control chars stripped, CRLF collapsed to space)

[0.5.2]: https://github.com/1d98/framework/releases/tag/v0.5.2
[Unreleased]: https://github.com/1d98/framework/compare/v0.5.2...HEAD
