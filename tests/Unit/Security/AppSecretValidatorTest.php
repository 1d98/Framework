<?php

declare(strict_types=1);

namespace Framework\Tests\Unit\Security;

use Framework\Exception\ConfigException;
use Framework\Security\AppSecretValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppSecretValidator::class)]
final class AppSecretValidatorTest extends TestCase
{
    public function testRejectsKnownDevDefaultInProduction(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('well-known development default');

        AppSecretValidator::assertProductionSafe('dev-only-secret-change-in-prod', 'prod');
    }

    public function testRejectsEmptySecretInProduction(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('well-known development default');

        AppSecretValidator::assertProductionSafe('', 'prod');
    }

    public function testRejectsShortSecretInProduction(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('too short');

        AppSecretValidator::assertProductionSafe('short', 'prod');
    }

    public function testRejectsSecretJustBelowMinimumLengthInProduction(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('too short');

        $secret = str_repeat('a', AppSecretValidator::MIN_PROD_LENGTH - 1);
        AppSecretValidator::assertProductionSafe($secret, 'prod');
    }

    public function testAcceptsSecretAtMinimumLengthInProduction(): void
    {
        $secret = str_repeat('a', AppSecretValidator::MIN_PROD_LENGTH);

        AppSecretValidator::assertProductionSafe($secret, 'prod');

        $this->expectNotToPerformAssertions();
    }

    public function testAcceptsRandomSecretInProduction(): void
    {
        $secret = bin2hex(random_bytes(32));

        AppSecretValidator::assertProductionSafe($secret, 'prod');

        $this->expectNotToPerformAssertions();
    }

    public function testDevEnvAllowsKnownDevDefault(): void
    {
        AppSecretValidator::assertProductionSafe('dev-only-secret-change-in-prod', 'dev');

        $this->expectNotToPerformAssertions();
    }

    public function testDevEnvAllowsEmptySecret(): void
    {
        AppSecretValidator::assertProductionSafe('', 'dev');

        $this->expectNotToPerformAssertions();
    }

    public function testDevEnvAllowsShortSecret(): void
    {
        AppSecretValidator::assertProductionSafe('x', 'dev');

        $this->expectNotToPerformAssertions();
    }

    public function testStagingEnvIsNotSubjectToProductionCheck(): void
    {
        AppSecretValidator::assertProductionSafe('dev-only-secret-change-in-prod', 'staging');

        $this->expectNotToPerformAssertions();
    }

    public function testIsKnownDevDefaultReturnsTrueForKnownDefaults(): void
    {
        self::assertTrue(AppSecretValidator::isKnownDevDefault('dev-only-secret-change-in-prod'));
        self::assertTrue(AppSecretValidator::isKnownDevDefault(''));
    }

    public function testIsKnownDevDefaultReturnsFalseForRandomSecret(): void
    {
        self::assertFalse(AppSecretValidator::isKnownDevDefault('a-very-real-and-long-secret-1234567890'));
    }
}
