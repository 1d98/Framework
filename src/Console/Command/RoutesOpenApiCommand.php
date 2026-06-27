<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;
use Framework\Http\Router\Router;
use Framework\Logging\LoggerInterface;
use Framework\OpenApi\OpenApiExporter;

/**
 * `php bin/framework routes:openapi --out public/openapi.json` —
 * serialises the registered route table to an OpenAPI 3.1
 * document and either prints to stdout or writes to a file.
 *
 * Pass `--exclude=/_internal/,/admin/` to drop matching routes
 * (literal prefix match — see {@see OpenApiExporter::withExcludePatterns()}
 * for regex syntax). Pass `--exclude` once with a comma-separated list.
 *
 * Patterns shorter than {@see self::MIN_EXCLUDE_PATTERN_LENGTH}
 * characters are rejected with a warning: the shortest sensible
 * regex is `/#/`, i.e. a single-character class between matching
 * delimiters. Empty and 2-char patterns (`//`, `##`, `~~`, `||`,
 * `!!`, `%%`, `@@`) all look like a regex delimiter pair to
 * {@see OpenApiExporter::looksLikeRegex()} but `preg_match('//',
 * $anyPath) === 1` — an empty pattern matches every position and
 * would silently produce an empty OpenAPI document. We refuse
 * the pattern and warn instead of crashing so operators using
 * the CLI get feedback, not surprises.
 *
 * The exporter is configured by the consuming app at boot time
 * (title, version, decorator hook); the command is a thin
 * dispatcher that does not carry any OpenAPI knowledge of its
 * own.
 */
final class RoutesOpenApiCommand extends Command
{
    /**
     * Minimum length of an `--exclude` pattern. The shortest sensible
     * regex is `/#/` (a one-char class between delimiters); anything
     * shorter than that cannot express intent and risks collapsing to
     * an "empty regex matches everything" trap. Two-char same-
     * delimiter pairs are exactly that trap.
     */
    private const int MIN_EXCLUDE_PATTERN_LENGTH = 4;

    public function __construct(
        ContainerInterface $c,
        private readonly Router $router,
        private readonly OpenApiExporter $exporter,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct($c);
    }

    public function name(): string
    {
        return 'routes:openapi';
    }

    public function description(): string
    {
        return 'Export the route table as an OpenAPI 3.1 document (--out <path> to write to file, --exclude p1,p2 to drop paths)';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $exporter = $this->exporter;

        $excludeRaw = $input->option('exclude');
        if ($excludeRaw !== null && $excludeRaw !== '') {
            $patterns = [];
            foreach (explode(',', $excludeRaw) as $piece) {
                $trimmed = trim($piece);
                if ($trimmed === '') {
                    continue;
                }
                if (strlen($trimmed) < self::MIN_EXCLUDE_PATTERN_LENGTH) {
                    $message = "Skipping too-short exclude pattern: '{$trimmed}' (min " . self::MIN_EXCLUDE_PATTERN_LENGTH . ' chars)';
                    $output->warning($message);
                    $this->logger?->warning($message, ['pattern' => $trimmed]);
                    continue;
                }
                $patterns[] = $trimmed;
            }
            if ($patterns !== []) {
                $exporter = $exporter->withExcludePatterns($patterns);
            }
        }

        $document = $exporter->build($this->router);
        $json = $document->toJson(JSON_PRETTY_PRINT);

        $outPath = $input->option('out');
        if ($outPath !== null && $outPath !== '') {
            $bytes = @file_put_contents($outPath, $json);
            if ($bytes === false) {
                $output->danger("Failed to write OpenAPI document to {$outPath}");
                return 1;
            }
            $output->success("Wrote {$bytes} bytes to {$outPath}");
            return 0;
        }

        $output->write($json);
        return 0;
    }
}
