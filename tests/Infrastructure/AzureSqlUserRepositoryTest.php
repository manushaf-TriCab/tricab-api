<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class AzureSqlUserRepositoryTest extends TestCase
{
    public function test_find_by_external_identity_hydrates_deduplicated_user(): void
    {
        $connection = new FakeSqlConnection([
            [
                'UserId' => 1,
                'PublicId' => '550e8400-e29b-41d4-a716-446655440000',
                'EmailAddress' => 'thilanh@tricab.com',
                'DisplayName' => 'Thilan Lakshitha',
                'StatusCode' => 'active',
                'AllowsLogin' => 1,
                'RoleCode' => 'employee',
                'PermissionCode' => 'account.login',
            ],
            [
                'UserId' => 1,
                'PublicId' => '550e8400-e29b-41d4-a716-446655440000',
                'EmailAddress' => 'thilanh@tricab.com',
                'DisplayName' => 'Thilan Lakshitha',
                'StatusCode' => 'active',
                'AllowsLogin' => 1,
                'RoleCode' => 'employee',
                'PermissionCode' => 'account.login',
            ],
        ]);

        $repository = new AzureSqlUserRepository($connection);
        $user = $repository->findByExternalIdentity(new ExternalIdentity(
            ExternalProvider::microsoftEntra(),
            ExternalSubjectId::fromString('development-thilan'),
            ExternalTenantId::fromString('development-tenant'),
        ));

        self::assertNotNull($user);
        self::assertSame(1, $user->id()->toInt());
        self::assertSame('thilanh@tricab.com', $user->emailAddress()->toString());
        self::assertSame(['EMPLOYEE'], array_map(
            static fn (\TriCab\Core\Identity\Domain\ValueObject\RoleCode $role): string => $role->toString(),
            $user->roles(),
        ));
        self::assertSame(['ACCOUNT.LOGIN'], array_map(
            static fn (\TriCab\Core\Identity\Domain\ValueObject\PermissionCode $permission): string => $permission->toString(),
            $user->permissions(),
        ));

        self::assertCount(1, $connection->calls);
        self::assertSame('microsoft_entra', $connection->calls[0]['params'][':provider']);
        self::assertSame('microsoft-entra', $connection->calls[0]['params'][':providerAlt']);
        self::assertStringContainsString('ExternalIdentities', $connection->calls[0]['sql']);
        self::assertStringNotContainsString("N'", $connection->calls[0]['sql']);
    }

    public function test_find_by_id_and_email_use_parameterized_lookups(): void
    {
        $connection = new FakeSqlConnection([]);
        $repository = new AzureSqlUserRepository($connection);

        self::assertNull($repository->findById(UserId::fromInt(42)));
        self::assertNull($repository->findByEmail(EmailAddress::fromString('nobody@tricab.com')));

        self::assertSame(42, $connection->calls[0]['params'][':userId']);
        self::assertSame('nobody@tricab.com', $connection->calls[1]['params'][':email']);
    }

    public function test_null_display_name_falls_back_to_email(): void
    {
        $connection = new FakeSqlConnection([
            [
                'UserId' => 7,
                'PublicId' => '550e8400-e29b-41d4-a716-446655440000',
                'EmailAddress' => 'thilanh@tricab.com',
                'DisplayName' => null,
                'StatusCode' => 'active',
                'AllowsLogin' => 1,
                'RoleCode' => null,
                'PermissionCode' => null,
            ],
        ]);

        $user = (new AzureSqlUserRepository($connection))->findById(UserId::fromInt(7));

        self::assertNotNull($user);
        self::assertSame('thilanh@tricab.com', $user->displayName());
        self::assertSame([], $user->roles());
    }
}
