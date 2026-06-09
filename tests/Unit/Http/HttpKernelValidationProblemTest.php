<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\HttpKernel;
use Framework\Http\Request\Request;
use Framework\Http\Response\Response;
use Framework\Http\Router\Router;
use Framework\Validation\Attribute\Validate;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpKernel::class)]
final class HttpKernelValidationProblemTest extends TestCase
{
    public function testNestedValidationRendersRfc7807WithPointers(): void
    {
        $router = new Router();
        $router->post('/api/v1/orders', static function (Request $req): Response {
            $dto = $req->bind(CreateOrderHttpDto::class);
            return Response::json(['ok' => true], 201);
        });

        $validator = new Validator(new RuleRegistry());
        $request = (new Request('POST', '/api/v1/orders'))->withValidator($validator)
            ->withJson([
                'email' => 'ok@example.com',
                'address' => ['email' => 'not-an-email'],
            ]);

        $kernel = new HttpKernel($router);
        $response = $kernel->handle($request);

        self::assertSame(422, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertSame(422, $body['status']);
        self::assertSame('Unprocessable Entity', $body['title']);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertCount(1, $body['errors']);
        $err0 = $body['errors'][0];
        self::assertIsArray($err0);
        self::assertSame('email', $err0['property']);
        self::assertSame('email', $err0['rule']);
        self::assertSame('/address/email', $err0['pointer']);
        self::assertSame(['address'], $err0['path']);
    }

    public function testTopLevelValidationRendersRfc7807WithPointer(): void
    {
        $router = new Router();
        $router->post('/api/v1/users', static function (Request $req): Response {
            $dto = $req->bind(SimpleHttpDto::class);
            return Response::json(['ok' => true], 201);
        });

        $validator = new Validator(new RuleRegistry());
        $request = (new Request('POST', '/api/v1/users'))->withValidator($validator)
            ->withJson(['email' => 'not-an-email']);

        $kernel = new HttpKernel($router);
        $response = $kernel->handle($request);

        self::assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        $err0 = $body['errors'][0];
        self::assertIsArray($err0);
        self::assertSame('/email', $err0['pointer']);
        self::assertSame([], $err0['path']);
    }

    public function testArrayShapeValidationRendersIndexedPointer(): void
    {
        $router = new Router();
        $router->post('/api/v1/orders', static function (Request $req): Response {
            $dto = $req->bind(OrderWithItemsHttpDto::class);
            return Response::json(['ok' => true], 201);
        });

        $validator = new Validator(new RuleRegistry());
        $request = (new Request('POST', '/api/v1/orders'))->withValidator($validator)
            ->withJson([
                'sku' => 'PARENT',
                'items' => [
                    ['sku' => 'A'],
                    ['sku' => ''],
                ],
            ]);

        $kernel = new HttpKernel($router);
        $response = $kernel->handle($request);

        self::assertSame(422, $response->status);
        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertIsArray($body['errors']);
        $err0 = $body['errors'][0];
        self::assertIsArray($err0);
        self::assertSame('/items/1/sku', $err0['pointer']);
        self::assertSame(['items', '1'], $err0['path']);
    }
}

final class SimpleHttpDto
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final class HttpAddress
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
    ) {
    }
}

final class CreateOrderHttpDto
{
    public function __construct(
        #[Validate('required|email')]
        public ?string $email = null,
        #[Validate(HttpAddress::class)]
        public ?HttpAddress $address = null,
    ) {
    }
}

final class HttpOrderItem
{
    public function __construct(
        #[Validate('required|string|min:1')]
        public ?string $sku = null,
    ) {
    }
}

final class OrderWithItemsHttpDto
{
    /**
     * @param list<HttpOrderItem>|null $items
     */
    public function __construct(
        #[Validate('required|string')]
        public ?string $sku = null,
        #[Validate(items: HttpOrderItem::class)]
        public ?array $items = null,
    ) {
    }
}
