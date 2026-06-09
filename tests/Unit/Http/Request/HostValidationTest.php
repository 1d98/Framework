<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http\Request;

use Framework\Http\Exception\BadRequestHttpException;
use Framework\Http\Request\Request;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class HostValidationTest extends TestCase
{
    public function testHostWithNullReturnsRawHeaderBackwardCompat(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com:8080']);

        self::assertSame('example.com:8080', $request->host(null));
    }

    public function testHostWithNullAndNoTrustedHostsArgDefaultsToLegacyBehavior(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'evil.com']);

        self::assertSame('evil.com', $request->host());
    }

    public function testHostReturnsExactMatch(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com']);

        self::assertSame('example.com', $request->host(['example.com']));
    }

    public function testHostFallsBackToFirstTrustedPatternWhenUntrusted(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'evil.com']);

        self::assertSame('example.com', $request->host(['example.com']));
    }

    public function testHostFallsBackToFirstTrustedWhenMultiplePatterns(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'evil.com']);

        self::assertSame('primary.example.com', $request->host(['primary.example.com', 'secondary.example.com']));
    }

    public function testHostWildcardMatchesSubdomain(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'api.example.com']);

        self::assertSame('api.example.com', $request->host(['*.example.com']));
    }

    public function testHostWildcardMatchesApex(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com']);

        self::assertSame('example.com', $request->host(['*.example.com']));
    }

    public function testHostWildcardDoesNotMatchUnrelatedDomain(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'evil.com']);

        self::assertSame('example.com', $request->host(['*.example.com']));
    }

    public function testHostWildcardDoesNotMatchSuffixOfUnrelatedDomain(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'notexample.com']);

        self::assertSame('example.com', $request->host(['*.example.com']));
    }

    public function testHostStripsPortBeforeMatching(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com:8080']);

        self::assertSame('example.com', $request->host(['example.com']));
    }

    public function testHostStripsPortForWildcardMatch(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'api.example.com:443']);

        self::assertSame('api.example.com', $request->host(['*.example.com']));
    }

    public function testHostIsCaseInsensitive(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'EXAMPLE.com']);

        self::assertSame('example.com', $request->host(['example.com']));
    }

    public function testHostIsCaseInsensitiveForWildcard(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'API.EXAMPLE.COM']);

        self::assertSame('api.example.com', $request->host(['*.example.com']));
    }

    public function testHostThrowsOnCrlfInjection(): void
    {
        $request = new Request('GET', '/', '', ['host' => "example.com\r\nX-Evil: 1"]);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid Host header');

        $request->host(['example.com']);
    }

    public function testHostThrowsOnLoneLfInjection(): void
    {
        $request = new Request('GET', '/', '', ['host' => "example.com\nX-Evil: 1"]);

        $this->expectException(BadRequestHttpException::class);

        $request->host(['example.com']);
    }

    public function testHostThrowsOnNulByteInjection(): void
    {
        $request = new Request('GET', '/', '', ['host' => "example.com\0.evil.com"]);

        $this->expectException(BadRequestHttpException::class);

        $request->host(['example.com']);
    }

    public function testHostThrowsOnEmptyTrustedHostsList(): void
    {
        $request = new Request('GET', '/', '', ['host' => 'example.com']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-empty trusted hosts list');

        /** @var list<string> $empty */
        $empty = [];
        $request->host($empty);
    }

    public function testHostFallsBackToLocalhostWhenHeaderMissingEvenWithTrustedList(): void
    {
        $request = new Request('GET', '/');

        self::assertSame('localhost', $request->host(['example.com']));
    }

    public function testTrustedHostsDefaultContainsLocalhostAndLoopback(): void
    {
        self::assertSame(['localhost', '127.0.0.1', '*.localhost'], Request::TRUSTED_HOSTS_DEFAULT);
    }
}
