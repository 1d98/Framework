<?php

declare(strict_types=1);

use Framework\Container\Container;
use Framework\Framework;
use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Exception\NotFoundHttpException;
use Framework\Http\HttpKernel;
use Framework\Http\Middleware\CompressionMiddleware;
use Framework\Http\Middleware\CorsMiddleware;
use Framework\Http\Middleware\FormBodyParser;
use Framework\Http\Middleware\HttpsRedirectMiddleware;
use Framework\Http\Middleware\JsonBodyParser;
use Framework\Http\Middleware\MultipartBodyParser;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Middleware\SecurityHeadersMiddleware;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Config\Env;
use Framework\Logging\LoggerInterface;
use Framework\Logging\StreamLogger;
use Framework\Security\AppSecretValidator;
use Framework\Security\CsrfMiddleware;
use Framework\Security\SignedCookieJar;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\MaxRule;
use Framework\Validation\Rule\MinRule;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$debug = getenv('APP_DEBUG') === '1';
$appEnv = getenv('APP_ENV') ?: 'dev';

$trustedHosts = array_values(array_filter(
    array_map('trim', explode(',', getenv('APP_TRUSTED_HOSTS') ?: '')),
    static fn(string $h): bool => $h !== '',
));
if ($trustedHosts === []) {
    $trustedHosts = Request::TRUSTED_HOSTS_DEFAULT;
}

$trustedProxies = array_values(array_filter(
    array_map('trim', explode(',', getenv('APP_TRUSTED_PROXIES') ?: '')),
    static fn(string $p): bool => $p !== '',
));

$container = new Container();
$container->set(LoggerInterface::class, static fn(): LoggerInterface => StreamLogger::stderr());
$container->set(JsonBodyParser::class, static fn(): JsonBodyParser => new JsonBodyParser());
$container->set(FormBodyParser::class, static fn(): FormBodyParser => new FormBodyParser());
$uploadTmpDir = getenv('APP_UPLOAD_TMP_DIR') ?: __DIR__ . '/../var/tmp';
$container->set(MultipartBodyParser::class, static fn(): MultipartBodyParser => new MultipartBodyParser(tmpDir: $uploadTmpDir));
$container->set(HttpsRedirectMiddleware::class, static fn(): HttpsRedirectMiddleware => new HttpsRedirectMiddleware(
    trustedHosts: $trustedHosts,
    trustedProxies: $trustedProxies,
));
$container->set(CorsMiddleware::class, static fn(): CorsMiddleware => new CorsMiddleware(
    origins: ['http://localhost:3000', 'http://localhost:5173'],
    credentials: true,
    exposeHeaders: ['X-Total-Count'],
));
$container->set(SecurityHeadersMiddleware::class, static fn(): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
    trustedProxies: $trustedProxies,
));
$container->set(CompressionMiddleware::class, static fn(): CompressionMiddleware => new CompressionMiddleware());
$container->set(RuleRegistry::class, static fn(): RuleRegistry => new RuleRegistry());
$container->set(Validator::class, static fn(Container $c): Validator
    => new Validator($c->get(RuleRegistry::class)));
$appSecret = getenv('APP_SECRET') ?: 'dev-only-secret-change-in-prod';
AppSecretValidator::assertProductionSafe($appSecret, $appEnv);
$container->set(SignedCookieJar::class, static fn(): SignedCookieJar => new SignedCookieJar(
    secret: $appSecret,
    algorithm: 'sha256',
));
$container->set(CsrfMiddleware::class, static fn(): CsrfMiddleware => new CsrfMiddleware(
    jar: $container->get(SignedCookieJar::class),
    exemptPrefixes: ['/api/'],
    trustedProxies: $trustedProxies,
));
$logger = $container->get(LoggerInterface::class);
if (AppSecretValidator::isKnownDevDefault($appSecret)) {
    $logger->warning('AppSecretValidator: APP_SECRET is a well-known development default; do not deploy to production as-is.');
}

$router = new Router();

