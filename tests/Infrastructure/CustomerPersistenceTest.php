<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerContactRepository;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerContactUserRepository;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class CustomerPersistenceTest extends TestCase
{
    private const SAMPLE_CUSTOMER_PUBLIC_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const SAMPLE_CONTACT_PUBLIC_ID = '660e8400-e29b-41d4-a716-446655440111';
    private const SAMPLE_USER_PUBLIC_ID = '770e8400-e29b-41d4-a716-446655440222';

    // ------------------------------------------------------------------
    // AzureSqlCustomerRepository
    // ------------------------------------------------------------------

    public function test_list_customers_builds_parameterized_sql_with_whitelisted_sort_column(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->customerSummaryRow()]);
        $connection->queueFetch([$this->customerSummaryCountsRow()]);

        $repository = new AzureSqlCustomerRepository($connection);

        $criteria = new CustomerListCriteria(
            page: 2,
            pageSize: 10,
            search: "o'brien",
            status: 'active',
            sortBy: CustomerListCriteria::SORT_NAME,
            sortDirection: CustomerListCriteria::SORT_ASC,
        );

        $result = $repository->list($criteria);

        self::assertSame(1, $result->pagination->totalItems);
        self::assertCount(1, $result->items);
        self::assertSame(5, $result->counts->totalCustomers);
        self::assertSame('VIC', $result->items[0]->marketCode);
        self::assertSame('MEL', $result->items[0]->territoryCode);
        self::assertSame('+61 3 9000 0000', $result->items[0]->phone);
        self::assertSame(2, $result->items[0]->contactCount);
        self::assertSame(1, $result->items[0]->portalUserCount);

        $listSql = $connection->calls[1]['sql'];
        $listParams = $connection->calls[1]['params'];

        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $listSql);
        self::assertStringContainsString('ORDER BY c.[CustomerName] ASC', $listSql);
        self::assertStringNotContainsString("o'brien", $listSql);
        self::assertSame("%o'brien%", $listParams[':searchName']);
        self::assertSame("%o'brien%", $listParams[':searchCode']);
        self::assertSame("%o'brien%", $listParams[':searchPublicId']);
        self::assertSame('active', $listParams[':status']);
        self::assertSame(10, $listParams[':pageSize']);
        self::assertSame(10, $listParams[':offset']);
    }

    public function test_list_customers_defaults_sort_to_created_at_when_unspecified(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);
        $connection->queueFetch([$this->customerSummaryCountsRow()]);

        $repository = new AzureSqlCustomerRepository($connection);

        $repository->list(new CustomerListCriteria());

        $listSql = $connection->calls[1]['sql'];
        self::assertStringContainsString('ORDER BY c.[CreatedAtUtc] DESC', $listSql);
    }

    public function test_create_customer_runs_in_transaction_and_syncs_is_active_with_status(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['PublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID]]);
        $connection->queueFetch([$this->customerDetailsRow()]);

        $repository = new AzureSqlCustomerRepository($connection);

        $details = $repository->create(
            customerCode: CustomerCode::fromString('CUST001'),
            customerName: 'Acme Pty Ltd',
            status: CustomerStatus::active(),
            addressLine1: '1 Main St',
            addressLine2: null,
            suburb: 'Melbourne',
            postcode: '3000',
            countryCode: 'AU',
            marketCode: 'VIC',
            salesRep: 'Jane',
            territory: 'METRO',
            phone: '0400000000',
            legacyCustomerId: null,
            actorUserId: UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_CUSTOMER_PUBLIC_ID, $details->publicId);
        self::assertTrue($connection->beganTransaction);
        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);

        $insertCall = $connection->calls[0];
        self::assertStringContainsString('INSERT INTO [customer].[Customers]', $insertCall['sql']);
        self::assertStringContainsString('OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId])', $insertCall['sql']);
        self::assertStringNotContainsString('Acme Pty Ltd', $insertCall['sql']);
        self::assertSame('Acme Pty Ltd', $insertCall['params'][':customerName']);
        self::assertSame(1, $insertCall['params'][':isActive']);
    }

    public function test_create_customer_rolls_back_when_write_fails(): void
    {
        $connection = new FakeSqlConnection();
        $connection->failNextWrite = new RuntimeException('insert exploded');

        $repository = new AzureSqlCustomerRepository($connection);

        $this->expectException(RuntimeException::class);

        try {
            $repository->create(
                customerCode: null,
                customerName: 'Acme Pty Ltd',
                status: CustomerStatus::active(),
                addressLine1: null,
                addressLine2: null,
                suburb: null,
                postcode: null,
                countryCode: null,
                marketCode: null,
                salesRep: null,
                territory: null,
                phone: null,
                legacyCustomerId: null,
                actorUserId: UserId::fromInt(1),
            );
        } finally {
            self::assertTrue($connection->beganTransaction);
            self::assertTrue($connection->rolledBack);
            self::assertFalse($connection->committed);
        }
    }

    public function test_change_status_sets_is_active_false_for_inactive_status(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['CustomerId' => 42]]);
        $connection->queueFetch([[
            ...$this->customerDetailsRow(),
            'StatusCode' => 'inactive',
            'IsActive' => 0,
        ]]);

        $repository = new AzureSqlCustomerRepository($connection);

        $details = $repository->changeStatus(
            \TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId::fromString(self::SAMPLE_CUSTOMER_PUBLIC_ID),
            CustomerStatus::inactive(),
            UserId::fromInt(1),
        );

        self::assertFalse($details->isActive);
        self::assertTrue($connection->committed);

        $updateCall = $connection->calls[0];
        self::assertStringContainsString('[UpdatedAtUtc] = SYSUTCDATETIME()', $updateCall['sql']);
        self::assertSame(0, $updateCall['params'][':isActive']);
        self::assertSame('inactive', $updateCall['params'][':statusCode']);
    }

    public function test_customer_code_exists_excludes_given_public_id(): void
    {
        $connection = new FakeSqlConnection([]);

        $repository = new AzureSqlCustomerRepository($connection);

        $exists = $repository->customerCodeExists(
            CustomerCode::fromString('CUST001'),
            \TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId::fromString(self::SAMPLE_CUSTOMER_PUBLIC_ID),
        );

        self::assertFalse($exists);
        $call = $connection->calls[0];
        self::assertStringContainsString('AND [PublicId] <> :exceptPublicId', $call['sql']);
        self::assertSame('CUST001', $call['params'][':customerCode']);
        self::assertSame(self::SAMPLE_CUSTOMER_PUBLIC_ID, $call['params'][':exceptPublicId']);
    }

    // ------------------------------------------------------------------
    // AzureSqlCustomerContactRepository
    // ------------------------------------------------------------------

    public function test_list_contacts_by_customer_filters_and_parameterizes_search(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->contactSummaryRow()]);

        $repository = new AzureSqlCustomerContactRepository($connection);

        $criteria = new ContactListCriteria(
            search: 'john',
            isActive: true,
            portalAccessStatus: 'active',
            sortBy: ContactListCriteria::SORT_DISPLAY_NAME,
            sortDirection: ContactListCriteria::SORT_ASC,
        );

        $result = $repository->listByCustomer(CustomerId::fromInt(1), $criteria);

        self::assertSame(1, $result->pagination->totalItems);
        self::assertCount(1, $result->items);

        $listSql = $connection->calls[1]['sql'];
        $listParams = $connection->calls[1]['params'];

        self::assertStringContainsString('OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY', $listSql);
        self::assertStringContainsString('ORDER BY cc.[DisplayName] ASC', $listSql);
        self::assertStringContainsString('cc.[CustomerId] = :customerId', $listSql);
        self::assertSame('%john%', $listParams[':searchName']);
        self::assertSame('%john%', $listParams[':searchEmail']);
        self::assertSame(1, $listParams[':isActive']);
        self::assertSame('active', $listParams[':portalAccessStatus']);
        self::assertSame(1, $listParams[':customerId']);
    }

    public function test_create_contact_persists_within_transaction_and_reloads_details(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['PublicId' => self::SAMPLE_CONTACT_PUBLIC_ID]]);
        $connection->queueFetch([$this->contactDetailsRow()]);

        $repository = new AzureSqlCustomerContactRepository($connection);

        $details = $repository->create(
            customerId: CustomerId::fromInt(1),
            displayName: 'John Doe',
            email: \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('john@example.com'),
            position: 'Manager',
            phone: null,
            mobile: null,
            isPrimary: true,
            isActive: true,
            portalAccessStatus: \TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus::notInvited(),
            legacyContactId: null,
            actorUserId: UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_CONTACT_PUBLIC_ID, $details->publicId);
        self::assertTrue($connection->committed);

        $insertCall = $connection->calls[0];
        self::assertStringContainsString('INSERT INTO [customer].[CustomerContacts]', $insertCall['sql']);
        self::assertSame('john@example.com', $insertCall['params'][':normalizedEmail']);
    }

    public function test_find_by_normalized_email_scopes_to_customer_when_provided(): void
    {
        $connection = new FakeSqlConnection([$this->contactDetailsRow()]);

        $repository = new AzureSqlCustomerContactRepository($connection);

        $matches = $repository->findByNormalizedEmail(
            \TriCab\Core\Identity\Domain\ValueObject\EmailAddress::fromString('john@example.com'),
            CustomerId::fromInt(1),
        );

        self::assertCount(1, $matches);
        $call = $connection->calls[0];
        self::assertStringContainsString('cc.[NormalizedEmailAddress] = :email', $call['sql']);
        self::assertStringContainsString('cc.[CustomerId] = :customerId', $call['sql']);
        self::assertSame('john@example.com', $call['params'][':email']);
        self::assertSame(1, $call['params'][':customerId']);
    }

    // ------------------------------------------------------------------
    // AzureSqlCustomerContactUserRepository
    // ------------------------------------------------------------------

    public function test_link_inserts_new_row_with_unique_named_parameters(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $connection->queueFetch([$this->linkRow()]);

        $repository = new AzureSqlCustomerContactUserRepository($connection);

        $link = $repository->link(
            CustomerContactId::fromInt(1),
            UserId::fromInt(2),
            true,
            UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_USER_PUBLIC_ID, $link->userPublicId);
        self::assertTrue($connection->committed);

        $insertCall = $connection->calls[1];
        self::assertStringContainsString('INSERT INTO [customer].[CustomerContactUsers]', $insertCall['sql']);
        self::assertSame(1, $insertCall['params'][':contactId']);
        self::assertSame(2, $insertCall['params'][':userId']);
        self::assertSame(1, $insertCall['params'][':isPrimary']);
        self::assertSame(1, $insertCall['params'][':createdByUserId']);
    }

    public function test_link_reactivates_existing_row_instead_of_inserting_duplicate(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['CustomerContactUserId' => 9]]);
        $connection->queueFetch([$this->linkRow()]);

        $repository = new AzureSqlCustomerContactUserRepository($connection);

        $repository->link(
            CustomerContactId::fromInt(1),
            UserId::fromInt(2),
            false,
            UserId::fromInt(1),
        );

        $updateCall = $connection->calls[1];
        self::assertStringContainsString('UPDATE [customer].[CustomerContactUsers]', $updateCall['sql']);
        self::assertStringContainsString('[UnlinkedAtUtc] = NULL', $updateCall['sql']);
    }

    public function test_unlink_is_parameterized_and_scoped_to_active_rows(): void
    {
        $connection = new FakeSqlConnection();

        $repository = new AzureSqlCustomerContactUserRepository($connection);
        $repository->unlink(CustomerContactId::fromInt(1), UserId::fromInt(2));

        $call = $connection->calls[0];
        self::assertStringContainsString('UPDATE [customer].[CustomerContactUsers]', $call['sql']);
        self::assertStringContainsString('[IsActive] = 1', $call['sql']);
        self::assertSame(1, $call['params'][':contactId']);
        self::assertSame(2, $call['params'][':userId']);
    }

    public function test_has_active_link_and_find_active_primary_user_use_bound_parameters(): void
    {
        $connection = new FakeSqlConnection([['Found' => 1]]);
        $repository = new AzureSqlCustomerContactUserRepository($connection);

        self::assertTrue($repository->hasActiveLink(CustomerContactId::fromInt(1), UserId::fromInt(2)));

        $call = $connection->calls[0];
        self::assertStringNotContainsString("'1'", $call['sql']);
        self::assertSame(1, $call['params'][':contactId']);
        self::assertSame(2, $call['params'][':userId']);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function customerSummaryRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID,
            'CustomerCode' => 'CUST001',
            'CustomerName' => 'Acme Pty Ltd',
            'StatusCode' => 'active',
            'IsActive' => 1,
            'Suburb' => 'Melbourne',
            'Postcode' => '3000',
            'CountryCode' => 'AU',
            'MarketCode' => 'VIC',
            'TerritoryCode' => 'MEL',
            'PhoneNumber' => '+61 3 9000 0000',
            'ContactCount' => 2,
            'PortalUserCount' => 1,
            'CreatedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UpdatedAtUtc' => '2026-01-02T00:00:00.0000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerSummaryCountsRow(): array
    {
        return [
            'TotalCustomers' => 5,
            'ActiveCustomers' => 3,
            'InactiveCustomers' => 1,
            'PortalEnabledCustomers' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerDetailsRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID,
            'CustomerCode' => 'CUST001',
            'CustomerName' => 'Acme Pty Ltd',
            'StatusCode' => 'active',
            'IsActive' => 1,
            'AddressLine1' => '1 Main St',
            'AddressLine2' => null,
            'Suburb' => 'Melbourne',
            'Postcode' => '3000',
            'CountryCode' => 'AU',
            'MarketCode' => 'VIC',
            'SalesRepresentative' => 'Jane',
            'TerritoryCode' => 'METRO',
            'PhoneNumber' => '0400000000',
            'LegacyCustomerId' => null,
            'CreatedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UpdatedAtUtc' => '2026-01-01T00:00:00.0000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactSummaryRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_CONTACT_PUBLIC_ID,
            'CustomerPublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID,
            'DisplayName' => 'John Doe',
            'EmailAddress' => 'john@example.com',
            'PositionTitle' => 'Manager',
            'IsPrimaryContact' => 1,
            'IsActive' => 1,
            'PortalAccessStatusCode' => 'active',
            'CreatedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UpdatedAtUtc' => '2026-01-02T00:00:00.0000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactDetailsRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_CONTACT_PUBLIC_ID,
            'CustomerPublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID,
            'DisplayName' => 'John Doe',
            'EmailAddress' => 'john@example.com',
            'NormalizedEmailAddress' => 'john@example.com',
            'PositionTitle' => 'Manager',
            'PhoneNumber' => null,
            'MobileNumber' => null,
            'IsPrimaryContact' => 1,
            'IsActive' => 1,
            'PortalAccessStatusCode' => 'not-invited',
            'LegacyContactId' => null,
            'CreatedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UpdatedAtUtc' => '2026-01-01T00:00:00.0000000',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkRow(): array
    {
        return [
            'ContactPublicId' => self::SAMPLE_CONTACT_PUBLIC_ID,
            'CustomerPublicId' => self::SAMPLE_CUSTOMER_PUBLIC_ID,
            'UserPublicId' => self::SAMPLE_USER_PUBLIC_ID,
            'IsPrimary' => 1,
            'IsActive' => 1,
            'LinkedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UnlinkedAtUtc' => null,
        ];
    }
}
