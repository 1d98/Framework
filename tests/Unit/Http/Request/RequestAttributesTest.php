<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Request\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestAttributesTest extends TestCase
{
    public function testAttributesDefaultToEmptyArray(): void
    {
        $request = new Request('GET', '/');

        self::assertSame([], $request->attributes());
        self::assertFalse($request->hasAttribute('csp_nonce'));
        self::assertNull($request->getAttribute('csp_nonce'));
    }

    public function testWithAttributeAddsEntry(): void
    {
        $request = new Request('GET', '/');
        $next = $request->withAttribute('csp_nonce', 'abc123');

        self::assertNotSame($request, $next);
        self::assertSame([], $request->attributes());
        self::assertSame(['csp_nonce' => 'abc123'], $next->attributes());
        self::assertTrue($next->hasAttribute('csp_nonce'));
        self::assertSame('abc123', $next->getAttribute('csp_nonce'));
    }

    public function testWithAttributeOverwritesExisting(): void
    {
        $request = (new Request('GET', '/'))->withAttribute('k', 'v1')->withAttribute('k', 'v2');

        self::assertSame('v2', $request->getAttribute('k'));
        self::assertCount(1, $request->attributes());
    }

    public function testGetAttributeReturnsDefaultWhenMissing(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('fallback', $request->getAttribute('missing', 'fallback'));
        self::assertNull($request->getAttribute('missing'));
    }
}
