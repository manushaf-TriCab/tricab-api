<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use Dotenv\Dotenv;
use TriCab\Api\Config\Settings;

final class Environment
{
    /**
     * @return array<string, string|null>
     */
    public static function load(string $basePath): array
    {
        if (is_readable($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }

        /** @var array<string, string|null> $env */
        $env = $_ENV;

        return $env;
    }

    public static function settings(string $basePath): Settings
    {
        return Settings::fromEnvironment(self::load($basePath));
    }
}
