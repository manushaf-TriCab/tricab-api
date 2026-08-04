<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Customer\Doubles;

use DateTimeZone;
use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\DTO\CustomerContactSummary;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Application\DTO\CustomerSummary;
use TriCab\Core\Customer\Application\DTO\CustomerSummaryCounts;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerPortalUserProvisioner;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Contract\ProvisionedPortalUser;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Customer\Domain\ValueObject\LegacyContactId;
use TriCab\Core\Customer\Domain\ValueObject\LegacyCustomerId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserManagementStore;

/**
 * In-memory Customer bounded-context persistence for unit and application
 * tests. Mirrors tests\Identity\Doubles\InMemoryUserManagementStore.
 *
 * @phpstan-type StoredCustomer array{
 *     id: int,
 *     publicId: string,
 *     customerCode: string|null,
 *     customerName: string,
 *     status: string,
 *     addressLine1: string|null,
 *     addressLine2: string|null,
 *     suburb: string|null,
 *     postcode: string|null,
 *     countryCode: string|null,
 *     marketCode: string|null,
 *     salesRep: string|null,
 *     territory: string|null,
 *     phone: string|null,
 *     legacyCustomerId: int|null,
 *     createdAtUtc: string,
 *     updatedAtUtc: string,
 * }
 * @phpstan-type StoredContact array{
 *     id: int,
 *     publicId: string,
 *     customerId: int,
 *     displayName: string,
 *     email: string|null,
 *     position: string|null,
 *     phone: string|null,
 *     mobile: string|null,
 *     isPrimary: bool,
 *     isActive: bool,
 *     portalAccessStatus: string,
 *     legacyContactId: int|null,
 *     createdAtUtc: string,
 *     updatedAtUtc: string,
 * }
 * @phpstan-type StoredLink array{
 *     contactId: int,
 *     userId: int,
 *     isPrimary: bool,
 *     isActive: bool,
 *     linkedAtUtc: string,
 *     unlinkedAtUtc: string|null,
 * }
 */
final class InMemoryCustomerStore
{
    private readonly InMemoryCustomerRepository $customerRepository;
    private readonly InMemoryCustomerContactRepository $contactRepository;
    private readonly InMemoryCustomerContactUserRepository $linkRepository;
    private readonly UuidV4Generator $uuids;

    /** @var array<int, StoredCustomer> */
    private array $customers = [];

    /** @var array<string, int> */
    private array $customerIdsByCode = [];

    /** @var array<int, StoredContact> */
    private array $contacts = [];

    /** @var list<StoredLink> */
    private array $links = [];

