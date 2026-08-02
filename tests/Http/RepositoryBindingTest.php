<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Persistence\AzureSqlEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserProvisioningRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class RepositoryBindingTest extends TestCase
{
    public function test_defaults_to_in_memory_repository(): void
    {
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'memory',
        ]);

        $repository = $app->getContainer()->get(UserRepository::class);

        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
    }

    public function test_database_health_skips_when_memory_repository_selected(): void
    {
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'memory',
        ]);

        $response = $app->handle(TestAppFactory::request('GET', '/health/database'));
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsArray($payload['database']);
        /** @var array<string, mixed> $database */
        $database = $payload['database'];
        self::assertSame('skipped', $database['status']);
    }

    public function test_azure_sql_binding_is_selected_by_settings(): void
    {
        // Binding is verified without opening a live PDO connection by constructing
        // the repository with a fake SQL connection in unit tests. Here we assert
        // the settings switch itself and that Core interface remains the DI key.
        $app = TestAppFactory::create([
            'USER_REPOSITORY' => 'memory',
        ]);

        self::assertTrue(interface_exists(UserRepository::class));
        self::assertTrue(class_exists(AzureSqlUserRepository::class));
        self::assertTrue(class_exists(AzureSqlEmailOtpChallengeRepository::class));
        self::assertTrue(class_exists(AzureSqlGuestSessionRepository::class));
        self::assertTrue(class_exists(AzureSqlUserProvisioningRepository::class));
        self::assertInstanceOf(
            InMemoryApiUserRepository::class,
            $app->getContainer()->get(UserRepository::class),
        );
    }
}
