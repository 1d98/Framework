<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class MakeDtoCommand extends Command
{
    private const DEFAULT_SUFFIX = 'Request';

    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace %namespace%;

use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\EmailRule;

/**
 * TODO: add #[Validate(...)] attributes on each property.
 * Example: #[Validate(EmailRule::class)] on a `string $email` property.
 */
final readonly class %class%
{
    public function __construct(
        public string $example,
    ) {}
}
PHP;

    private readonly NamespaceResolver $namespaceResolver;

    public function __construct(
        ContainerInterface $container,
        private readonly string $dtoDir,
        private readonly string $defaultSuffix = self::DEFAULT_SUFFIX,
        private readonly ?string $namespaceOverride = null,
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
        ?NamespaceResolver $namespaceResolver = null,
    ) {
        parent::__construct($container);
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
    }

    public function name(): string
    {
        return 'make:dto';
    }

    public function description(): string
    {
        return 'Generate a new validation DTO class';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:dto <Name> [--suffix=Request]');
            return 1;
        }

        $suffix = $input->option('suffix') ?? $this->defaultSuffix;
        if ($suffix === '') {
            $suffix = '';
        }

        $class = $this->validator->suffixed($raw, $suffix);
        if ($class === '') {
            $output->danger('Invalid DTO name. Use PascalCase (e.g. CreateUser, UpdateProfile).');
            return 1;
        }

        $path = rtrim($this->dtoDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        if (!is_dir($this->dtoDir) && !@mkdir($this->dtoDir, 0o755, true) && !is_dir($this->dtoDir)) {
            $output->danger("Failed to create directory: {$this->dtoDir}");
            return 1;
        }

        $namespace = $this->namespaceOverride
            ?? $this->namespaceResolver->resolveForTargetDir($this->dtoDir);

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
        $output->info('Next: add #[Validate(...)] attributes to your properties and bind with $request->bind(' . $class . '::class).');
        return 0;
    }
}
