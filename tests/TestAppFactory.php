<?php

declare(strict_types=1);

namespace TriCab\Api\Tests;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TriCab\Api\Bootstrap\AppBuilder;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;

final class TestAppFactory
{
    /**
     * @param array<string, string|null> $env
     * @return App<ContainerInterface>
     */
    public static function create(array $env = []): App
    {
        $defaults = [
            'APP_ENV' => 'development',
            'APP_NAME' => 'tricab-api',
            'APP_DEBUG' => 'true',
            'DEV_AUTH_ENABLED' => 'true',
            'DEV_AUTH_DEFAULT_TENANT' => 'development-tenant',
            'DEV_AUTH_DEFAULT_SUBJECT' => 'development-thilan',
            'DEV_AUTH_DEFAULT_EMAIL' => 'thilanh@tricab.com',
            'USER_REPOSITORY' => 'memory',
            'DB_AUTH_MODE' => 'sql-login',
            'GUEST_JWT_ISSUER' => 'https://api.tricab.local',
            'GUEST_JWT_AUDIENCE' => 'tricab-api',
            'GUEST_JWT_SECRET' => 'local-development-guest-jwt-secret-32b',
            'GUEST_JWT_TTL_SECONDS' => '86400',
            'APP_PERFORMANCE_LOGGING' => 'false',
            'APP_CACHE_DRIVER' => 'array',
        ];

        $merged = array_merge($defaults, $env);
        $appEnv = $merged['APP_ENV'] ?? 'development';
        if (($merged['GUEST_EMAIL_PROVIDER'] ?? null) === null && $appEnv !== 'production') {
            $merged['GUEST_EMAIL_PROVIDER'] = 'development';
        }

        $settings = Settings::fromEnvironment($merged);
        $container = ContainerFactory::create($settings);

        return AppBuilder::build($container);
    }

    public static function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $raw = (string) $response->getBody();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
