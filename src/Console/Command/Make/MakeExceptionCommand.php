<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;
use Throwable;

final class MakeExceptionCommand extends Command
{
    private const DEFAULT_STATUS = 500;
    private const MIN_STATUS = 400;
    private const MAX_STATUS = 599;

    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace %namespace%;

use Framework\Http\Exception\HttpException;
use Throwable;

final class %class% extends HttpException
{
    public function __construct(string $message = %defaultMessage%, ?Throwable $previous = null)
    {
        parent::__construct(%status%, $message, 'about:blank', $previous);
    }
}
PHP;

    private readonly NamespaceResolver $namespaceResolver;

    public function __construct(
        ContainerInterface $container,
        private readonly string $exceptionDir,
        private readonly ?string $namespaceOverride = null,
        private readonly int $defaultStatus = self::DEFAULT_STATUS,
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
        ?NamespaceResolver $namespaceResolver = null,
    ) {
        parent::__construct($container);
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
    }

    public function name(): string
    {
        return 'make:exception';
    }

    public function description(): string
    {
        return 'Generate a new HTTP exception subclass';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:exception <Name> [--status=4xx|5xx] [--message="..."]');
            return 1;
        }

        $class = $this->validator->suffixed($raw, 'Exception');
        if ($class === '') {
            $output->danger('Invalid exception name. Use PascalCase (e.g. PaymentRequired, Teapot).');
            return 1;
        }
        $base = $this->validator->normalize($raw);

        $statusOption = $input->option('status');
        if ($statusOption === null || $statusOption === '') {
            $status = $this->defaultStatus;
        } elseif (!ctype_digit($statusOption)) {
            $output->danger("Invalid --status value '{$statusOption}': must be an integer.");
            return 1;
        } else {
            $status = (int) $statusOption;
            if ($status < self::MIN_STATUS || $status > self::MAX_STATUS) {
                $output->danger("Invalid status code {$status}: must be in " . self::MIN_STATUS . '-' . self::MAX_STATUS . ' (HTTP error range).');
                return 1;
            }
        }

        $message = $input->option('message') ?? 'TODO: describe ' . $class;

        $builtInSuffix = str_ends_with($base, 'HttpException') ? $base : $base . 'HttpException';
        $builtInFqcn = 'Framework\\Http\\Exception\\' . $builtInSuffix;
        if (class_exists($builtInFqcn)) {
            $output->danger("A built-in {$builtInFqcn} already exists for this name.");
            $output->info("Throw \\{$builtInFqcn} from your controller instead of generating a new {$class}.");
            return 1;
        }

        $path = rtrim($this->exceptionDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        if (!is_dir($this->exceptionDir) && !@mkdir($this->exceptionDir, 0o755, true) && !is_dir($this->exceptionDir)) {
            $output->danger("Failed to create directory: {$this->exceptionDir}");
            return 1;
        }

        $namespace = $this->namespaceOverride
            ?? $this->namespaceResolver->resolveForTargetDir($this->exceptionDir);

        $body = strtr(self::TEMPLATE, [
            '%namespace%' => $namespace,
            '%class%' => $class,
            '%status%' => (string) $status,
            '%defaultMessage%' => var_export($message, true),
        ]);

        $written = @file_put_contents($path, $body);
        if ($written === false) {
            $output->danger("Failed to write {$path}");
            return 1;
        }

        $output->success("Created {$path}");
        $output->info("Class: {$class}");
        $output->info("Namespace: {$namespace}");
        $output->info("Status: {$status}");
        $output->info('Next: throw this exception from a controller — HttpKernel renders it as application/problem+json.');
        return 0;
    }
}
