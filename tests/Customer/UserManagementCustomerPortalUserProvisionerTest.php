<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Customer;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Customer\Persistence\UserManagementCustomerPortalUserProvisioner;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class UserManagementCustomerPortalUserProvisionerTest extends TestCase
{
    public function test_creates_active_user_with_customer_role_and_resolves_user_id(): void
    {
        $store = new InMemoryUserManagementStore();
        $provisioner = new UserManagementCustomerPortalUserProvisioner($store->users());

        $email = EmailAddress::fromString('contact.portal@example.com');

        $provisioned = $provisioner->createActivePortalUser(
            email: $email,
            displayName: 'Contact Portal User',
            customerRole: ProtectedIdentityCatalog::customerUser(),
            actorUserId: UserId::fromInt(1),
        );

        self::assertSame($email->toString(), $provisioned->email->toString());
        self::assertSame('Contact Portal User', $provisioned->displayName);
        self::assertGreaterThan(0, $provisioned->userId->toInt());

        $details = $store->users()->findDetailsByPublicId($provisioned->userPublicId);
        self::assertNotNull($details);
        self::assertSame('active', $details->status);
        self::assertContains(
            ProtectedIdentityCatalog::ROLE_CUSTOMER_USER,
            array_map(static fn ($role) => $role->roleCode, $details->roles),
        );

        self::assertTrue($store->users()->userHasActiveRole(
            $provisioned->userPublicId,
            ProtectedIdentityCatalog::customerUser(),
        ));
    }

    public function test_never_fabricates_an_external_identity(): void
    {
        $store = new InMemoryUserManagementStore();
        $provisioner = new UserManagementCustomerPortalUserProvisioner($store->users());

        $provisioned = $provisioner->createActivePortalUser(
            email: EmailAddress::fromString('no.entra@example.com'),
            displayName: 'No Entra User',
            customerRole: ProtectedIdentityCatalog::customerAdmin(),
            actorUserId: UserId::fromInt(1),
        );

        $details = $store->users()->findDetailsByPublicId($provisioned->userPublicId);
        self::assertNotNull($details);
        self::assertSame([], $details->identityProviders);
    }
}
