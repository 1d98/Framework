<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Validation\Rule;

use Framework\Validation\Rule\EmailRule;
use Framework\Validation\Rule\UrlRule;
use Framework\Validation\Rule\UuidRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailRule::class)]
#[CoversClass(UrlRule::class)]
#[CoversClass(UuidRule::class)]
final class FormatRulesTest extends TestCase
{
    public function testEmailAcceptsValidEmail(): void
    {
        $rule = new EmailRule();
        self::assertNull($rule->validate('alice@example.com', []));
        self::assertNull($rule->validate('alice+tag@sub.example.com', []));
    }

    public function testEmailRejectsInvalidEmail(): void
    {
        $rule = new EmailRule();
        self::assertNotNull($rule->validate('not-an-email', []));
        self::assertNotNull($rule->validate('@example.com', []));
        self::assertNotNull($rule->validate('alice@', []));
        self::assertNotNull($rule->validate('', []));
    }

    public function testEmailSkipsNull(): void
    {
        $rule = new EmailRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testUrlAcceptsValidUrl(): void
    {
        $rule = new UrlRule();
        self::assertNull($rule->validate('https://example.com', []));
        self::assertNull($rule->validate('http://localhost:8000/path?q=1', []));
    }

    public function testUrlRejectsInvalidUrl(): void
    {
        $rule = new UrlRule();
        self::assertNotNull($rule->validate('not a url', []));
        self::assertNotNull($rule->validate('example.com', []));
        self::assertNotNull($rule->validate('', []));
    }

    public function testUrlSkipsNull(): void
    {
        $rule = new UrlRule();
        self::assertNull($rule->validate(null, []));
    }

    public function testUuidAcceptsValidUuid(): void
    {
        $rule = new UuidRule();
        self::assertNull($rule->validate('550e8400-e29b-41d4-a716-446655440000', []));
        self::assertNull($rule->validate('550E8400-E29B-41D4-A716-446655440000', []));
    }

    public function testUuidRejectsInvalidUuid(): void
    {
        $rule = new UuidRule();
        self::assertNotNull($rule->validate('not-a-uuid', []));
        self::assertNotNull($rule->validate('550e8400-e29b-41d4-a716', []));
        self::assertNotNull($rule->validate('550e8400e29b41d4a716446655440000', []));
    }

    public function testUuidSkipsNull(): void
    {
        $rule = new UuidRule();
        self::assertNull($rule->validate(null, []));
    }
}
