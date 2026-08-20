<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure\Authentication;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentitySqlAccessTokenException;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentitySqlAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenClient;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ManagedIdentitySqlAccessTokenProviderTest extends TestCase
{
    public function test_requests_azure_sql_resource_and_caches_the_token(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => 'sql-mi-token',
            'expires_on' => (string) (time() + 3_600),
        ], JSON_THROW_ON_ERROR);
        $provider = new ManagedIdentitySqlAccessTokenProvider(
            new ManagedIdentityTokenClient($http, [
                'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
                'IDENTITY_HEADER' => 'rotating-secret',
            ]),
            new ArrayApplicationCache(),
            null,
            static fn (): int => 1_700_000_000,
        );

        self::assertSame('sql-mi-token', $provider->getAccessToken());
        self::assertSame('sql-mi-token', $provider->getAccessToken());
        self::assertCount(1, $http->calls);

        $query = [];
        parse_str((string) parse_url($http->calls[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('https://database.windows.net/', $query['resource']);
    }

    public function test_does_not_use_azure_cli(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"access_token":"imds-sql-token","expires_on":"4102444800"}';
        $provider = new ManagedIdentitySqlAccessTokenProvider(
            new ManagedIdentityTokenClient($http, []),
            new ArrayApplicationCache(),
        );

        self::assertSame('imds-sql-token', $provider->getAccessToken());
        self::assertStringContainsString('169.254.169.254', $http->calls[0]['url']);
    }

    public function test_wraps_client_failures_without_exposing_secrets(): void
    {
        $http = new FakeJsonHttpClient();
        $provider = new ManagedIdentitySqlAccessTokenProvider(
            new ManagedIdentityTokenClient($http, [
                'IDENTITY_ENDPOINT' => 'https://attacker.example/token',
                'IDENTITY_HEADER' => 'header-secret-value',
            ]),
        );

        try {
            $provider->getAccessToken();
            self::fail('Expected ManagedIdentitySqlAccessTokenException');
        } catch (ManagedIdentitySqlAccessTokenException $exception) {
            self::assertSame(
                'Failed to acquire an Azure SQL access token via managed identity.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('header-secret-value', $exception->getMessage());
            $previous = $exception->getPrevious();
            self::assertNotNull($previous);
            self::assertStringNotContainsString('header-secret-value', $previous->getMessage());
        }
    }
}
