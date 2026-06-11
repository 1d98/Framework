# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- (next-version items)

## [0.5.3] - 2026-06-11

### Added
- `MultipartBodyParser::maxPartBytes` ctor arg — per-part cap (separate from the existing cumulative `maxBodyBytes` cap). Defaults to `MultipartParser::MAX_PART_BYTES` (64 MiB).

### Changed
- `CorsMiddleware` normalizes the `Origin` header to lowercase before the whitelist match (RFC 6454 case-insensitivity for scheme/host).
- `StatusText::for()` return type changed from `string` to `?string`; returns `null` for codes outside the maintained IANA registry.
- `Response::buildStatusLine()` substitutes an empty reason phrase for `null` (no more `'Unknown'` sentinel in the wire format).
- `bin/framework` resolves the initial debug flag from the `APP_DEBUG` env var via a new `envDebug()` helper and passes it to `Application::__construct` so the ctor `$debug` arg is no longer dead in the shipped entry point.
- All 7 `make:*` commands (`make:command`, `make:controller`, `make:exception`, `make:middleware`, `make:rule`, `make:dto`, `make:controller`) print `$output->info("Class: {$class}")` so the user sees the normalized class name (snake_case → PascalCase) that was actually written.

### Fixed
- `MultipartEnvelope::assertContentLengthMatches()` now uses `ctype_digit()` instead of `is_numeric()`; `Content-Length: 1e10` and whitespace-padded numerics (`  5  `) are now rejected with a clear 400. Aligns with the `ctype_digit` check already in `RequestFactory::assertContentLengthWithinCap()`.
- The misleading comment in `MultipartBodyParser::process()` referring to "per-PART cap" now points at the new `$maxPartBytes` field instead of the cumulative cap.

### Deprecated
- `Response::setStatus()` — use `Response::withStatus()` instead. The method is kept for backward compatibility; new code should use the immutable builder.
- `Request::withTrustedProxies()` — now also marked `@internal` in addition to the existing `@deprecated since 0.5.1`; will be removed in the next minor release. New code must use `Request::withHost()` with a `RequestHost` VO.

### Documentation
- `Route::withPrefix()` PHPDoc expanded; explicitly states the original instance is not mutated and adds a `@return self` tag.
- `Request::readStreamWithCap()` PHPDoc expanded; adds `@see RequestFactory::readStreamWithCap()` and notes that new code should call the factory method directly.
- `Application::__construct` `$debug` PHPDoc expanded; explains the `null` = "fall back to env" semantics.

## [0.5.2] - 2026-06-10

### Added
- `Vary` value object for HTTP `Vary` header concatenation (`Framework\Http\Response\Vary`)
- `StatusText` value object with `public static function for(int $code): string` (`Framework\Http\Response\StatusText`)
- `ClassNameValidator::suffixed()` and `slug()` helpers (consolidates 4 previous methods)
- `docs/` reference documentation (installation, quickstart, kernel, validation, security, container, value-objects, config, embed guide)
- `CONTRIBUTING.md` with ground rules, local setup, PR checklist
- `bin/framework` is now exposed as `vendor/bin/framework` for Composer installs
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
[0.5.3]: https://github.com/1d98/framework/releases/tag/v0.5.3
[Unreleased]: https://github.com/1d98/framework/compare/v0.5.3...HEAD
