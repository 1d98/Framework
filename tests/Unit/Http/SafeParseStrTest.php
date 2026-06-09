<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use Framework\Http\SafeParseStr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SafeParseStr::class)]
final class SafeParseStrTest extends TestCase
{
    public function testParsesSimpleForm(): void
    {
        self::assertSame(
            ['email' => 'foo@bar', 'name' => 'John'],
            SafeParseStr::parse('email=foo@bar&name=John'),
        );
    }

    public function testParsesEmptyString(): void
    {
        self::assertSame([], SafeParseStr::parse(''));
    }

    public function testParsesHundredKeysAtOneLevelDeep(): void
    {
        $pairs = [];
        for ($i = 0; $i < 100; $i++) {
            $pairs[] = 'k' . $i . '=v' . $i;
        }
        $body = implode('&', $pairs);

        $result = SafeParseStr::parse($body);

        self::assertCount(100, $result);
        self::assertSame('v0', $result['k0']);
        self::assertSame('v99', $result['k99']);
    }

    public function testThrowsOnTooManyKeys(): void
    {
        $pairs = [];
        for ($i = 0; $i < 5000; $i++) {
            $pairs[] = 'k' . $i . '=v' . $i;
        }
        $body = implode('&', $pairs);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Too many form keys');

        SafeParseStr::parse($body);
    }

    public function testThrowsOnDeeplyNestedKey(): void
    {
        $key = 'a' . str_repeat('[b]', 50) . '=leaf';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Form key nesting too deep');

        SafeParseStr::parse($key);
    }

    public function testAcceptsKeyAtMaxDepthBoundary(): void
    {
        $key = 'a' . str_repeat('[b]', SafeParseStr::DEFAULT_MAX_DEPTH) . '=leaf';

        $result = SafeParseStr::parse($key);

        self::assertArrayHasKey('a', $result);
    }

    public function testRejectsKeyOneOverMaxDepth(): void
    {
        $key = 'a' . str_repeat('[b]', SafeParseStr::DEFAULT_MAX_DEPTH + 1) . '=leaf';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Form key nesting too deep');

        SafeParseStr::parse($key);
    }

    public function testRequestQueryMirrorsSafeParseBehavior(): void
    {
        $request = new Request('GET', '/search', 'email=foo@bar&name=John');

        self::assertSame(['email' => 'foo@bar', 'name' => 'John'], $request->query());
    }

    public function testRequestQueryThrowsOnTooManyKeys(): void
    {
        $pairs = [];
        for ($i = 0; $i < 5000; $i++) {
            $pairs[] = 'k' . $i . '=v' . $i;
        }
        $request = new Request('GET', '/x', implode('&', $pairs));

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Too many form keys');

        $request->query();
    }

    public function testRequestQueryThrowsOnDeeplyNestedKey(): void
    {
        $request = new Request('GET', '/x', 'a' . str_repeat('[b]', 50) . '=leaf');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Form key nesting too deep');

        $request->query();
    }

    public function testRespectsCustomMaxKeys(): void
    {
        $body = 'a=1&b=2&c=3';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Too many form keys');

        SafeParseStr::parse($body, maxKeys: 2);
    }

    public function testRespectsCustomMaxDepth(): void
    {
        $body = 'a[b][c][d]=x';

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Form key nesting too deep');

        SafeParseStr::parse($body, maxDepth: 2);
    }

    public function testDoesNotCountBracketsInValue(): void
    {
        $result = SafeParseStr::parse('note=hello[world]');

        self::assertSame(['note' => 'hello[world]'], $result);
    }
}
