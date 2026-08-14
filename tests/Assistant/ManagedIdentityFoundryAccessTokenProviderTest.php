<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Assistant;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryProviderUnavailable;
use TriCab\Api\Assistant\Infrastructure\Foundry\ManagedIdentityFoundryAccessTokenProvider;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ManagedIdentityFoundryAccessTokenProviderTest extends TestCase
{
    public function test_uses_the_app_service_identity_endpoint_and_caches_the_token(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => 'managed-token',
            'expires_on' => (string) (time() + 3_600),
        ], JSON_THROW_ON_ERROR);
        $provider = new ManagedIdentityFoundryAccessTokenProvider($http, [
            'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
            'IDENTITY_HEADER' => 'rotating-secret',
        ]);

        self::assertSame('managed-token', $provider->accessToken());
        self::assertSame('managed-token', $provider->accessToken());
        self::assertCount(1, $http->calls);
        self::assertSame('get', $http->calls[0]['kind']);
        self::assertSame('rotating-secret', $http->calls[0]['headers']['X-IDENTITY-HEADER']);

        $query = [];
        parse_str((string) parse_url($http->calls[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2019-08-01', $query['api-version']);
        self::assertSame('https://ai.azure.com', $query['resource']);
    }

    public function test_uses_imds_when_app_service_identity_variables_are_absent(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"access_token":"imds-token","expires_on":"4102444800"}';
        $provider = new ManagedIdentityFoundryAccessTokenProvider($http, []);

        self::assertSame('imds-token', $provider->accessToken());
        self::assertStringStartsWith(
            'http://169.254.169.254/metadata/identity/oauth2/token?',
            $http->calls[0]['url'],
        );
        self::assertSame('true', $http->calls[0]['headers']['Metadata']);
    }

    public function test_rejects_a_non_local_environment_identity_endpoint(): void
    {
        $http = new FakeJsonHttpClient();
        $provider = new ManagedIdentityFoundryAccessTokenProvider($http, [
            'IDENTITY_ENDPOINT' => 'https://attacker.example/token',
            'IDENTITY_HEADER' => 'secret',
        ]);

        $this->expectException(FoundryProviderUnavailable::class);
        $this->expectExceptionMessage('local HTTP address');

        $provider->accessToken();
    }

    public function test_rejects_a_token_response_without_an_access_token(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"expires_on":"4102444800"}';
        $provider = new ManagedIdentityFoundryAccessTokenProvider($http, []);

        $this->expectException(FoundryProviderUnavailable::class);
        $this->expectExceptionMessage('did not contain a token');

        $provider->accessToken();
    }
}
