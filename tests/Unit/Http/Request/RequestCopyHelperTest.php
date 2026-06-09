<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use Framework\Http\Request\RequestBinder;
use Framework\Http\Request\RequestHost;
use Framework\Http\Request\RequestMemo;
use Framework\Http\UploadedFile;
use Framework\Validation\Rule\RuleRegistry;
use Framework\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(Request::class)]
final class RequestCopyHelperTest extends TestCase
{
    public function testCopyMethodIsPrivate(): void
    {
        $reflection = new ReflectionClass(Request::class);
        $method = $reflection->getMethod('copy');

        self::assertTrue(
            $method->isPrivate(),
            'Request::copy() must stay private — it is an internal deduplication helper, not part of the public API',
        );
    }

    public function testCopyDoesNotAllocateNewRequestBinder(): void
    {
        $validator = new Validator(new RuleRegistry());
        $request = new Request('POST', '/x', validator: $validator);

        $child = $this->invokeCopy($request, ['json' => ['k' => 'v']]);

        $originalMemo = $this->readMemo($request);
        $childMemo = $this->readMemo($child);

        self::assertSame(
            $originalMemo,
            $childMemo,
            'copy() must propagate the same RequestMemo instance by reference (R9 contract)',
        );
        self::assertNull(
            $this->readBinder($originalMemo),
            'copy() must not allocate a fresh RequestBinder on the shared memo',
        );
    }

    public function testCopySeesAlreadyPopulatedBinderFromParent(): void
    {
        $validator = new Validator(new RuleRegistry());
        $request = new Request('POST', '/x', validator: $validator)->withJson(['k' => 'v']);
        $request->bind(CopyTestDto::class);
        $populated = $this->readBinder($this->readMemo($request));
        self::assertInstanceOf(RequestBinder::class, $populated);

        $child = $this->invokeCopy($request, ['json' => ['k' => 'v2']]);

        self::assertSame(
            $populated,
            $this->readBinder($this->readMemo($child)),
            'copy() child must observe the binder the parent already populated — no re-allocation',
        );
    }

    public function testWithJsonAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withJson(['fresh' => true]);

