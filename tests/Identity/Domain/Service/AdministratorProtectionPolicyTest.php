<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Exception\LastAdminProtection;
use TriCab\Core\Identity\Domain\Exception\SelfLockoutProtection;
use TriCab\Core\Identity\Domain\Service\AdministratorProtectionPolicy;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class AdministratorProtectionPolicyTest extends TestCase
{
    private AdministratorProtectionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AdministratorProtectionPolicy();
    }

    public function test_self_lockout_status_change_is_blocked(): void
    {
        $actor = UserPublicId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $target = $actor;

        $this->expectException(SelfLockoutProtection::class);
        $this->expectExceptionMessage('cannot suspend or disable their own account');

        $this->policy->assertStatusChangeAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            newStatus: UserStatus::disabled(),
            targetHasAdministratorRole: true,
            activeAdministratorCount: 2,
        );
    }

    public function test_last_admin_status_change_is_blocked(): void
    {
        $actor = UserPublicId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $target = UserPublicId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        $this->expectException(LastAdminProtection::class);
        $this->expectExceptionMessage('final active administrator');

        $this->policy->assertStatusChangeAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            newStatus: UserStatus::disabled(),
            targetHasAdministratorRole: true,
            activeAdministratorCount: 1,
        );
    }

    public function test_self_remove_admin_role_is_blocked(): void
    {
        $actor = UserPublicId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $target = $actor;

        $this->expectException(SelfLockoutProtection::class);
        $this->expectExceptionMessage('cannot remove their own last ADMINISTRATOR role');

        $this->policy->assertAdministratorRoleRemovalAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            roleCode: ProtectedIdentityCatalog::administrator(),
            activeAdministratorCount: 2,
        );
    }

    public function test_last_admin_remove_role_is_blocked(): void
    {
        $actor = UserPublicId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $target = UserPublicId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        $this->expectException(LastAdminProtection::class);
        $this->expectExceptionMessage('final active administrator');

        $this->policy->assertAdministratorRoleRemovalAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            roleCode: ProtectedIdentityCatalog::administrator(),
            activeAdministratorCount: 1,
        );
    }

    public function test_non_admin_role_removal_is_allowed(): void
    {
        self::expectNotToPerformAssertions();

        $actor = UserPublicId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $target = UserPublicId::fromString('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        $this->policy->assertAdministratorRoleRemovalAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            roleCode: RoleCode::fromString('EMPLOYEE'),
            activeAdministratorCount: 1,
        );

        $this->policy->assertStatusChangeAllowed(
            targetPublicId: $target,
            actorPublicId: $actor,
            newStatus: UserStatus::disabled(),
            targetHasAdministratorRole: false,
            activeAdministratorCount: 1,
        );
    }
}
