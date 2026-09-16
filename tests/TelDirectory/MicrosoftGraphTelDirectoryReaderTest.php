<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\TelDirectory;

use PHPUnit\Framework\TestCase;
use TriCab\Api\TelDirectory\Infrastructure\MicrosoftGraphTelDirectoryReader;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\TelDirectory\Application\Exception\TelDirectoryUnavailable;

final class MicrosoftGraphTelDirectoryReaderTest extends TestCase
{
    public function test_initial_users_url_encodes_odata_query_parameters(): void
    {
        $url = MicrosoftGraphTelDirectoryReader::buildInitialUsersUrl();

        self::assertStringStartsWith('https://graph.microsoft.com/v1.0/users?', $url);

        // Previous bug: raw spaces in `$filter=accountEnabled eq true`.
        self::assertStringNotContainsString('accountEnabled eq true', $url);
        self::assertStringNotContainsString('$filter=accountEnabled eq', $url);

        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $params);

        // http_build_query(RFC3986) encodes `$` as %24; parse_str restores `$filter`.
        self::assertArrayHasKey('$filter', $params);
        self::assertSame('accountEnabled eq true', $params['$filter']);
        self::assertArrayHasKey('$select', $params);
        self::assertSame(
            'displayName,jobTitle,mail,postalCode,mobilePhone,businessPhones,officeLocation,accountEnabled',
            $params['$select'],
        );
        self::assertArrayHasKey('$top', $params);
        self::assertSame('999', $params['$top']);

        // Spaces must be %20 (RFC3986), not `+` or raw.
        self::assertMatchesRegularExpression('/(?:\$|%24)filter=accountEnabled%20eq%20true/', $url);
        self::assertStringContainsString('%20', $url);
        self::assertDoesNotMatchRegularExpression('/(?:\$|%24)filter=accountEnabled\+/', $url);
    }

    public function test_list_uses_encoded_initial_url_and_preserves_next_link(): void
    {
        $nextLink = 'https://graph.microsoft.com/v1.0/users?$skiptoken=page2%20keep';

        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'tok',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 200,
                'body' => json_encode([
                    'value' => [
                        [
                            'displayName' => 'A',
                            'jobTitle' => 'Eng',
                            'mail' => 'a@tricab.com',
                            'accountEnabled' => true,
                            'businessPhones' => [],
                        ],
                    ],
                    '@odata.nextLink' => $nextLink,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 200,
                'body' => json_encode([
                    'value' => [
                        [
                            'displayName' => 'B',
                            'jobTitle' => 'Sales',
                            'mail' => 'b@tricab.com',
                            'accountEnabled' => true,
                            'businessPhones' => [],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        $reader = new MicrosoftGraphTelDirectoryReader($http, 'tenant', 'client', 'secret');
        $users = $reader->listActiveDirectoryUsers();

        self::assertCount(2, $users);
        self::assertSame('A', $users[0]['displayName']);
        self::assertSame('B', $users[1]['displayName']);

        $getCalls = array_values(array_filter(
            $http->calls,
            static fn (array $call): bool => $call['kind'] === 'get',
        ));
        self::assertCount(2, $getCalls);

        $initialUrl = $getCalls[0]['url'];
        self::assertSame(MicrosoftGraphTelDirectoryReader::buildInitialUsersUrl(), $initialUrl);
        self::assertStringNotContainsString('accountEnabled eq true', $initialUrl);
        self::assertMatchesRegularExpression('/(?:\$|%24)filter=accountEnabled%20eq%20true/', $initialUrl);

        // nextLink must be used verbatim — no double-encoding / reconstruction.
        self::assertSame($nextLink, $getCalls[1]['url']);
        self::assertStringContainsString('skiptoken=page2%20keep', $getCalls[1]['url']);
        self::assertStringNotContainsString('page2%2520keep', $getCalls[1]['url']);
    }

    public function test_graph_http_error_maps_to_tel_directory_unavailable(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'tok',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 400,
                'body' => json_encode([
                    'error' => [
                        'code' => 'BadRequest',
                        'message' => 'Invalid filter clause',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        $reader = new MicrosoftGraphTelDirectoryReader($http, 'tenant', 'client', 'secret');

        try {
            $reader->listActiveDirectoryUsers();
            self::fail('Expected TelDirectoryUnavailable');
        } catch (TelDirectoryUnavailable $exception) {
            self::assertStringContainsString('Unable to load Tel Directory from Microsoft Graph.', $exception->getMessage());
            self::assertStringContainsString('(HTTP 400)', $exception->getMessage());
            self::assertStringNotContainsString('Invalid filter clause', $exception->getMessage());
            self::assertStringNotContainsString('access_token', $exception->getMessage());
        }
    }
}