$router->get('/', static fn(Request $r): Response => Response::html(
    '<!DOCTYPE html><html><head><title>Framework</title></head><body><h1>Framework v' . Framework::VERSION . '</h1>'
    . '<p>Try <a href="/form">/form</a> for CSRF demo.</p></body></html>',
));
$router->get('/json', static fn(Request $r): Response => Response::json([
    'framework' => 'PHP 8.5',
    'version' => Framework::VERSION,
    'method' => $r->method,
    'path' => $r->path,
    'debug' => $debug,
]));
$router->get('/hello/{name}', static fn(Request $r, array $p): Response => Response::text(
    "Hello, {$p['name']}!",
));
$router->get('/form', static fn(Request $r): Response => Response::html(
    '<!DOCTYPE html><html><body>'
    . '<form method="POST" action="/submit">'
    . '<input type="hidden" name="_token" value="' . htmlspecialchars($r->csrfToken() ?? '', ENT_QUOTES) . '">'
    . '<input type="text" name="name" placeholder="Your name">'
    . '<button type="submit">Submit</button>'
    . '</form></body></html>',
));
$router->post('/submit', static function (Request $req): Response {
    $token = $req->csrfToken() ?? '';
    if ($token === '') {
        throw new BadRequestHttpException('CSRF token not present in request context');
    }
    $form = $req->form() ?? [];
    return Response::json([
        'ok' => true,
        'name' => $form['name'] ?? null,
        'csrf_token' => $token,
    ]);
});

if ($appEnv !== 'prod') {
    $router->get('/boom', static function (): void {
        throw new NotFoundHttpException('Demo: deliberate 404 for testing');
    });
}

final readonly class CreateUserRequest
{
    public function __construct(
        #[Validate(['required', 'string', 'min:2', 'max:50'])]
        public ?string $name = null,
        #[Validate(['required', 'email'])]
        public ?string $email = null,
        #[Validate(['required', 'integer', new MinRule(min: 0), new MaxRule(max: 150)])]
        public ?int $age = null,
    ) {
    }
}

$router->group('/api/v1', static function (Router $r): void {
    $r->get('/users', static fn(): Response => Response::json([
        'users' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']],
    ]));
    $r->get('/users/{id}', static fn(Request $req, array $p): Response => Response::json([
        'id' => (int) $p['id'],
        'name' => 'User ' . $p['id'],
    ]));
    $r->post('/echo', static function (Request $req): Response {
        return Response::json([
            'received' => $req->json(),
            'method' => $req->method,
        ]);
    });
    $r->post('/form', static function (Request $req): Response {
        return Response::json([
            'received' => $req->form() ?? [],
            'method' => $req->method,
        ]);
    });
    $r->post('/upload', static function (Request $req): Response {
        $files = $req->files() ?? [];
        $fields = array_keys($files);
        $sizes = [];
        foreach ($files as $field => $fileOrList) {
            $entries = is_array($fileOrList) ? $fileOrList : [$fileOrList];
            foreach ($entries as $file) {
                $sizes[] = ['field' => $field, 'name' => $file->name, 'size' => $file->size, 'type' => $file->type];
            }
        }
        return Response::json([
            'fields' => $fields,
            'file_count' => count($sizes),
            'files' => $sizes,
        ]);
    });
    $r->get('/large', static fn(): Response => Response::json([
        'data' => array_fill(0, 100, [
            'id' => 1,
            'name' => 'Test entry for compression verification',
            'description' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 5),
        ]),
    ]));
    $r->post('/users', static function (Request $req): Response {
        /** @var CreateUserRequest $user */
        $user = $req->bind(CreateUserRequest::class);
        return Response::json([
            'id' => 1,
            'name' => $user->name,
            'email' => $user->email,
            'age' => $user->age,
        ], 201);
    });
});

$pipeline = new Pipeline($container);
$pipeline->pipe(CompressionMiddleware::class);
if ($appEnv === 'prod') {
    $pipeline->pipe(HttpsRedirectMiddleware::class);
}
$pipeline->pipe(CorsMiddleware::class);
$pipeline->pipe(SecurityHeadersMiddleware::class);
$pipeline->pipe(JsonBodyParser::class);
$pipeline->pipe(FormBodyParser::class);
$pipeline->pipe(MultipartBodyParser::class);
$pipeline->pipe(CsrfMiddleware::class);

$kernel = new HttpKernel($router, $pipeline, $container, $logger, $debug);

$request = Request::fromGlobals()->withValidator($container->get(Validator::class));
$response = $kernel->handle($request);

$logger->info('request', [
    'request_id' => $request->id,
    'method' => $request->method,
    'path' => $request->path,
    'status' => $response->status,
    'content_type' => $response->headers['Content-Type'] ?? null,
]);

$response->send();
