# Contributing

Thanks for your interest. This framework is intentionally small and opinionated.

## Ground rules
1. **No runtime dependencies.** New features must use only the PHP standard library.
2. **PHP 8.5+ syntax** is fair game: readonly classes, asymmetric visibility, `#[\Attribute]`, `enum`, etc.
3. **PHPStan level max** must pass on every commit (`composer stan`).
4. **Tests are mandatory.** New code ships with `tests/Unit/...` and (when relevant) `tests/Integration/...`.
5. **No new top-level namespaces** without a prior design discussion in an issue.
6. **Immutability by default.** Mutable state goes behind a clearly-named factory.

## Local setup
```bash
composer install
composer test     # PHPUnit
composer stan     # PHPStan level max
composer check    # both
```

## Code style
- `declare(strict_types=1);` in every file.
- `final readonly class` for value objects.
- One class per file; PSR-4 under `Framework\`.
- Comments only when they explain a non-obvious invariant (security caveats, magic numbers). Never `// TODO: implement` in committed code.

## Pull request checklist
- [ ] `composer check` is green locally
- [ ] Tests cover the new code path (happy + at least one error path)
- [ ] No new PHPStan ignore comments
- [ ] Public API additions documented in `docs/` (or an issue noting the doc-debt)
- [ ] Commit messages are imperative-mood, ≤72 char subject, body explains *why*

## CI
GitHub Actions (`.github/workflows/ci.yml`) runs `composer validate`, `composer install`, then `composer check` on PHP 8.5 across Ubuntu, Windows, and macOS. A PR cannot be merged while CI is red.

## Release process
Maintainers cut a release by:
1. Bumping `Framework::VERSION` and `VERSION_TRIPLE` in `src/Framework.php`
2. Updating `CHANGELOG.md` (Keep-a-Changelog format)
3. Tagging `vX.Y.Z`; CI publishes the package