    private int $nextCustomerId = 1;
    private int $nextContactId = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->uuids = new UuidV4Generator();
        $this->customerRepository = new InMemoryCustomerRepository($this);
        $this->contactRepository = new InMemoryCustomerContactRepository($this);
        $this->linkRepository = new InMemoryCustomerContactUserRepository($this);
    }

    public function customers(): CustomerRepository
    {
        return $this->customerRepository;
    }

    public function contacts(): CustomerContactRepository
    {
        return $this->contactRepository;
    }

    public function links(): CustomerContactUserRepository
    {
        return $this->linkRepository;
    }

    /**
     * Convenience seed helper for tests.
     */
    public function seedCustomer(
        string $customerName,
        ?string $customerCode = null,
        string $status = CustomerStatus::ACTIVE,
        int $actorUserId = 1,
    ): CustomerDetails {
        return $this->create(
            customerCode: $customerCode !== null ? CustomerCode::fromString($customerCode) : null,
            customerName: $customerName,
            status: CustomerStatus::fromString($status),
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
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    public function seedContact(
        CustomerId $customerId,
        string $displayName,
        ?string $email = null,
        bool $isPrimary = false,
        bool $isActive = true,
        int $actorUserId = 1,
    ): CustomerContactDetails {
        return $this->createContact(
            customerId: $customerId,
            displayName: $displayName,
            email: $email !== null ? EmailAddress::fromString($email) : null,
            position: null,
            phone: null,
            mobile: null,
            isPrimary: $isPrimary,
            isActive: $isActive,
            portalAccessStatus: PortalAccessStatus::notInvited(),
            legacyContactId: null,
            actorUserId: UserId::fromInt($actorUserId),
        );
    }

    // ---- CustomerRepository backing implementation ----

    public function list(CustomerListCriteria $criteria): CustomerListResult
    {
        $items = [];
        foreach ($this->customers as $customer) {
            $summary = $this->buildCustomerSummary($customer['id']);
            if (!$this->matchesCustomerListCriteria($summary, $criteria)) {
                continue;
            }

            $items[] = $summary;
        }

        usort(
            $items,
            fn (CustomerSummary $a, CustomerSummary $b): int => $this->compareCustomerSummaries($a, $b, $criteria),
        );

        $total = count($items);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($items, $offset, $criteria->pageSize);

        return new CustomerListResult(
            items: $pageItems,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
            counts: $this->summarizeCounts(),
        );
    }

    public function findCustomerDetailsByPublicId(CustomerPublicId $publicId): ?CustomerDetails
    {
        $id = $this->customerIdByPublicId($publicId);

        return $id !== null ? $this->buildCustomerDetails($id) : null;
    }

    public function findCustomerIdByPublicId(CustomerPublicId $publicId): ?CustomerId
    {
        $id = $this->customerIdByPublicId($publicId);

        return $id !== null ? CustomerId::fromInt($id) : null;
    }

    public function customerCodeExists(CustomerCode $customerCode, ?CustomerPublicId $exceptPublicId = null): bool
    {
        $id = $this->customerIdsByCode[$customerCode->toString()] ?? null;
        if ($id === null) {
            return false;
        }

        if ($exceptPublicId === null) {
            return true;
        }

        return $this->customerIdByPublicId($exceptPublicId) !== $id;
    }

    public function create(
        ?CustomerCode $customerCode,
        string $customerName,
        CustomerStatus $status,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        ?LegacyCustomerId $legacyCustomerId,
        UserId $actorUserId,
    ): CustomerDetails {
        $now = $this->timestampUtc();
        $id = $this->nextCustomerId++;

        $this->customers[$id] = [
            'id' => $id,
            'publicId' => $this->uuids->generate()->toString(),
            'customerCode' => $customerCode?->toString(),
            'customerName' => $customerName,
            'status' => $status->toString(),
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2,
            'suburb' => $suburb,
            'postcode' => $postcode,
            'countryCode' => $countryCode,
            'marketCode' => $marketCode,
            'salesRep' => $salesRep,
            'territory' => $territory,
            'phone' => $phone,
            'legacyCustomerId' => $legacyCustomerId?->toInt(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];

        if ($customerCode !== null) {
            $this->customerIdsByCode[$customerCode->toString()] = $id;
        }

        return $this->buildCustomerDetails($id);
    }

    public function update(
        CustomerPublicId $publicId,
        ?CustomerCode $customerCode,
        string $customerName,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        UserId $actorUserId,
    ): CustomerDetails {
        $id = $this->requireCustomerIdByPublicId($publicId);
        $existing = $this->customers[$id];

        if ($existing['customerCode'] !== null) {
            unset($this->customerIdsByCode[$existing['customerCode']]);
        }

        $this->customers[$id] = [
            ...$existing,
            'customerCode' => $customerCode?->toString(),
            'customerName' => $customerName,
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2,
            'suburb' => $suburb,
            'postcode' => $postcode,
            'countryCode' => $countryCode,
            'marketCode' => $marketCode,
            'salesRep' => $salesRep,
            'territory' => $territory,
            'phone' => $phone,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        if ($customerCode !== null) {
            $this->customerIdsByCode[$customerCode->toString()] = $id;
        }

        return $this->buildCustomerDetails($id);
    }

    public function changeCustomerStatus(
        CustomerPublicId $publicId,
        CustomerStatus $status,
        UserId $actorUserId,
    ): CustomerDetails {
        $id = $this->requireCustomerIdByPublicId($publicId);
        $existing = $this->customers[$id];
        $this->customers[$id] = [
            ...$existing,
            'status' => $status->toString(),
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildCustomerDetails($id);
    }

    public function summarizeCounts(): CustomerSummaryCounts
    {
        $total = count($this->customers);
        $active = 0;
        $inactive = 0;
        $portalEnabled = 0;

        foreach ($this->customers as $customer) {
            $status = CustomerStatus::fromString($customer['status']);
            if ($status->isActive()) {
                ++$active;
            } elseif ($status->isInactive()) {
                ++$inactive;
            }
        }

        foreach ($this->contacts as $contact) {
            if (PortalAccessStatus::fromString($contact['portalAccessStatus'])->isActive()) {
                ++$portalEnabled;
            }
        }

        return new CustomerSummaryCounts(
            totalCustomers: $total,
            activeCustomers: $active,
            inactiveCustomers: $inactive,
            portalEnabledCustomers: $portalEnabled,
        );
    }

    // ---- CustomerContactRepository backing implementation ----

    public function listByCustomer(CustomerId $customerId, ContactListCriteria $criteria): ContactListResult
    {
        $items = [];
        foreach ($this->contacts as $contact) {
            if ($contact['customerId'] !== $customerId->toInt()) {
                continue;
            }

            $summary = $this->buildContactSummary($contact['id']);
            if (!$this->matchesContactListCriteria($summary, $criteria)) {
                continue;
            }

            $items[] = $summary;
        }

        usort(
            $items,
            fn (CustomerContactSummary $a, CustomerContactSummary $b): int => $this->compareContactSummaries($a, $b, $criteria),
        );

        $total = count($items);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($items, $offset, $criteria->pageSize);

        return new ContactListResult(
            items: $pageItems,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findContactDetailsByPublicId(CustomerContactPublicId $publicId): ?CustomerContactDetails
    {
        $id = $this->contactIdByPublicId($publicId);

        return $id !== null ? $this->buildContactDetails($id) : null;
    }

    public function findContactIdByPublicId(CustomerContactPublicId $publicId): ?CustomerContactId
    {
        $id = $this->contactIdByPublicId($publicId);

        return $id !== null ? CustomerContactId::fromInt($id) : null;
    }

    public function createContact(
        CustomerId $customerId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        bool $isPrimary,
        bool $isActive,
        PortalAccessStatus $portalAccessStatus,
        ?LegacyContactId $legacyContactId,
        UserId $actorUserId,
    ): CustomerContactDetails {
        $now = $this->timestampUtc();
        $id = $this->nextContactId++;

        $this->contacts[$id] = [
            'id' => $id,
            'publicId' => $this->uuids->generate()->toString(),
            'customerId' => $customerId->toInt(),
            'displayName' => $displayName,
            'email' => $email?->toString(),
            'position' => $position,
            'phone' => $phone,
            'mobile' => $mobile,
            'isPrimary' => $isPrimary,
            'isActive' => $isActive,
            'portalAccessStatus' => $portalAccessStatus->toString(),
            'legacyContactId' => $legacyContactId?->toInt(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];

        return $this->buildContactDetails($id);
    }

    public function updateContact(
        CustomerContactPublicId $publicId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        UserId $actorUserId,
    ): CustomerContactDetails {
        $id = $this->requireContactIdByPublicId($publicId);
        $existing = $this->contacts[$id];

        $this->contacts[$id] = [
            ...$existing,
            'displayName' => $displayName,
            'email' => $email?->toString(),
            'position' => $position,
            'phone' => $phone,
            'mobile' => $mobile,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildContactDetails($id);
    }

    public function changeContactStatus(
        CustomerContactPublicId $publicId,
        bool $isActive,
        UserId $actorUserId,
    ): CustomerContactDetails {
        $id = $this->requireContactIdByPublicId($publicId);
        $existing = $this->contacts[$id];
        $this->contacts[$id] = [
            ...$existing,
            'isActive' => $isActive,
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildContactDetails($id);
    }

    public function changePortalAccessStatus(
        CustomerContactPublicId $publicId,
        PortalAccessStatus $status,
        UserId $actorUserId,
    ): CustomerContactDetails {
        $id = $this->requireContactIdByPublicId($publicId);
        $existing = $this->contacts[$id];
        $this->contacts[$id] = [
            ...$existing,
            'portalAccessStatus' => $status->toString(),
            'updatedAtUtc' => $this->timestampUtc(),
        ];

        return $this->buildContactDetails($id);
    }

    /**
     * @return list<CustomerContactDetails>
     */
    public function findByNormalizedEmail(EmailAddress $email, ?CustomerId $customerScope = null): array
    {
        $matches = [];
        foreach ($this->contacts as $contact) {
            if ($contact['email'] !== $email->toString()) {
                continue;
            }

            if ($customerScope !== null && $contact['customerId'] !== $customerScope->toInt()) {
                continue;
            }

            $matches[] = $this->buildContactDetails($contact['id']);
        }

        return $matches;
    }

    // ---- CustomerContactUserRepository backing implementation ----

    public function link(
        CustomerContactId $contactId,
        UserId $userId,
        bool $isPrimary,
        ?UserId $createdByUserId,
    ): CustomerContactUserLinkDetails {
        $now = $this->timestampUtc();

        foreach ($this->links as $index => $existing) {
            if ($existing['contactId'] === $contactId->toInt() && $existing['userId'] === $userId->toInt()) {
                $this->links[$index] = [
                    ...$existing,
                    'isPrimary' => $isPrimary,
                    'isActive' => true,
                    'linkedAtUtc' => $now,
                    'unlinkedAtUtc' => null,
                ];

                return $this->buildLinkDetails($this->links[$index]);
            }
        }

        $record = [
            'contactId' => $contactId->toInt(),
            'userId' => $userId->toInt(),
            'isPrimary' => $isPrimary,
            'isActive' => true,
            'linkedAtUtc' => $now,
            'unlinkedAtUtc' => null,
        ];
        $this->links[] = $record;

        return $this->buildLinkDetails($record);
    }

    public function unlink(CustomerContactId $contactId, UserId $userId): void
    {
        foreach ($this->links as $index => $existing) {
            if (
                $existing['contactId'] === $contactId->toInt()
                && $existing['userId'] === $userId->toInt()
                && $existing['isActive']
            ) {
                $this->links[$index]['isActive'] = false;
                $this->links[$index]['unlinkedAtUtc'] = $this->timestampUtc();
            }
        }
    }

    public function hasActiveLink(CustomerContactId $contactId, UserId $userId): bool
    {
        foreach ($this->links as $existing) {
            if (
                $existing['contactId'] === $contactId->toInt()
                && $existing['userId'] === $userId->toInt()
                && $existing['isActive']
            ) {
                return true;
            }
        }

        return false;
    }

    public function findActivePrimaryUser(CustomerContactId $contactId): ?UserId
    {
        foreach ($this->links as $existing) {
            if ($existing['contactId'] === $contactId->toInt() && $existing['isActive'] && $existing['isPrimary']) {
                return UserId::fromInt($existing['userId']);
            }
        }

        return null;
    }

    /**
     * @return list<CustomerContactUserLinkDetails>
     */
    public function listActiveLinksForUser(UserId $userId): array
    {
        $items = [];
        foreach ($this->links as $existing) {
            if ($existing['userId'] === $userId->toInt() && $existing['isActive']) {
                $items[] = $this->buildLinkDetails($existing);
            }
        }

        return $items;
    }

    /**
     * @return list<CustomerContactUserLinkDetails>
     */
    public function listPortalUsersForCustomer(CustomerId $customerId): array
    {
        $items = [];
        foreach ($this->links as $existing) {
            if (!$existing['isActive']) {
                continue;
            }

            $contact = $this->contacts[$existing['contactId']] ?? null;
            if ($contact === null || $contact['customerId'] !== $customerId->toInt()) {
                continue;
            }

            $items[] = $this->buildLinkDetails($existing);
        }

        return $items;
    }

    // ---- shared helpers ----

    private function buildCustomerSummary(int $id): CustomerSummary
    {
        $customer = $this->customers[$id];

        $contactCount = 0;
        $portalUserCount = 0;
        foreach ($this->contacts as $contact) {
            if ($contact['customerId'] !== $id) {
                continue;
            }
            ++$contactCount;
            foreach ($this->links as $link) {
                if ($link['contactId'] === $contact['id'] && $link['isActive']) {
                    ++$portalUserCount;
                }
            }
        }

        return new CustomerSummary(
            publicId: $customer['publicId'],
            customerCode: $customer['customerCode'],
            customerName: $customer['customerName'],
            status: $customer['status'],
            isActive: CustomerStatus::fromString($customer['status'])->isActive(),
            suburb: $customer['suburb'],
            postcode: $customer['postcode'],
            countryCode: $customer['countryCode'],
            marketCode: $customer['marketCode'],
            territoryCode: $customer['territory'],
            phone: $customer['phone'],
            contactCount: $contactCount,
            portalUserCount: $portalUserCount,
            createdAtUtc: $customer['createdAtUtc'],
            updatedAtUtc: $customer['updatedAtUtc'],
        );
    }

    private function buildCustomerDetails(int $id): CustomerDetails
    {
        $customer = $this->customers[$id];

        return new CustomerDetails(
            publicId: $customer['publicId'],
            customerCode: $customer['customerCode'],
            customerName: $customer['customerName'],
            status: $customer['status'],
            isActive: CustomerStatus::fromString($customer['status'])->isActive(),
            addressLine1: $customer['addressLine1'],
            addressLine2: $customer['addressLine2'],
            suburb: $customer['suburb'],
            postcode: $customer['postcode'],
            countryCode: $customer['countryCode'],
            marketCode: $customer['marketCode'],
            salesRep: $customer['salesRep'],
            territory: $customer['territory'],
            phone: $customer['phone'],
            legacyCustomerId: $customer['legacyCustomerId'],
            createdAtUtc: $customer['createdAtUtc'],
            updatedAtUtc: $customer['updatedAtUtc'],
        );
    }

    private function buildContactSummary(int $id): CustomerContactSummary
    {
        $contact = $this->contacts[$id];
        $customer = $this->customers[$contact['customerId']];

        return new CustomerContactSummary(
            publicId: $contact['publicId'],
            customerPublicId: $customer['publicId'],
            displayName: $contact['displayName'],
            email: $contact['email'],
            position: $contact['position'],
            isPrimary: $contact['isPrimary'],
            isActive: $contact['isActive'],
            portalAccessStatus: $contact['portalAccessStatus'],
            createdAtUtc: $contact['createdAtUtc'],
            updatedAtUtc: $contact['updatedAtUtc'],
        );
    }

    private function buildContactDetails(int $id): CustomerContactDetails
    {
        $contact = $this->contacts[$id];
        $customer = $this->customers[$contact['customerId']];

        return new CustomerContactDetails(
            publicId: $contact['publicId'],
            customerPublicId: $customer['publicId'],
            displayName: $contact['displayName'],
            email: $contact['email'],
            normalizedEmail: $contact['email'],
            position: $contact['position'],
            phone: $contact['phone'],
            mobile: $contact['mobile'],
            isPrimary: $contact['isPrimary'],
            isActive: $contact['isActive'],
            portalAccessStatus: $contact['portalAccessStatus'],
            legacyContactId: $contact['legacyContactId'],
            createdAtUtc: $contact['createdAtUtc'],
            updatedAtUtc: $contact['updatedAtUtc'],
        );
    }

    /**
     * @param StoredLink $link
     */
    private function buildLinkDetails(array $link): CustomerContactUserLinkDetails
    {
        $contact = $this->contacts[$link['contactId']];
        $customer = $this->customers[$contact['customerId']];

        return new CustomerContactUserLinkDetails(
            contactPublicId: $contact['publicId'],
            customerPublicId: $customer['publicId'],
            userPublicId: (string) $link['userId'],
            isPrimary: $link['isPrimary'],
            isActive: $link['isActive'],
            linkedAtUtc: $link['linkedAtUtc'],
            unlinkedAtUtc: $link['unlinkedAtUtc'],
        );
    }

    private function matchesCustomerListCriteria(CustomerSummary $summary, CustomerListCriteria $criteria): bool
    {
        $search = $criteria->normalizedSearch();
        if ($search !== null && !$this->matchesSearch($search, [$summary->customerName, $summary->customerCode])) {
            return false;
        }

        if ($criteria->status !== null && strcasecmp($summary->status, $criteria->status) !== 0) {
            return false;
        }

        return true;
    }

    private function matchesContactListCriteria(CustomerContactSummary $summary, ContactListCriteria $criteria): bool
    {
        $search = $criteria->normalizedSearch();
        if ($search !== null && !$this->matchesSearch($search, [$summary->displayName, $summary->email])) {
            return false;
        }

        if ($criteria->isActive !== null && $summary->isActive !== $criteria->isActive) {
            return false;
        }

        if (
            $criteria->portalAccessStatus !== null
            && strcasecmp($summary->portalAccessStatus, $criteria->portalAccessStatus) !== 0
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param list<string|null> $fields
     */
    private function matchesSearch(string $search, array $fields): bool
    {
        $needle = strtolower($search);

        foreach ($fields as $field) {
            if ($field !== null && str_contains(strtolower($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function compareCustomerSummaries(CustomerSummary $a, CustomerSummary $b, CustomerListCriteria $criteria): int
    {
        $direction = $criteria->normalizedSortDirection() === CustomerListCriteria::SORT_ASC ? 1 : -1;

        $value = match ($criteria->sortBy) {
            CustomerListCriteria::SORT_NAME => strcmp($a->customerName, $b->customerName),
            CustomerListCriteria::SORT_CODE => strcmp($a->customerCode ?? '', $b->customerCode ?? ''),
            CustomerListCriteria::SORT_STATUS => strcmp($a->status, $b->status),
            CustomerListCriteria::SORT_UPDATED_AT => strcmp($a->updatedAtUtc, $b->updatedAtUtc),
            default => strcmp($a->createdAtUtc, $b->createdAtUtc),
        };

        if ($value === 0) {
            return strcmp($a->publicId, $b->publicId) * $direction;
        }

        return $value * $direction;
    }

    private function compareContactSummaries(CustomerContactSummary $a, CustomerContactSummary $b, ContactListCriteria $criteria): int
    {
        $direction = $criteria->normalizedSortDirection() === ContactListCriteria::SORT_ASC ? 1 : -1;

        $value = match ($criteria->sortBy) {
            ContactListCriteria::SORT_DISPLAY_NAME => strcmp($a->displayName, $b->displayName),
            ContactListCriteria::SORT_UPDATED_AT => strcmp($a->updatedAtUtc, $b->updatedAtUtc),
            default => strcmp($a->createdAtUtc, $b->createdAtUtc),
        };

        if ($value === 0) {
            return strcmp($a->publicId, $b->publicId) * $direction;
        }

        return $value * $direction;
    }

    private function customerIdByPublicId(CustomerPublicId $publicId): ?int
    {
        foreach ($this->customers as $customer) {
            if ($customer['publicId'] === $publicId->toString()) {
                return $customer['id'];
            }
        }

        return null;
    }

    private function requireCustomerIdByPublicId(CustomerPublicId $publicId): int
    {
        $id = $this->customerIdByPublicId($publicId);
        if ($id === null) {
            throw new \RuntimeException(sprintf('Customer "%s" not found.', $publicId->toString()));
        }

        return $id;
    }

    private function contactIdByPublicId(CustomerContactPublicId $publicId): ?int
    {
        foreach ($this->contacts as $contact) {
            if ($contact['publicId'] === $publicId->toString()) {
                return $contact['id'];
            }
        }

        return null;
    }

    private function requireContactIdByPublicId(CustomerContactPublicId $publicId): int
    {
        $id = $this->contactIdByPublicId($publicId);
        if ($id === null) {
            throw new \RuntimeException(sprintf('Contact "%s" not found.', $publicId->toString()));
        }

        return $id;
    }

    private function timestampUtc(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}

final class InMemoryCustomerRepository implements CustomerRepository
{
    public function __construct(
        private readonly InMemoryCustomerStore $store,
    ) {
    }

    public function list(CustomerListCriteria $criteria): CustomerListResult
    {
        return $this->store->list($criteria);
    }

    public function findDetailsByPublicId(CustomerPublicId $publicId): ?CustomerDetails
    {
        return $this->store->findCustomerDetailsByPublicId($publicId);
    }

    public function findIdByPublicId(CustomerPublicId $publicId): ?CustomerId
    {
        return $this->store->findCustomerIdByPublicId($publicId);
    }

    public function customerCodeExists(CustomerCode $customerCode, ?CustomerPublicId $exceptPublicId = null): bool
    {
        return $this->store->customerCodeExists($customerCode, $exceptPublicId);
    }

    public function create(
        ?CustomerCode $customerCode,
        string $customerName,
        CustomerStatus $status,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        ?LegacyCustomerId $legacyCustomerId,
        UserId $actorUserId,
    ): CustomerDetails {
        return $this->store->create(
            $customerCode,
            $customerName,
            $status,
            $addressLine1,
            $addressLine2,
            $suburb,
            $postcode,
            $countryCode,
            $marketCode,
            $salesRep,
            $territory,
            $phone,
            $legacyCustomerId,
            $actorUserId,
        );
    }

    public function update(
        CustomerPublicId $publicId,
        ?CustomerCode $customerCode,
        string $customerName,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        UserId $actorUserId,
    ): CustomerDetails {
        return $this->store->update(
            $publicId,
            $customerCode,
            $customerName,
            $addressLine1,
            $addressLine2,
            $suburb,
            $postcode,
            $countryCode,
            $marketCode,
            $salesRep,
            $territory,
            $phone,
            $actorUserId,
        );
    }

    public function changeStatus(
        CustomerPublicId $publicId,
        CustomerStatus $status,
        UserId $actorUserId,
    ): CustomerDetails {
        return $this->store->changeCustomerStatus($publicId, $status, $actorUserId);
    }

    public function summarizeCounts(): CustomerSummaryCounts
    {
        return $this->store->summarizeCounts();
    }
}

final class InMemoryCustomerContactRepository implements CustomerContactRepository
{
    public function __construct(
        private readonly InMemoryCustomerStore $store,
    ) {
    }

    public function listByCustomer(CustomerId $customerId, ContactListCriteria $criteria): ContactListResult
    {
        return $this->store->listByCustomer($customerId, $criteria);
    }

    public function findDetailsByPublicId(CustomerContactPublicId $publicId): ?CustomerContactDetails
    {
        return $this->store->findContactDetailsByPublicId($publicId);
    }

    public function findIdByPublicId(CustomerContactPublicId $publicId): ?CustomerContactId
    {
        return $this->store->findContactIdByPublicId($publicId);
    }

    public function create(
        CustomerId $customerId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        bool $isPrimary,
        bool $isActive,
        PortalAccessStatus $portalAccessStatus,
        ?LegacyContactId $legacyContactId,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->store->createContact(
            $customerId,
            $displayName,
            $email,
            $position,
            $phone,
            $mobile,
            $isPrimary,
            $isActive,
            $portalAccessStatus,
            $legacyContactId,
            $actorUserId,
        );
    }

    public function update(
        CustomerContactPublicId $publicId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->store->updateContact($publicId, $displayName, $email, $position, $phone, $mobile, $actorUserId);
    }

    public function changeStatus(
        CustomerContactPublicId $publicId,
        bool $isActive,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->store->changeContactStatus($publicId, $isActive, $actorUserId);
    }

    public function changePortalAccessStatus(
        CustomerContactPublicId $publicId,
        PortalAccessStatus $status,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->store->changePortalAccessStatus($publicId, $status, $actorUserId);
    }

    public function findByNormalizedEmail(EmailAddress $email, ?CustomerId $customerScope = null): array
    {
        return $this->store->findByNormalizedEmail($email, $customerScope);
    }
}

final class InMemoryCustomerContactUserRepository implements CustomerContactUserRepository
{
    public function __construct(
        private readonly InMemoryCustomerStore $store,
    ) {
    }

    public function link(
        CustomerContactId $contactId,
        UserId $userId,
        bool $isPrimary,
        ?UserId $createdByUserId,
    ): CustomerContactUserLinkDetails {
        return $this->store->link($contactId, $userId, $isPrimary, $createdByUserId);
    }

    public function unlink(CustomerContactId $contactId, UserId $userId): void
    {
        $this->store->unlink($contactId, $userId);
    }

    public function hasActiveLink(CustomerContactId $contactId, UserId $userId): bool
    {
        return $this->store->hasActiveLink($contactId, $userId);
    }

    public function findActivePrimaryUser(CustomerContactId $contactId): ?UserId
    {
        return $this->store->findActivePrimaryUser($contactId);
    }

    public function listActiveLinksForUser(UserId $userId): array
    {
        return $this->store->listActiveLinksForUser($userId);
    }

    public function listPortalUsersForCustomer(CustomerId $customerId): array
    {
        return $this->store->listPortalUsersForCustomer($customerId);
    }
}

/**
 * Test double for the Identity-side provisioning port. Delegates to a real
 * (in-memory) Identity user-management store so tests exercise realistic
 * user creation without a direct Customer -> Identity application dependency.
 */
final class FakeCustomerPortalUserProvisioner implements CustomerPortalUserProvisioner
{
    /** @var list<array{email: string, displayName: string, roleCode: string}> */
    private array $provisionedUsers = [];

    public function __construct(
        private readonly InMemoryUserManagementStore $identityStore,
    ) {
    }

    public function createActivePortalUser(
        EmailAddress $email,
        string $displayName,
        RoleCode $customerRole,
        UserId $actorUserId,
    ): ProvisionedPortalUser {
        $created = $this->identityStore->users()->createUser(
            emailAddress: $email,
            displayName: $displayName,
            status: UserStatus::active(),
            roleCodes: [$customerRole],
            actorUserId: $actorUserId,
        );

        $userId = $this->identityStore->users()->findUserIdByPublicId(
            UserPublicId::fromString($created->publicId),
        );
        assert($userId !== null);

        $this->provisionedUsers[] = [
            'email' => $email->toString(),
            'displayName' => $displayName,
            'roleCode' => $customerRole->toString(),
        ];

        return new ProvisionedPortalUser(
            userId: $userId,
            userPublicId: UserPublicId::fromString($created->publicId),
            email: $email,
            displayName: $displayName,
        );
    }

    /**
     * @return list<array{email: string, displayName: string, roleCode: string}>
     */
    public function provisionedUsers(): array
    {
        return $this->provisionedUsers;
    }
}
