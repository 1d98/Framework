<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Response;

use Framework\Http\Cookie\Cookie;
use Framework\Http\Response\Response;
use Framework\Http\Response\ResponseInterface;
use Framework\Http\Response\StreamedResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Reflection-based contract test: both {@see Response} and
 * {@see StreamedResponse} MUST satisfy {@see ResponseInterface} — same
 * method/property surface, same return types, same parameter counts.
 *
 * This test guards against accidental divergence between the two
 * implementations (e.g. one gaining a builder method that the other
 * forgets, or one falling behind on the interface contract).
 */
#[CoversClass(ResponseInterface::class)]
#[CoversClass(Response::class)]
#[CoversClass(StreamedResponse::class)]
final class ResponseInterfaceContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<ResponseInterface>}>
     */
    public static function responseImplementations(): iterable
    {
        yield 'Response' => [Response::class];
        yield 'StreamedResponse' => [StreamedResponse::class];
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testClassImplementsResponseInterface(string $class): void
    {
        $interfaces = class_implements($class);
        self::assertIsArray($interfaces);
        self::assertContains(ResponseInterface::class, $interfaces, "{$class} must implement ResponseInterface");
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testClassIsReadonly(string $class): void
    {
        // The interface contract requires `readonly` so mutator builders
        // (withHeader, withStatus, etc.) return a new instance instead
        // of mutating in place. Verified via the PHP runtime modifier.
        $reflection = new ReflectionClass($class);
        self::assertTrue($reflection->isReadOnly(), "{$class} must be declared `readonly`");
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testStatusPropertyExistsWithCorrectType(string $class): void
    {
        $prop = new ReflectionProperty($class, 'status');
        self::assertTrue($prop->hasType());
        $type = $prop->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('int', $type->getName());
        self::assertSame('public', $prop->isPublic() ? 'public' : ($prop->isProtected() ? 'protected' : 'private'));
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testHeadersPropertyExistsWithCorrectType(string $class): void
    {
        $prop = new ReflectionProperty($class, 'headers');
        self::assertTrue($prop->hasType());
        $type = $prop->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('array', $type->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testCookiesPropertyExistsWithCorrectType(string $class): void
    {
        $prop = new ReflectionProperty($class, 'cookies');
        self::assertTrue($prop->hasType());
        $type = $prop->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('array', $type->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testReasonPhrasePropertyExistsAndIsNullable(string $class): void
    {
        $prop = new ReflectionProperty($class, 'reasonPhrase');
        self::assertTrue($prop->hasType());
        $type = $prop->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertTrue($type->allowsNull(), 'reasonPhrase must be ?string');
        self::assertSame('string', $type->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testWithHeaderMethodSignature(string $class): void
    {
        $method = new ReflectionMethod($class, 'withHeader');
        self::assertTrue($method->hasReturnType());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        // PHP resolves `: self` to the FQCN when the class lives in a
        // namespace. We compare against the declaring FQCN to assert that
        // the builder returns an instance of the same class (NOT `static`,
        // which would be reported as the literal string "static").
        self::assertNotSame(
            'static',
            $returnType->getName(),
            "{$class}::withHeader() must NOT declare `: static` return type",
        );
        self::assertSame(
            $class,
            $returnType->getName(),
            "{$class}::withHeader() must declare `: self` return type",
        );

        $params = $method->getParameters();
        self::assertCount(2, $params);
        self::assertSame('name', $params[0]->getName());
        self::assertSame('value', $params[1]->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testWithHeadersMethodSignature(string $class): void
    {
        $method = new ReflectionMethod($class, 'withHeaders');
        self::assertSame(1, $method->getNumberOfParameters());
        $params = $method->getParameters();
        self::assertSame('headers', $params[0]->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame($class, $returnType->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testWithStatusMethodSignature(string $class): void
    {
        $method = new ReflectionMethod($class, 'withStatus');
        self::assertSame(2, $method->getNumberOfParameters());
        $params = $method->getParameters();
        self::assertSame('status', $params[0]->getName());
        self::assertSame('reason', $params[1]->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame($class, $returnType->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testWithCookieMethodAcceptsCookieVo(string $class): void
    {
        $method = new ReflectionMethod($class, 'withCookie');
        $params = $method->getParameters();
        self::assertCount(1, $params);

        $cookieParam = $params[0];
        $paramType = $cookieParam->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $paramType);
        self::assertSame(Cookie::class, $paramType->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testWithRequestIdMethodSignature(string $class): void
    {
        $method = new ReflectionMethod($class, 'withRequestId');
        self::assertSame(1, $method->getNumberOfParameters());
        self::assertSame('id', $method->getParameters()[0]->getName());

        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame($class, $returnType->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testSendMethodExistsAndReturnsVoid(string $class): void
    {
        self::assertTrue(method_exists($class, 'send'), "{$class} must implement send()");

        $method = new ReflectionMethod($class, 'send');
        self::assertTrue($method->hasReturnType());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testBuilderMethodsReturnSelfNotStatic(string $class): void
    {
        // `: self` (not `: static`) so the return type is the declared
        // ResponseInterface; `: static` would force callers to handle the
        // concrete subclass and break the polymorphic contract.
        // Reflection resolves `: self` to the FQCN for namespaced classes,
        // and to the literal string 'static' for `: static`.
        foreach (['withHeader', 'withHeaders', 'withStatus', 'withCookie', 'withRequestId'] as $name) {
            $method = new ReflectionMethod($class, $name);
            $returnType = $method->getReturnType();
            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
            self::assertNotSame(
                'static',
                $returnType->getName(),
                "{$class}::{$name}() must NOT declare `: static` return type",
            );
            self::assertSame(
                $class,
                $returnType->getName(),
                "{$class}::{$name}() must declare `: self` return type",
            );
        }
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testInterfaceDeclaresAllBuilderMethods(string $class): void
    {
        $interface = new ReflectionClass(ResponseInterface::class);
        $interfaceMethods = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            $interface->getMethods(),
        );

        foreach (['withHeader', 'withHeaders', 'withStatus', 'withCookie', 'withRequestId', 'send'] as $method) {
            self::assertContains($method, $interfaceMethods, "ResponseInterface must declare {$method}()");
        }
    }

    /**
     * @param class-string<ResponseInterface> $class
     */
    #[DataProvider('responseImplementations')]
    public function testInterfaceDeclaresAllPropertyGetters(string $class): void
    {
        $interface = new ReflectionClass(ResponseInterface::class);
        $interfaceProperties = array_map(
            static fn(ReflectionProperty $p): string => $p->getName(),
            $interface->getProperties(),
        );

        // PHP 8.5 property hooks (`{ get; }`) compile down to actual
        // properties on the interface, not methods. Both implementations
        // MUST declare these properties so the interface contract is
        // fully satisfied.
        foreach (['status', 'headers', 'cookies', 'reasonPhrase'] as $property) {
            self::assertContains($property, $interfaceProperties, "ResponseInterface must declare property {$property}");
        }
    }

    public function testInterfaceExposesBodyViaResponseOnlyNotStreamed(): void
    {
        // The `$body` property exists only on Response, NOT on the
        // interface (and NOT on StreamedResponse). Streamed responses
        // produce their body at send() time and have no materialised
        // body field.
        $interface = new ReflectionClass(ResponseInterface::class);
        self::assertFalse($interface->hasProperty('body'));

        $response = new ReflectionClass(Response::class);
        self::assertTrue($response->hasProperty('body'));

        $streamed = new ReflectionClass(StreamedResponse::class);
        self::assertFalse($streamed->hasProperty('body'));
    }
}