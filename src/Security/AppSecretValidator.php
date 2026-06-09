<?php

declare(strict_types=1);

namespace Framework\Security;

use Framework\Exception\ConfigException;

final class AppSecretValidator
{
    public const MIN_PROD_LENGTH = 32;

    public const KNOWN_DEV_SECRETS = [
        'dev-only-secret-change-in-prod',
        '',
    ];

    public static function assertProductionSafe(string $secret, string $env): void
    {
        if ($env !== 'prod') {
            return;
        }

        if (in_array($secret, self::KNOWN_DEV_SECRETS, true)) {
            throw new ConfigException(
                'APP_SECRET is set to a well-known development default. '
                . 'Using it in production would let anyone forge CSRF tokens and signed cookies. '
                . 'Generate a fresh secret (e.g. `php bin/framework app:secret`) and provide it '
                . 'via the APP_SECRET environment variable before booting in production.',
            );
        }

        if (strlen($secret) < self::MIN_PROD_LENGTH) {
            throw new ConfigException(sprintf(
                'APP_SECRET is too short for production: got %d characters, minimum is %d. '
                . 'A short secret weakens HMAC signatures and is rejected at boot. '
                . 'Generate a fresh secret (e.g. `php bin/framework app:secret`) and provide it '
                . 'via the APP_SECRET environment variable.',
                strlen($secret),
                self::MIN_PROD_LENGTH,
            ));
        }
    }

    public static function isKnownDevDefault(string $secret): bool
    {
        return in_array($secret, self::KNOWN_DEV_SECRETS, true);
    }
}
