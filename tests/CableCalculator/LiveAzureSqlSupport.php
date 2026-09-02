<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PDO;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;

/**
 * Opens the real Azure SQL connection the application uses, for tests that must run their
 * SQL against the live schema rather than against a double. Returns null instead of
 * throwing so the caller can skip: locally the connection depends on the VPN being up and
 * on the serverless database not being paused, neither of which is a test failure.
 */
final class LiveAzureSqlSupport
{
    public static function connect(): ?PDO
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            return null;
        }

        $envDir = dirname(__DIR__, 2);
        if (is_readable($envDir . '/.env')) {
            \Dotenv\Dotenv::createImmutable($envDir)->safeLoad();
        }

        /** @var array<string, string|null> $env */
        $env = $_ENV;

        try {
            $settings = Settings::fromEnvironment(array_merge($env, [
                'USER_REPOSITORY' => Settings::USER_REPOSITORY_AZURE_SQL,
                'GUEST_JWT_SECRET' => $env['GUEST_JWT_SECRET'] ?? str_repeat('t', 64),
            ]));

            $pdo = ContainerFactory::create($settings)->get(PDO::class);

            return $pdo instanceof PDO ? $pdo : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
