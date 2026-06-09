<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Http;

use Framework\Http\CidrMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CidrMatcher::class)]
final class CidrMatcherTest extends TestCase
{
    public function testExactIpv4Match(): void
    {
        self::assertTrue(CidrMatcher::matches('192.0.2.1', '192.0.2.1'));
    }

    public function testExactIpv4Miss(): void
    {
        self::assertFalse(CidrMatcher::matches('192.0.2.1', '192.0.2.2'));
    }

    public function testIpv4CidrSlash8(): void
    {
        self::assertTrue(CidrMatcher::matches('10.0.0.0/8', '10.0.0.1'));
        self::assertTrue(CidrMatcher::matches('10.0.0.0/8', '10.255.255.255'));
        self::assertTrue(CidrMatcher::matches('127.0.0.0/8', '127.0.0.1'));
    }

    public function testIpv4CidrSlash16(): void
    {
        self::assertTrue(CidrMatcher::matches('192.168.0.0/16', '192.168.1.1'));
        self::assertTrue(CidrMatcher::matches('192.168.0.0/16', '192.168.255.255'));
        self::assertFalse(CidrMatcher::matches('192.168.0.0/16', '192.169.0.0'));
    }

    public function testIpv4CidrSlash24(): void
    {
        self::assertTrue(CidrMatcher::matches('192.168.1.0/24', '192.168.1.42'));
        self::assertFalse(CidrMatcher::matches('192.168.1.0/24', '192.168.2.42'));
    }

    public function testIpv4CidrBoundary(): void
    {
        self::assertTrue(CidrMatcher::matches('10.0.0.0/8', '10.0.0.0'));
        self::assertTrue(CidrMatcher::matches('10.0.0.0/8', '10.255.255.255'));
    }

    public function testIpv4CidrSlash30(): void
    {
        self::assertTrue(CidrMatcher::matches('192.0.2.0/30', '192.0.2.0'));
        self::assertTrue(CidrMatcher::matches('192.0.2.0/30', '192.0.2.3'));
        self::assertFalse(CidrMatcher::matches('192.0.2.0/30', '192.0.2.4'));
    }

    public function testIpv4CidrSlash32EqualsExact(): void
    {
        self::assertTrue(CidrMatcher::matches('192.0.2.1/32', '192.0.2.1'));
        self::assertFalse(CidrMatcher::matches('192.0.2.1/32', '192.0.2.2'));
    }

    public function testIpv6ExactMatch(): void
    {
        self::assertTrue(CidrMatcher::matches('::1', '::1'));
        self::assertTrue(CidrMatcher::matches('2001:db8::1', '2001:db8::1'));
        self::assertFalse(CidrMatcher::matches('::1', '::2'));
    }

    public function testIpv6CidrSlash128EqualsExact(): void
    {
        self::assertTrue(CidrMatcher::matches('::1/128', '::1'));
        self::assertFalse(CidrMatcher::matches('::1/128', '::2'));
    }

    public function testIpv6CidrSlash127(): void
    {
        self::assertTrue(CidrMatcher::matches('2001:db8::/127', '2001:db8::'));
        self::assertTrue(CidrMatcher::matches('2001:db8::/127', '2001:db8::1'));
        self::assertFalse(CidrMatcher::matches('2001:db8::/127', '2001:db8::2'));
    }

    public function testIpv6CidrSlash64(): void
    {
        self::assertTrue(CidrMatcher::matches('2001:db8:1::/64', '2001:db8:1::1'));
        self::assertTrue(CidrMatcher::matches('2001:db8:1::/64', '2001:db8:1::ffff:ffff:ffff:ffff'));
        self::assertFalse(CidrMatcher::matches('2001:db8:1::/64', '2001:db8:2::1'));
    }

    public function testIpv4NetworkDoesNotMatchIpv6Candidate(): void
    {
        self::assertFalse(CidrMatcher::matches('10.0.0.0/8', '::1'));
        self::assertFalse(CidrMatcher::matches('192.0.2.1', '2001:db8::1'));
    }

    public function testIpv6NetworkDoesNotMatchIpv4Candidate(): void
    {
        self::assertFalse(CidrMatcher::matches('::1', '127.0.0.1'));
        self::assertFalse(CidrMatcher::matches('2001:db8::/32', '192.168.1.1'));
    }

    public function testInvalidCandidateReturnsFalse(): void
    {
        self::assertFalse(CidrMatcher::matches('10.0.0.0/8', 'not-an-ip'));
        self::assertFalse(CidrMatcher::matches('10.0.0.0/8', '999.999.999.999'));
        self::assertFalse(CidrMatcher::matches('::1', '999.999.999.999'));
    }

    public function testInvalidNetworkFallsBackToExactMatch(): void
    {
        self::assertFalse(CidrMatcher::matches('not-a-cidr/abc', '10.0.0.1'));
        self::assertFalse(CidrMatcher::matches('not-a-cidr', '10.0.0.1'));
    }

    public function testMatchesAnyWithEmptyList(): void
    {
        self::assertFalse(CidrMatcher::matchesAny([], '10.0.0.1'));
    }

    public function testMatchesAnyIgnoresBlankEntries(): void
    {
        self::assertTrue(CidrMatcher::matchesAny(['', '  ', '10.0.0.0/8', ''], '10.1.2.3'));
        self::assertFalse(CidrMatcher::matchesAny(['', '  '], '10.1.2.3'));
    }

    public function testMatchesAnyPicksFirstHit(): void
    {
        self::assertTrue(CidrMatcher::matchesAny(['10.0.0.0/8', '192.168.0.0/16'], '192.168.5.5'));
        self::assertTrue(CidrMatcher::matchesAny(['10.0.0.0/8', '192.168.0.0/16'], '10.5.5.5'));
        self::assertFalse(CidrMatcher::matchesAny(['10.0.0.0/8', '192.168.0.0/16'], '172.16.0.1'));
    }

    public function testIpv4MappedCandidateMatchesIpv4Cidr(): void
    {
        self::assertTrue(CidrMatcher::matches('192.168.0.0/16', '::ffff:192.168.5.5'));
    }

    public function testIpv4MappedCandidateMissesIpv4Cidr(): void
    {
        self::assertFalse(CidrMatcher::matches('192.168.0.0/16', '::ffff:10.5.5.5'));
    }

    public function testPureIpv6StillMatchesPureIpv6Cidr(): void
    {
        self::assertTrue(CidrMatcher::matches('::1/128', '::1'));
    }

    public function testIpv4MappedCandidateMatchesIpv4MappedIpv6Cidr(): void
    {
        self::assertTrue(CidrMatcher::matches('::ffff:0:0/96', '::ffff:192.0.2.1'));
    }

    public function testInvalidEmbeddedIpv4FallsBackToV6(): void
    {
        self::assertFalse(CidrMatcher::matches('10.0.0.0/8', '::ffff:0.0.0.0'));
    }
}
