<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

/**
 * Maps a target directory (e.g. `src/Http/Controller`) to a
 * fully-qualified PHP namespace by reading the nearest
 * `composer.json` (the consumer project's, not the framework's).
 *
 * The framework's own repo-mode scaffolder hands the developer
 * paths inside `src/...` whose namespace is `Framework\...` —
 * the existing default. For consumer projects the scaffolder
 * wants the namespace that the consumer has registered in
 * `autoload.psr-4` for that subdirectory. The previous hard-coded
 * `App\…` default produced files that did not autoload for any
 * project that used `App\…` mapped to `app/…` (the most common
 * convention), so the scaffolder silently created dead code.
 *
 * Algorithm:
 *  - Walk up from `$targetDir` looking for a `composer.json`
 *    that has a `psr-4` mapping whose absolute path is a prefix
 *    of `$targetDir`. Pick the most specific (longest path).
 *  - Compose the namespace as
 *    `psr4_prefix + (targetDir relative to psr4_path with `/`
 *    replaced by `\` and the trailing `\` removed)`.
 *  - If nothing matches (e.g. consumer hasn't configured PSR-4
 *    for the subdir, or the project uses classmap autoloading),
 *    fall back to a generic `App\<relative subdir as ns>`
 *    so the file is at least self-consistent — the developer
 *    can edit it.
 *
 * Hard-coded `Framework\…` namespaces are intentionally NOT used
 * as a fallback for consumer paths: a project that ran
 * `composer require 1d98/framework` should not be writing files
 * into the framework's own namespace.
 */
final class NamespaceResolver
{
    /**
     * Upper bound on the number of parent directories we walk up
     * looking for `composer.json`. The framework's own repo reaches
     * the root in 4 levels (`src/Console/Command/Make` → `Make` →
     * `Command` → `Console` → `src` → project root); 6 leaves two
     * extra hops of headroom for monorepos / nested layouts without
     * letting a misconfigured symlink loop walk forever.
     */
    private const int MAX_WALK_LEVELS = 6;

    public function resolveForTargetDir(string $targetDir): string
    {
        $abs = $this->absolutePath(rtrim($targetDir, '/'));

        $dir = $abs;
        for ($i = 0; $i < self::MAX_WALK_LEVELS; $i++) {
            $composer = $dir . '/composer.json';
            if (is_file($composer)) {
                $namespace = $this->namespaceFromComposer($composer, $abs);
                if ($namespace !== null) {
                    return $namespace;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return $this->fallbackNamespace($abs, $dir);
    }

    /**
     * @return class-string|null Fully-qualified namespace for
     *     `$absTargetDir`, or null when no PSR-4 mapping covers
     *     it.
     */
    private function namespaceFromComposer(string $composerPath, string $absTargetDir): ?string
    {
        $raw = (string) file_get_contents($composerPath);
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        $autoload = $decoded['autoload'] ?? [];
        $autoloadDev = $decoded['autoload-dev'] ?? [];
        if (!is_array($autoload) || !is_array($autoloadDev)) {
            return null;
        }

        $candidates = [];
        foreach ([$autoload, $autoloadDev] as $payload) {
            /** @var array<string, mixed> $payload */
            $psr4 = $payload['psr-4'] ?? null;
            if (!is_array($psr4)) {
                continue;
            }
            foreach ($psr4 as $prefix => $paths) {
                if (!is_string($prefix) || $prefix === '') {
                    continue;
                }
                $pathList = is_array($paths) ? $paths : [$paths];
                foreach ($pathList as $relPath) {
                    if (!is_string($relPath) || $relPath === '') {
                        continue;
                    }
                    $absBase = $this->normalizePath(dirname($composerPath) . '/' . $relPath);
                    if (!$this->isUnder($absTargetDir, $absBase)) {
                        continue;
                    }
                    $candidates[] = [
                        'prefix' => rtrim($prefix, '\\') . '\\',
                        'absBase' => $absBase,
                        'specificity' => strlen($absBase),
                    ];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int
            => $b['specificity'] <=> $a['specificity']);

        $best = $candidates[0];
        $tail = substr($absTargetDir, strlen($best['absBase']));
        $tail = trim(str_replace('\\', '/', $tail), '/');
        if ($tail === '') {
            /** @var class-string */
            return rtrim($best['prefix'], '\\');
        }
        /** @var class-string */
        return rtrim($best['prefix'], '\\') . '\\' . str_replace('/', '\\', $tail);
    }

    /**
     * Compose a best-effort namespace when no `composer.json` PSR-4
     * mapping covers `$absTargetDir` (e.g. the consumer project
     * hasn't configured autoload for the subdirectory, or the
     * walk-up walked past every project root).
     *
     * Heuristic:
     *  - If `$absTargetDir` is under the current working directory,
     *    use the path *relative to* the CWD (so `src/Http/Controller`
     *    under CWD `/Users/x64off/Framework` → `App\Http\Controller`).
     *  - Otherwise, fall back to the **last two path segments** of
     *    the absolute path (so a path like `/var/cache` becomes
     *    `App\var\cache` — the choice of two segments is arbitrary
     *    but gives stable, self-explanatory namespaces for paths
     *    outside the project tree, e.g. transient tmpdirs used in
     *    tests).
     *
     * The fallback is always prefixed with `App\` (or is the literal
     * `App` when the relative tail is empty) — it never falls back
     * to `Framework\…` because consumer projects should not write
     * into the framework's own namespace.
     */
    private function fallbackNamespace(string $absTargetDir, string $walkedTo): string
    {
        $cwd = $this->normalizePath((string) getcwd());
        $tail = '';
        if ($cwd !== '' && str_starts_with($absTargetDir, $cwd . '/')) {
            $tail = substr($absTargetDir, strlen($cwd) + 1);
        } else {
            $parts = explode('/', $absTargetDir);
            $count = count($parts);
            $start = max(0, $count - 2);
            if ($count > 0 && $parts[$count - 1] !== '') {
                $tail = implode('/', array_slice($parts, $start));
            }
        }
        $ns = str_replace('/', '\\', $tail);
        return $ns === '' ? 'App' : 'App\\' . $ns;
    }

    private function isUnder(string $path, string $base): bool
    {
        if ($path === $base) {
            return true;
        }
        return str_starts_with($path, $base . '/');
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $resolved = realpath($path);
        if (is_string($resolved)) {
            $path = $resolved;
        }
        return rtrim($path, '/');
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return $this->normalizePath((string) getcwd());
        }
        if ($path[0] === '/' || (strlen($path) >= 2 && $path[1] === ':')) {
            return $this->normalizePath($path);
        }
        return $this->normalizePath(((string) getcwd()) . '/' . $path);
    }
}
