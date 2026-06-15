<?php

declare(strict_types=1);

namespace Framework\Console\Command;

use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;
use Framework\Http\Router\Router;
use Framework\OpenApi\OpenApiExporter;

/**
 * `php bin/framework routes:openapi --out public/openapi.json` —
 * serialises the registered route table to an OpenAPI 3.1
 * document and either prints to stdout or writes to a file.
 *
 * The exporter is configured by the consuming app at boot time
 * (title, version, decorator hook); the command is a thin
 * dispatcher that does not carry any OpenAPI knowledge of its
 * own.
 */
final class RoutesOpenApiCommand extends Command
{
    public function __construct(
        ContainerInterface $c,
        private readonly Router $router,
        private readonly OpenApiExporter $exporter,
    ) {
        parent::__construct($c);
    }

    public function name(): string
    {
        return 'routes:openapi';
    }

    public function description(): string
    {
        return 'Export the route table as an OpenAPI 3.1 document (--out <path> to write to file)';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $document = $this->exporter->build($this->router);
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
