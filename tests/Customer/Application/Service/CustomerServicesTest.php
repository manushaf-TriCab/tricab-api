<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Customer\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Customer\Application\Exception\CustomerCodeConflict;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerContactUserLinkNotFound;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Application\Service\ChangeCustomerContactStatusService;
use TriCab\Core\Customer\Application\Service\ChangeCustomerStatusService;
use TriCab\Core\Customer\Application\Service\CreateCustomerContactService;
use TriCab\Core\Customer\Application\Service\CreateCustomerPortalUserService;
use TriCab\Core\Customer\Application\Service\CreateCustomerService;
use TriCab\Core\Customer\Application\Service\DisableCustomerPortalAccessService;
use TriCab\Core\Customer\Application\Service\EnableCustomerPortalAccessService;
use TriCab\Core\Customer\Application\Service\GetCustomerContactService;
use TriCab\Core\Customer\Application\Service\GetCustomerService;
use TriCab\Core\Customer\Application\Service\LinkCustomerContactToUserService;
use TriCab\Core\Customer\Application\Service\ListCustomerContactsService;
use TriCab\Core\Customer\Application\Service\ListCustomersService;
use TriCab\Core\Customer\Application\Service\UnlinkCustomerContactFromUserService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerContactService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerService;
use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Domain\Exception\DuplicateContactUserLink;
use TriCab\Core\Customer\Domain\Exception\InactiveContactPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InactiveCustomerPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerRoleAssignment;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\Exception\InvalidPortalAccessTransition;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Tests\Customer\Doubles\FakeCustomerPortalUserProvisioner;
use TriCab\Core\Tests\Customer\Doubles\InMemoryCustomerStore;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserManagementStore;

final class CustomerServicesTest extends TestCase
{
    private InMemoryCustomerStore $customerStore;
    private InMemoryUserManagementStore $identityStore;
    private FakeCustomerPortalUserProvisioner $provisioner;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $clock = FixedClock::utc('2026-08-04T02:00:00+00:00');
        $this->customerStore = new InMemoryCustomerStore($clock);
        $this->identityStore = new InMemoryUserManagementStore($clock);
        $this->provisioner = new FakeCustomerPortalUserProvisioner($this->identityStore);

        $admin = $this->identityStore->seedUser(
            emailAddress: 'admin@example.com',
            displayName: 'Primary Admin',
            roleCodes: [ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
        );
        $adminUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($admin->publicId),
        );
        assert($adminUserId !== null);

