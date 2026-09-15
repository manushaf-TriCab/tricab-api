<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\TelDirectory\Infrastructure\MicrosoftGraphTelDirectoryReader;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader;
use TriCab\Core\TelDirectory\Module;

final class TelDirectoryEndpointsTest extends TestCase
{
    public function test_unauthenticated_is_rejected(): void
    {
        $app = TestAppFactory::create(['DEV_AUTH_ENABLED' => 'false']);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tel-directory'));
        $payload = TestAppFactory::json($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $payload['error']['code']);
    }

    public function test_authenticated_without_permission_is_forbidden(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN']);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tel-directory'));
        $payload = TestAppFactory::json($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('INSUFFICIENT_PERMISSION', $payload['error']['code']);
    }

    public function test_permission_allows_and_returns_filtered_dto(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS]);
        $this->bindGraphFixture($app, [
            [
                'displayName' => 'Included',
                'jobTitle' => 'Engineer',
                'mail' => 'included@tricab.com',
                'accountEnabled' => true,
                'mobilePhone' => '0400111222',
                'businessPhones' => ['0399990000x55'],
                'officeLocation' => 'Melbourne',
            ],
            [
                'displayName' => 'Disabled',
                'jobTitle' => 'Engineer',
                'mail' => 'disabled@tricab.com',
                'accountEnabled' => false,
                'businessPhones' => [],
            ],
            [
                'displayName' => 'External',
                'jobTitle' => 'Engineer',
                'mail' => 'ext@gmail.com',
                'accountEnabled' => true,
                'businessPhones' => [],
            ],
            [
                'displayName' => 'No Title',
                'jobTitle' => '',
                'mail' => 'notitle@tricab.com',
                'accountEnabled' => true,
                'businessPhones' => [],
            ],
            [
                'displayName' => 'Excluded',
                'jobTitle' => 'Staff',
                'mail' => 'jeffe@tricab.com',
                'accountEnabled' => true,
                'businessPhones' => [],
            ],
        ]);

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tel-directory'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame(1, $payload['data']['count']);
        self::assertCount(1, $payload['data']['items']);
        $item = $payload['data']['items'][0];
        self::assertSame('Included', $item['name']);
        self::assertSame('Engineer', $item['jobTitle']);
        self::assertSame('included@tricab.com', $item['email']);
        self::assertSame('0400111222', $item['mobile']);
        self::assertSame('0399990000', $item['telephone']);
        self::assertSame('Melbourne', $item['location']);
    }

    public function test_graph_failure_returns_safe_tel_directory_unavailable(): void
    {
        $app = TestAppFactory::create();
        $this->replacePermissions($app, ['ACCOUNT.LOGIN', Module::PERMISSION_ACCESS]);

        /** @var ContainerInterface $container */
        $container = $app->getContainer();
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 400,
                'body' => json_encode([
                    'error' => [
                        'code' => 'BadRequest',
                        'message' => 'Invalid filter clause: secret-details',
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ];
        $container->set(
            TelDirectoryGraphReader::class,
            new MicrosoftGraphTelDirectoryReader($http, 'tenant', 'client', 'secret'),
        );

        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/tel-directory'));
        $payload = TestAppFactory::json($response);

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('TEL_DIRECTORY_UNAVAILABLE', $payload['error']['code']);
        self::assertSame('Unable to load the telephone directory right now.', $payload['error']['message']);
        self::assertStringNotContainsString('Invalid filter', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-details', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('HTTP 400', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $this->userRepository($app)->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }

    private function userRepository(App $app): InMemoryApiUserRepository
    {
        /** @var ContainerInterface $container */
        $container = $app->getContainer();
        $repo = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repo);

        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function bindGraphFixture(App $app, array $users): void
    {
        /** @var ContainerInterface $container */
        $container = $app->getContainer();
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 200,
                'body' => json_encode(['value' => $users], JSON_THROW_ON_ERROR),
            ],
        ];
        $container->set(
            TelDirectoryGraphReader::class,
            new MicrosoftGraphTelDirectoryReader($http, 'tenant', 'client', 'secret'),
        );
    }
}
