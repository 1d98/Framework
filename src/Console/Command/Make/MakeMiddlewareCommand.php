<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class MakeMiddlewareCommand extends Command
{
    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace %namespace%;

use Framework\Http\Middleware\MiddlewareInterface;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

final class %class% implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        // TODO: implement middleware logic
        return $next($request);
    }
}
PHP;

    private readonly NamespaceResolver $namespaceResolver;

    public function __construct(
        ContainerInterface $container,
        private readonly string $middlewareDir,
        private readonly ?string $namespaceOverride = null,
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
        ?NamespaceResolver $namespaceResolver = null,
    ) {
        parent::__construct($container);
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
    }

    public function name(): string
    {
        return 'make:middleware';
    }

    public function description(): string
    {
        return 'Generate a new HTTP middleware class';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:middleware <Name>');
            return 1;
        }

        $class = $this->validator->suffixed($raw, 'Middleware');
        if ($class === '') {
            $output->danger('Invalid middleware name. Use PascalCase (e.g. Auth, RateLimit).');
            return 1;
        }

        $path = rtrim($this->middlewareDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        if (!is_dir($this->middlewareDir) && !@mkdir($this->middlewareDir, 0o755, true) && !is_dir($this->middlewareDir)) {
            $output->danger("Failed to create directory: {$this->middlewareDir}");
            return 1;
        }

        $namespace = $this->namespaceOverride
            ?? $this->namespaceResolver->resolveForTargetDir($this->middlewareDir);

        $body = strtr(self::TEMPLATE, [
            '%namespace%' => $namespace,
            '%class%' => $class,
        ]);

        $written = @file_put_contents($path, $body);
        if ($written === false) {
            $output->danger("Failed to write {$path}");
            return 1;
        }

        $output->success("Created {$path}");
        $output->info("Class: {$class}");
        $output->info("Namespace: {$namespace}");
        $output->info('Next: register the middleware in your HTTP pipeline (e.g. public/index.php).');
        return 0;
    }
}
