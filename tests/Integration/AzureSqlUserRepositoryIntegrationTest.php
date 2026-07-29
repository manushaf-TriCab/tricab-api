<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;

/**
 * Live Azure SQL checks. Skipped unless RUN_AZURE_SQL_INTEGRATION_TESTS=true.
 *
 * @group azure-sql
 */
#[Group('azure-sql')]
final class AzureSqlUserRepositoryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_AZURE_SQL_INTEGRATION_TESTS') !== 'true') {
            self::markTestSkipped('Set RUN_AZURE_SQL_INTEGRATION_TESTS=true to run Azure SQL integration tests.');
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv extension is not installed.');
        }
    }

    public function test_can_query_external_identity_against_live_database(): void
    {
        /** @var array<string, string|null> $env */
        $env = $_ENV;
        $settings = Settings::fromEnvironment(array_merge($env, [
            'USER_REPOSITORY' => Settings::USER_REPOSITORY_AZURE_SQL,
        ]));

        $container = ContainerFactory::create($settings);
        $repository = $container->get(UserRepository::class);

        self::assertInstanceOf(AzureSqlUserRepository::class, $repository);

        $user = $repository->findByExternalIdentity(new ExternalIdentity(
            ExternalProvider::fromString('microsoft-entra'),
            ExternalSubjectId::fromString((string) ($env['DEV_AUTH_DEFAULT_SUBJECT'] ?? 'development-thilan')),
            ExternalTenantId::fromString((string) ($env['DEV_AUTH_DEFAULT_TENANT'] ?? 'development-tenant')),
        ));

        // Connection/query path succeeded if no DatabaseUnavailable was thrown.
        // A null external-identity match is expected until ExternalIdentities is seeded.
        if ($user !== null) {
            self::assertSame('thilanh@tricab.com', $user->emailAddress()->toString());
            self::assertTrue($user->status()->isActive());

            return;
        }

        $byEmail = $repository->findByEmail(
            \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('thilanh@tricab.com'),
        );
        self::assertNotNull($byEmail);
        self::assertSame('thilanh@tricab.com', $byEmail->emailAddress()->toString());
    }
}
