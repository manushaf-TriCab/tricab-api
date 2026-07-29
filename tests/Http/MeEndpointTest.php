<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Shared\Package;

final class MeEndpointTest extends TestCase
{
    public function test_me_with_valid_development_identity(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
        ]);

        $request = TestAppFactory::request('GET', '/api/v1/me')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'development-thilan')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, 'thilanh@tricab.com');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['data']);
        /** @var array<string, mixed> $data */
        $data = $payload['data'];
        self::assertSame(1, $data['userId']);
        self::assertSame('thilanh@tricab.com', $data['emailAddress']);
        self::assertSame('Thilan Lakshitha', $data['displayName']);
        self::assertSame('active', $data['status']);
        self::assertSame(['EMPLOYEE'], $data['roles']);
        self::assertSame(['ACCOUNT.LOGIN'], $data['permissions']);
    }

    public function test_me_without_identity_when_dev_auth_disabled(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'false',
        ]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/me'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_me_with_unknown_identity(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
        ]);

        $request = TestAppFactory::request('GET', '/api/v1/me')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'unknown-subject');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('IDENTITY_NOT_LINKED', $error['code']);
    }

    public function test_development_auth_cannot_run_in_production_mode(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'production',
            'DEV_AUTH_ENABLED' => 'true',
            'APP_DEBUG' => 'false',
        ]);

        $request = TestAppFactory::request('GET', '/api/v1/me')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, 'development-tenant')
            ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, 'development-thilan');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('UNAUTHENTICATED', $error['code']);
    }

    public function test_production_validator_not_configured_for_bearer_token(): void
    {
        $app = TestAppFactory::create([
            'APP_ENV' => 'production',
            'DEV_AUTH_ENABLED' => 'false',
            'APP_DEBUG' => 'false',
        ]);

        $request = TestAppFactory::request('GET', '/api/v1/me')
            ->withHeader('Authorization', 'Bearer not-a-real-token');

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(501, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertIsArray($payload['error']);
        /** @var array<string, mixed> $error */
        $error = $payload['error'];
        self::assertSame('ENTRA_NOT_CONFIGURED', $error['code']);
        self::assertSame('Microsoft Entra JWT validation is not configured.', $error['message']);
    }

    public function test_api_depends_on_core_through_composer(): void
    {
        self::assertTrue(class_exists(ResolveAuthenticatedUserHandler::class));
        self::assertTrue(interface_exists(UserRepository::class));
        self::assertSame('tricab/tricab-core', Package::NAME);
        self::assertFalse(is_dir(dirname(__DIR__, 2) . '/../tricab-core/src/Http'));
        self::assertFalse(class_exists('TriCab\\Core\\Identity\\Persistence\\InMemoryApiUserRepository'));
    }
}