        $this->actor = new ActorContext(
            userId: $adminUserId->toInt(),
            publicId: $admin->publicId,
            emailAddress: $admin->emailAddress,
            correlationId: 'corr-test-001',
        );
    }

    // ---- Customer create/update ----

    public function test_create_customer_requires_non_empty_name(): void
    {
        $service = new CreateCustomerService($this->customerStore->customers());

        $this->expectException(InvalidCustomerValue::class);

        $service->execute($this->actor, '   ');
    }

    public function test_create_customer_rejects_duplicate_code(): void
    {
        $service = new CreateCustomerService($this->customerStore->customers());

        $service->execute($this->actor, 'Acme Pty Ltd', 'ACME-001');

        $this->expectException(CustomerCodeConflict::class);

        $service->execute($this->actor, 'Acme Duplicate', 'ACME-001');
    }

    public function test_create_and_get_customer(): void
    {
        $create = new CreateCustomerService($this->customerStore->customers());
        $get = new GetCustomerService($this->customerStore->customers());

        $created = $create->execute(
            $this->actor,
            'Acme Pty Ltd',
            'ACME-001',
            CustomerStatus::ACTIVE,
            addressLine1: '1 Example St',
            suburb: 'Sydney',
            postcode: '2000',
            countryCode: 'AU',
        );

        $fetched = $get->execute($created->publicId);

        self::assertSame('Acme Pty Ltd', $fetched->customerName);
        self::assertSame('ACME-001', $fetched->customerCode);
        self::assertTrue($fetched->isActive);
        self::assertSame('Sydney', $fetched->suburb);
    }

    public function test_get_customer_throws_when_missing(): void
    {
        $get = new GetCustomerService($this->customerStore->customers());

        $this->expectException(CustomerNotFound::class);

        $get->execute('00000000-0000-4000-8000-000000000000');
    }

    public function test_update_customer_allows_reusing_own_code(): void
    {
        $create = new CreateCustomerService($this->customerStore->customers());
        $update = new UpdateCustomerService($this->customerStore->customers());

        $created = $create->execute($this->actor, 'Acme Pty Ltd', 'ACME-001');

        $updated = $update->execute(
            $this->actor,
            $created->publicId,
            'Acme Pty Ltd (Updated)',
            'ACME-001',
            suburb: 'Melbourne',
        );

        self::assertSame('Acme Pty Ltd (Updated)', $updated->customerName);
        self::assertSame('Melbourne', $updated->suburb);
    }

    public function test_update_customer_rejects_code_conflict_with_another_customer(): void
    {
        $create = new CreateCustomerService($this->customerStore->customers());
        $update = new UpdateCustomerService($this->customerStore->customers());

        $create->execute($this->actor, 'Acme Pty Ltd', 'ACME-001');
        $other = $create->execute($this->actor, 'Other Pty Ltd', 'OTHER-001');

        $this->expectException(CustomerCodeConflict::class);

        $update->execute($this->actor, $other->publicId, 'Other Pty Ltd', 'ACME-001');
    }

    public function test_change_customer_status(): void
    {
        $create = new CreateCustomerService($this->customerStore->customers());
        $changeStatus = new ChangeCustomerStatusService($this->customerStore->customers());

        $created = $create->execute($this->actor, 'Acme Pty Ltd');

        $updated = $changeStatus->execute($this->actor, $created->publicId, CustomerStatus::INACTIVE);

        self::assertSame(CustomerStatus::INACTIVE, $updated->status);
        self::assertFalse($updated->isActive);
    }

    public function test_list_customers_filters_by_search(): void
    {
        $create = new CreateCustomerService($this->customerStore->customers());
        $list = new ListCustomersService($this->customerStore->customers());

        $create->execute($this->actor, 'Acme Pty Ltd', 'ACME-001');
        $create->execute($this->actor, 'Beta Industries', 'BETA-001');

        $result = $list->execute(new CustomerListCriteria(search: 'Beta'));

        self::assertCount(1, $result->items);
        self::assertSame('Beta Industries', $result->items[0]->customerName);
    }

    // ---- Contact create/update/validation ----

    public function test_create_contact_requires_non_empty_display_name(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $service = new CreateCustomerContactService($this->customerStore->contacts(), $this->customerStore->customers());

        $this->expectException(InvalidCustomerValue::class);

        $service->execute($this->actor, $customer->publicId, '   ');
    }

    public function test_create_contact_requires_existing_customer(): void
    {
        $service = new CreateCustomerContactService($this->customerStore->contacts(), $this->customerStore->customers());

        $this->expectException(CustomerNotFound::class);

        $service->execute($this->actor, '00000000-0000-4000-8000-000000000000', 'Jane Doe');
    }

    public function test_create_and_get_contact(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $create = new CreateCustomerContactService($this->customerStore->contacts(), $this->customerStore->customers());
        $get = new GetCustomerContactService($this->customerStore->contacts());

        $created = $create->execute(
            $this->actor,
            $customer->publicId,
            'Jane Doe',
            'jane.doe@example.com',
            isPrimary: true,
        );

        $fetched = $get->execute($created->publicId);

        self::assertSame('Jane Doe', $fetched->displayName);
        self::assertSame('jane.doe@example.com', $fetched->email);
        self::assertTrue($fetched->isPrimary);
        self::assertSame('not-invited', $fetched->portalAccessStatus);
    }

    public function test_update_contact_rejects_invalid_email(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $created = $this->customerStore->seedContact($this->requireCustomerId($customer->publicId), 'Jane Doe');
        $update = new UpdateCustomerContactService($this->customerStore->contacts());

        $this->expectException(\TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue::class);

        $update->execute($this->actor, $created->publicId, 'Jane Doe', 'not-an-email');
    }

    public function test_change_contact_status(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $created = $this->customerStore->seedContact($this->requireCustomerId($customer->publicId), 'Jane Doe');
        $changeStatus = new ChangeCustomerContactStatusService($this->customerStore->contacts());

        $updated = $changeStatus->execute($this->actor, $created->publicId, false);

        self::assertFalse($updated->isActive);
    }

    public function test_list_contacts_scoped_to_customer(): void
    {
        $customerA = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $customerB = $this->customerStore->seedCustomer('Beta Industries');
        $this->customerStore->seedContact($this->requireCustomerId($customerA->publicId), 'Jane Doe');
        $this->customerStore->seedContact($this->requireCustomerId($customerB->publicId), 'John Smith');

        $list = new ListCustomerContactsService($this->customerStore->contacts(), $this->customerStore->customers());
        $result = $list->execute($customerA->publicId, new ContactListCriteria());

        self::assertCount(1, $result->items);
        self::assertSame('Jane Doe', $result->items[0]->displayName);
    }

    // ---- Portal access rules ----

    public function test_enable_portal_access_requires_email(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact($this->requireCustomerId($customer->publicId), 'Jane Doe');
        $service = new EnableCustomerPortalAccessService($this->customerStore->contacts(), $this->customerStore->customers());

        $this->expectException(InvalidCustomerValue::class);

        $service->execute($this->actor, $contact->publicId);
    }

    public function test_enable_portal_access_rejects_inactive_contact(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
            isActive: false,
        );
        $service = new EnableCustomerPortalAccessService($this->customerStore->contacts(), $this->customerStore->customers());

        $this->expectException(InactiveContactPortalAccess::class);

        $service->execute($this->actor, $contact->publicId);
    }

    public function test_enable_portal_access_rejects_inactive_customer(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );
        $changeStatus = new ChangeCustomerStatusService($this->customerStore->customers());
        $changeStatus->execute($this->actor, $customer->publicId, CustomerStatus::INACTIVE);

        $service = new EnableCustomerPortalAccessService($this->customerStore->contacts(), $this->customerStore->customers());

        $this->expectException(InactiveCustomerPortalAccess::class);

        $service->execute($this->actor, $contact->publicId);
    }

    public function test_enable_then_disable_portal_access(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $enable = new EnableCustomerPortalAccessService($this->customerStore->contacts(), $this->customerStore->customers());
        $disable = new DisableCustomerPortalAccessService($this->customerStore->contacts());

        $invited = $enable->execute($this->actor, $contact->publicId);
        self::assertSame('invited', $invited->portalAccessStatus);

        $activated = $enable->execute($this->actor, $contact->publicId, activateImmediately: true);
        self::assertSame('active', $activated->portalAccessStatus);

        $suspended = $disable->execute($this->actor, $contact->publicId);
        self::assertSame('suspended', $suspended->portalAccessStatus);
    }

    public function test_disable_from_not_invited_is_rejected(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $disable = new DisableCustomerPortalAccessService($this->customerStore->contacts());

        $this->expectException(InvalidPortalAccessTransition::class);

        $disable->execute($this->actor, $contact->publicId);
    }

    // ---- Create portal user (orchestration) ----

    public function test_create_customer_portal_user_provisions_links_and_activates(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $service = new CreateCustomerPortalUserService(
            $this->customerStore->contacts(),
            $this->customerStore->customers(),
            $this->customerStore->links(),
            $this->provisioner,
        );

        $result = $service->execute(
            $this->actor,
            $contact->publicId,
            ProtectedIdentityCatalog::ROLE_CUSTOMER_USER,
        );

        self::assertSame('jane.doe@example.com', $result->emailAddress);
        self::assertSame('Jane Doe', $result->displayName);
        self::assertTrue($result->link->isPrimary);
        self::assertTrue($result->link->isActive);
        self::assertSame('active', $result->contact->portalAccessStatus);

        self::assertCount(1, $this->provisioner->provisionedUsers());
        self::assertSame(ProtectedIdentityCatalog::ROLE_CUSTOMER_USER, $this->provisioner->provisionedUsers()[0]['roleCode']);
    }

    public function test_create_customer_portal_user_rejects_non_customer_role(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $service = new CreateCustomerPortalUserService(
            $this->customerStore->contacts(),
            $this->customerStore->customers(),
            $this->customerStore->links(),
            $this->provisioner,
        );

        $this->expectException(InvalidCustomerRoleAssignment::class);

        $service->execute($this->actor, $contact->publicId, ProtectedIdentityCatalog::ROLE_ADMINISTRATOR);
    }

    public function test_create_customer_portal_user_requires_contact_email(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact($this->requireCustomerId($customer->publicId), 'Jane Doe');

        $service = new CreateCustomerPortalUserService(
            $this->customerStore->contacts(),
            $this->customerStore->customers(),
            $this->customerStore->links(),
            $this->provisioner,
        );

        $this->expectException(InvalidCustomerValue::class);

        $service->execute($this->actor, $contact->publicId, ProtectedIdentityCatalog::ROLE_CUSTOMER_USER);
    }

    public function test_create_customer_portal_user_rejects_inactive_customer(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );
        (new ChangeCustomerStatusService($this->customerStore->customers()))
            ->execute($this->actor, $customer->publicId, CustomerStatus::INACTIVE);

        $service = new CreateCustomerPortalUserService(
            $this->customerStore->contacts(),
            $this->customerStore->customers(),
            $this->customerStore->links(),
            $this->provisioner,
        );

        $this->expectException(InactiveCustomerPortalAccess::class);

        $service->execute($this->actor, $contact->publicId, ProtectedIdentityCatalog::ROLE_CUSTOMER_USER);
    }

    // ---- Link / unlink ----

    public function test_link_and_unlink_contact_to_user(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());
        $unlink = new UnlinkCustomerContactFromUserService($this->customerStore->contacts(), $this->customerStore->links());

        $linked = $link->execute($this->actor, $contact->publicId, $employeeUserId->toInt(), isPrimary: true);
        self::assertTrue($linked->isActive);
        self::assertTrue($linked->isPrimary);

        $unlink->execute($this->actor, $contact->publicId, $employeeUserId->toInt());

        $this->expectException(CustomerContactUserLinkNotFound::class);
        $unlink->execute($this->actor, $contact->publicId, $employeeUserId->toInt());
    }

    public function test_link_rejects_duplicate_active_link(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());
        $link->execute($this->actor, $contact->publicId, $employeeUserId->toInt());

        $this->expectException(DuplicateContactUserLink::class);

        $link->execute($this->actor, $contact->publicId, $employeeUserId->toInt());
    }

    public function test_link_rejects_second_active_primary_user(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $employeeA = $this->identityStore->seedUser('employee-a@example.com', 'Employee A');
        $employeeAId = $this->identityStore->users()->findUserIdByPublicId(UserPublicId::fromString($employeeA->publicId));
        assert($employeeAId !== null);

        $employeeB = $this->identityStore->seedUser('employee-b@example.com', 'Employee B');
        $employeeBId = $this->identityStore->users()->findUserIdByPublicId(UserPublicId::fromString($employeeB->publicId));
        assert($employeeBId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());
        $link->execute($this->actor, $contact->publicId, $employeeAId->toInt(), isPrimary: true);

        $this->expectException(DuplicateContactUserLink::class);

        $link->execute($this->actor, $contact->publicId, $employeeBId->toInt(), isPrimary: true);
    }

    public function test_unlink_throws_when_no_active_link(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $unlink = new UnlinkCustomerContactFromUserService($this->customerStore->contacts(), $this->customerStore->links());

        $this->expectException(CustomerContactUserLinkNotFound::class);

        $unlink->execute($this->actor, $contact->publicId, $employeeUserId->toInt());
    }

    public function test_link_throws_when_contact_missing(): void
    {
        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());

        $this->expectException(CustomerContactNotFound::class);

        $link->execute($this->actor, '00000000-0000-4000-8000-000000000000', $employeeUserId->toInt());
    }

    // ---- Customer scope for a user (CustomerContactUserRepository) ----

    public function test_list_active_links_for_user_reflects_customer_scope(): void
    {
        $customerA = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $customerB = $this->customerStore->seedCustomer('Beta Industries');

        $contactA = $this->customerStore->seedContact(
            $this->requireCustomerId($customerA->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );
        $contactB = $this->customerStore->seedContact(
            $this->requireCustomerId($customerB->publicId),
            'John Smith',
            'john.smith@example.com',
        );

        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());
        $link->execute($this->actor, $contactA->publicId, $employeeUserId->toInt(), isPrimary: true);
        $link->execute($this->actor, $contactB->publicId, $employeeUserId->toInt(), isPrimary: true);

        $activeLinks = $this->customerStore->links()->listActiveLinksForUser($employeeUserId);

        self::assertCount(2, $activeLinks);

        $customerPublicIds = array_map(
            static fn ($link) => $link->customerPublicId,
            $activeLinks,
        );

        self::assertContains($customerA->publicId, $customerPublicIds);
        self::assertContains($customerB->publicId, $customerPublicIds);
    }

    public function test_list_portal_users_for_customer(): void
    {
        $customer = $this->customerStore->seedCustomer('Acme Pty Ltd');
        $contact = $this->customerStore->seedContact(
            $this->requireCustomerId($customer->publicId),
            'Jane Doe',
            'jane.doe@example.com',
        );

        $employee = $this->identityStore->seedUser('employee@example.com', 'Employee User');
        $employeeUserId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        $link = new LinkCustomerContactToUserService($this->customerStore->contacts(), $this->customerStore->links());
        $link->execute($this->actor, $contact->publicId, $employeeUserId->toInt(), isPrimary: true);

        $customerId = $this->requireCustomerId($customer->publicId);
        $portalUsers = $this->customerStore->links()->listPortalUsersForCustomer($customerId);

        self::assertCount(1, $portalUsers);
        self::assertSame($contact->publicId, $portalUsers[0]->contactPublicId);
    }

    private function requireCustomerId(string $publicId): \TriCab\Core\Customer\Domain\ValueObject\CustomerId
    {
        $id = $this->customerStore->customers()->findIdByPublicId(
            \TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId::fromString($publicId),
        );
        assert($id !== null);

        return $id;
    }
}
