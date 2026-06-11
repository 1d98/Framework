<?php

declare(strict_types=1);

namespace Framework\Console\Command\Make;

use Framework\Console\Command\Command;
use Framework\Console\Input\InputInterface;
use Framework\Console\Output\OutputInterface;
use Framework\Container\ContainerInterface;

final class MakeControllerCommand extends Command
{
    private const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace %namespace%;

use Framework\Http\Request\Request;
use Framework\Http\Response\Response;

final readonly class %class%Controller
{
    public function index(Request $request): Response
    {
        return Response::empty(200);
    }
}
PHP;

    public function __construct(
        ContainerInterface $container,
        private readonly string $controllerDir,
        private readonly string $namespace = 'App\Http\Controller',
        private readonly ClassNameValidator $validator = new ClassNameValidator(),
    ) {
        parent::__construct($container);
    }

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Generate a new HTTP controller class';
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->arg(1);
        if ($raw === null || $raw === '') {
            $output->danger('Usage: make:controller <Name> [--name=slug] [--description="..."]');
            return 1;
        }

        $class = $this->validator->suffixed($raw, 'Controller');
        if ($class === '') {
            $output->danger('Invalid controller name. Use PascalCase (e.g. Home, UserProfile).');
            return 1;
        }

        $name = $input->option('name') ?? $this->validator->slug($class, 'Controller');
        $description = $input->option('description') ?? 'TODO: describe ' . $class;

        $path = rtrim($this->controllerDir, '/') . '/' . $class . '.php';
        if (file_exists($path)) {
            $output->danger("File already exists: {$path}");
            return 1;
        }

        if (!is_dir($this->controllerDir) && !@mkdir($this->controllerDir, 0o755, true) && !is_dir($this->controllerDir)) {
            $output->danger("Failed to create directory: {$this->controllerDir}");
            return 1;
        }

        $body = strtr(self::TEMPLATE, [
            '%namespace%' => $this->namespace,
            '%class%' => $class,
        ]);

        $written = @file_put_contents($path, $body);
        if ($written === false) {
            $output->danger("Failed to write {$path}");
            return 1;
        }

        $output->success("Created {$path}");
        $output->info("Class: {$class}");
        $output->info("Controller route slug: {$name}");
        $output->info("Description: {$description}");
        $output->info('Next: register the controller in your router (e.g. public/index.php).');
        return 0;
    }
}
