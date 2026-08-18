<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Domain\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class UserTest extends TestCase
{
    public function test_exposes_core_identity_fields(): void
    {
        $user = $this->user(
            displayName: '  Jane Doe  ',
            status: UserStatus::active(),
        );

        self::assertSame(1, $user->id()->toInt());
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $user->publicId()->toString());
        self::assertSame('jane@example.com', $user->emailAddress()->toString());
        self::assertSame('Jane Doe', $user->displayName());
        self::assertTrue($user->status()->isActive());
    }

    public function test_rejects_blank_display_name(): void
    {
        $this->expectException(InvalidIdentityValue::class);

        $this->user(displayName: '   ');
    }

    public function test_deduplicates_roles_and_permissions(): void
    {
        $user = $this->user(
            roles: [
                RoleCode::fromString('STAFF'),
                RoleCode::fromString('staff'),
                RoleCode::fromString('ADMIN'),
            ],
            permissions: [
                PermissionCode::fromString('ORDERS:READ'),
                PermissionCode::fromString('orders:read'),
                PermissionCode::fromString('ORDERS:WRITE'),
            ],
        );

        self::assertCount(2, $user->roles());
        self::assertCount(2, $user->permissions());
        self::assertTrue($user->hasRole(RoleCode::fromString('STAFF')));
        self::assertTrue($user->hasRole(RoleCode::fromString('ADMIN')));
        self::assertFalse($user->hasRole(RoleCode::fromString('CUSTOMER')));
        self::assertTrue($user->hasPermission(PermissionCode::fromString('ORDERS:READ')));
        self::assertTrue($user->hasPermission(PermissionCode::fromString('ORDERS:WRITE')));
        self::assertFalse($user->hasPermission(PermissionCode::fromString('ORDERS:DELETE')));
    }

    public function test_active_user_allows_authentication(): void
    {
        $user = $this->user(status: UserStatus::active());

        self::assertTrue($user->isAuthenticationAllowed());
        $user->assertAuthenticationAllowed();
    }

    #[DataProvider('blockedStatuses')]
    public function test_non_active_users_reject_authentication(UserStatus $status): void
    {
        $user = $this->user(status: $status);

        self::assertFalse($user->isAuthenticationAllowed());

        $this->expectException(AuthenticationNotAllowed::class);
        $user->assertAuthenticationAllowed();
    }

    /** @return iterable<string, array{UserStatus}> */
    public static function blockedStatuses(): iterable
    {
        yield 'pending' => [UserStatus::pending()];
        yield 'disabled' => [UserStatus::disabled()];
    }

    /**
     * @param list<RoleCode> $roles
     * @param list<PermissionCode> $permissions
     */
    private function user(
        string $displayName = 'Jane Doe',
        ?UserStatus $status = null,
        array $roles = [],
        array $permissions = [],
    ): User {
        return new User(
            UserId::fromInt(1),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString('jane@example.com'),
            $displayName,
            $status ?? UserStatus::active(),
            $roles,
            $permissions,
        );
    }
}
