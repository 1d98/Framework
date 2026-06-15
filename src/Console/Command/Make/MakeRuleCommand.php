<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class MakeRuleCommand extends Command
{
    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace %namespace%;

%description%final class %class% implements RuleInterface
{
    public function validate(mixed $value, array $params): ?string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9-]+$/', $value)) {
            return '%name%: must be a slug';
        }
        return null;
    }

    public function name(): string
    {
        return '%name%';
    }

    public function params(): array
    {
        return [];
    }
}
PHP;

    private readonly NamespaceResolver $namespaceResolver;

    public function __construct(
        ContainerInterface $container,
        private readonly string $rulesDir,
        private readonly ?string $namespaceOverride = null,
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
        ?NamespaceResolver $namespaceResolver = null,
    ) {
        parent::__construct($container);
        $this->namespaceResolver = $namespaceResolver ?? new NamespaceResolver();
    }

    public function name(): string
    {
        return 'make:rule';
    }

    public function description(): string
    {
        return 'Generate a new validation rule class';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:rule <Name> [--name=rule-name] [--description="..."]');
            return 1;
        }

        $class = $this->validator->suffixed($raw, 'Rule');
        if ($class === '') {
            $output->danger('Invalid rule name. Use PascalCase (e.g. Slug, EmailDomain).');
            return 1;
        }

        $name = $input->option('name') ?? $this->validator->slug($class, 'Rule');
        $description = $input->option('description');
        $docBlock = $description === null ? '' : $this->docBlock($description);

        $path = rtrim($this->rulesDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        if (!is_dir($this->rulesDir) && !@mkdir($this->rulesDir, 0o755, true) && !is_dir($this->rulesDir)) {
            $output->danger("Failed to create directory: {$this->rulesDir}");
            return 1;
        }

        $namespace = $this->namespaceOverride
            ?? $this->namespaceResolver->resolveForTargetDir($this->rulesDir);

        $body = strtr(self::TEMPLATE, [
            '%namespace%' => $namespace,
            '%class%' => $class,
            '%name%' => $name,
            '%description%' => $docBlock,
        ]);

        $written = @file_put_contents($path, $body);
        if ($written === false) {
            $output->danger("Failed to write {$path}");
            return 1;
        }

        $output->success("Created {$path}");
        $output->info("Class: {$class}");
        $output->info("Namespace: {$namespace}");
        $output->info('Next: register the rule in your RuleRegistry (or use a #[Validate] shorthand).');
        return 0;
    }

    private function docBlock(string $description): string
    {
        $cleaned = $this->sanitizeDocBlockDescription($description);
        if ($cleaned === '') {
            return '';
        }
        $lines = explode("\n", $cleaned);
        $text = "/**\n * " . implode("\n * ", $lines) . "\n */\n";
        return $text;
    }

    /**
     * Strip the byte sequences that open or close a PHPDoc comment
     * (slash-star and star-slash) and strip CR / NUL so user-supplied
     * --description cannot inject PHP into the generated file. Multiple
     * lines and leading whitespace are preserved. An empty result means
     * the description was nothing but meta-characters and we drop the
     * docblock entirely.
     */
    private function sanitizeDocBlockDescription(string $description): string
    {
        $stripped = str_replace(["*/", "/*", "\r", "\0"], ['', '', '', ''], $description);
        $collapsed = preg_replace('/[ \t]+\n/', "\n", $stripped) ?? $stripped;
        return trim($collapsed);
    }
}