        $this->assertAllBut($original, $next, 'json');
        self::assertSame(['fresh' => true], $next->json());
    }

    public function testWithFormAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withForm(['email' => 'a@b.c']);

        $this->assertAllBut($original, $next, 'form');
        self::assertSame(['email' => 'a@b.c'], $next->form());
    }

    public function testWithFilesAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $upload = new UploadedFile('/tmp/x', 'x.txt', 'text/plain', 1, 0);
        $next = $original->withFiles(['avatar' => $upload]);

        $this->assertAllBut($original, $next, 'files');
        self::assertSame(['avatar' => $upload], $next->files());
    }

    public function testWithCsrfTokenAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withCsrfToken('tok-2');

        $this->assertAllBut($original, $next, 'csrfToken');
        self::assertSame('tok-2', $next->csrfToken());
    }

    public function testWithValidatorAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $newValidator = new Validator(new RuleRegistry());
        $next = $original->withValidator($newValidator);

        $this->assertAllBut($original, $next, 'validator');
        self::assertSame($newValidator, $next->validator());
    }

    public function testWithIdAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withId('new-id');

        $this->assertAllBut($original, $next, 'id');
        self::assertSame('new-id', $next->id);
    }

    public function testWithTrustedProxiesAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withTrustedProxies(['10.0.0.0/8']);

        $this->assertAllBut($original, $next, 'host');
        self::assertSame(['10.0.0.0/8'], $this->readTrustedProxies($next));
    }

    public function testWithHostAppliesNewValueAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $replacement = new RequestHost(
            host: 'replacement.example.com',
            isSecure: true,
            remoteAddr: '10.0.0.7',
            trustedProxies: ['10.0.0.0/8'],
        );
        $next = $original->withHost($replacement);

        $this->assertAllBut($original, $next, 'host');
        self::assertSame($replacement, $this->readHost($next));
    }

    public function testWithAttributeMergesAndPreservesAllOthers(): void
    {
        $original = $this->seeded();
        $next = $original->withAttribute('extra', 'value');

        $this->assertAllBut($original, $next, 'attributes');
        self::assertSame(['k' => 'v', 'extra' => 'value'], $next->attributes());
    }

    public function testWithJsonAllowsNullOverride(): void
    {
        $original = (new Request('POST', '/x'))->withJson(['k' => 'v']);
        $cleared = $original->withJson(null);

        self::assertNull(
            $cleared->json(),
            'withJson(null) must override — the helper must distinguish "key absent" from "key present and null"',
        );
    }

    /**
     * Assert that every property except `$excluded` has the same value on
     * both requests. Each `with*` method must touch exactly one property
     * and leave the other 16 untouched.
     */
    private function assertAllBut(Request $original, Request $next, string $excluded): void
    {
        self::assertNotSame($original, $next);

        $checks = [
            'method' => $original->method === $next->method,
            'path' => $original->path === $next->path,
            'queryString' => $original->queryString === $next->queryString,
            'headers' => $original->headers === $next->headers,
            'body' => $original->body === $next->body,
            'json' => $original->json() === $next->json(),
            'form' => $original->form() === $next->form(),
            'files' => $original->files() === $next->files(),
            'cookies' => $original->cookies() === $next->cookies(),
            'csrfToken' => $original->csrfToken() === $next->csrfToken(),
            'validator' => $original->validator() === $next->validator(),
            'maxBodyBytes' => $original->maxBodyBytes() === $next->maxBodyBytes(),
            'id' => $original->id === $next->id,
            'attributes' => $original->attributes() === $next->attributes(),
            'host' => $this->readHost($original) === $this->readHost($next),
        ];

        foreach ($checks as $name => $same) {
            if ($name === $excluded) {
                continue;
            }
            self::assertTrue(
                $same,
                "Property '{$name}' was not preserved across with*() (changed: '{$excluded}')",
            );
        }
    }

    private function seeded(): Request
    {
        return new Request(
            method: 'POST',
            path: '/api/v1/items',
            queryString: 'a=1&b=2',
            headers: ['x-foo' => 'bar', 'x-trace' => 'abc'],
            body: '{"raw":true}',
            json: ['existing' => 'json'],
            form: ['email' => 'orig@example.com'],
            files: ['avatar' => new UploadedFile('/tmp/a', 'a.png', 'image/png', 10, 0)],
            cookies: ['session' => 's-1'],
            csrfToken: 'orig-csrf',
            validator: new Validator(new RuleRegistry()),
            maxBodyBytes: 4096,
            id: 'orig-id',
            attributes: ['k' => 'v'],
            host: new RequestHost(trustedProxies: ['127.0.0.1']),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function invokeCopy(Request $request, array $overrides): Request
    {
        $method = new ReflectionMethod(Request::class, 'copy');
        /** @var Request $child */
        $child = $method->invoke($request, $overrides);
        return $child;
    }

    private function readMemo(Request $request): RequestMemo
    {
        $prop = new ReflectionProperty(Request::class, 'memo');
        $memo = $prop->getValue($request);
        self::assertInstanceOf(RequestMemo::class, $memo);
        /** @var RequestMemo $memo */
        return $memo;
    }

    private function readBinder(RequestMemo $memo): ?RequestBinder
    {
        $prop = new ReflectionProperty(RequestMemo::class, 'binder');
        $value = $prop->getValue($memo);
        if ($value !== null) {
            self::assertInstanceOf(RequestBinder::class, $value);
        }
        return $value;
    }

    /**
     * @return list<string>
     */
    private function readTrustedProxies(Request $request): array
    {
        $host = $this->readHost($request);
        $value = $host->trustedProxies;
        if ($value === null) {
            return [];
        }
        return $value;
    }

    private function readHost(Request $request): RequestHost
    {
        $prop = new ReflectionProperty(Request::class, 'host');
        $value = $prop->getValue($request);
        self::assertInstanceOf(RequestHost::class, $value);
        return $value;
    }
}

final class CopyTestDto
{
    public function __construct(
        public ?string $k = null,
    ) {
    }
}
