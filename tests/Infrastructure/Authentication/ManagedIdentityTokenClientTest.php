<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure\Authentication;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenClient;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenException;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;

final class ManagedIdentityTokenClientTest extends TestCase
{
    public function test_uses_app_service_identity_endpoint_for_the_requested_resource(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = json_encode([
            'access_token' => 'managed-token',
            'expires_on' => (string) (time() + 3_600),
        ], JSON_THROW_ON_ERROR);
        $client = new ManagedIdentityTokenClient($http, [
            'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
            'IDENTITY_HEADER' => 'rotating-secret',
        ]);

        $token = $client->fetch('https://database.windows.net/');

        self::assertSame('managed-token', $token->token);
        self::assertSame(ManagedIdentityTokenClient::ENDPOINT_KIND_APP_SERVICE, $token->endpointKind);
        self::assertSame('rotating-secret', $http->calls[0]['headers']['X-IDENTITY-HEADER']);

        $query = [];
        parse_str((string) parse_url($http->calls[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2019-08-01', $query['api-version']);
        self::assertSame('https://database.windows.net/', $query['resource']);
    }

    public function test_uses_imds_when_app_service_identity_variables_are_absent(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"access_token":"imds-token","expires_on":"4102444800"}';
        $client = new ManagedIdentityTokenClient($http, []);

        $token = $client->fetch('https://database.windows.net/');

        self::assertSame('imds-token', $token->token);
        self::assertSame(ManagedIdentityTokenClient::ENDPOINT_KIND_IMDS, $token->endpointKind);
        self::assertStringStartsWith(
            'http://169.254.169.254/metadata/identity/oauth2/token?',
            $http->calls[0]['url'],
        );
        self::assertSame('true', $http->calls[0]['headers']['Metadata']);
    }

    public function test_rejects_a_non_local_environment_identity_endpoint(): void
    {
        $http = new FakeJsonHttpClient();
        $client = new ManagedIdentityTokenClient($http, [
            'IDENTITY_ENDPOINT' => 'https://attacker.example/token',
            'IDENTITY_HEADER' => 'secret',
        ]);

        $this->expectException(ManagedIdentityTokenException::class);
        $this->expectExceptionMessage('local HTTP address');

        $client->fetch('https://database.windows.net/');
    }

    public function test_rejects_incomplete_app_service_identity_environment(): void
    {
        $http = new FakeJsonHttpClient();
        $client = new ManagedIdentityTokenClient($http, [
            'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
        ]);

        $this->expectException(ManagedIdentityTokenException::class);
        $this->expectExceptionMessage('incomplete');

        $client->fetch('https://database.windows.net/');
    }

    public function test_exception_messages_do_not_include_tokens_or_identity_headers(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"expires_on":"4102444800","access_token":""}';
        $client = new ManagedIdentityTokenClient($http, [
            'IDENTITY_ENDPOINT' => 'http://127.0.0.1:41741/MSI/token',
            'IDENTITY_HEADER' => 'header-secret-value',
        ]);

        try {
            $client->fetch('https://database.windows.net/');
            self::fail('Expected ManagedIdentityTokenException');
        } catch (ManagedIdentityTokenException $exception) {
            self::assertStringNotContainsString('header-secret-value', $exception->getMessage());
            self::assertStringNotContainsString('access_token', $exception->getMessage());
        }
    }
}
