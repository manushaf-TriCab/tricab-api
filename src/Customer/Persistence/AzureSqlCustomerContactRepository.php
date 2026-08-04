<?php

declare(strict_types=1);

namespace TriCab\Api\Customer\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\DTO\CustomerContactSummary;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\LegacyContactId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Azure SQL implementation of the Core CustomerContactRepository contract.
 * Reads and writes prepared statements only; search text and sort selection
 * never reach the SQL text directly — only whitelisted fragments and bound
 * parameters do.
 */
final class AzureSqlCustomerContactRepository implements CustomerContactRepository
{
    use AzureSqlTransaction;

    private const DETAILS_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), cc.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
    cc.[DisplayName],
    cc.[EmailAddress],
    cc.[NormalizedEmailAddress],
    cc.[PositionTitle],
    cc.[PhoneNumber],
    cc.[MobileNumber],
    cc.[IsPrimaryContact],
    cc.[IsActive],
    cc.[PortalAccessStatusCode],
    cc.[LegacyContactId],
    CONVERT(VARCHAR(33), cc.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), cc.[UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [customer].[CustomerContacts] cc
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
SQL;

    private const SUMMARY_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), cc.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
    cc.[DisplayName],
    cc.[EmailAddress],
    cc.[PositionTitle],
    cc.[IsPrimaryContact],
    cc.[IsActive],
    cc.[PortalAccessStatusCode],
    CONVERT(VARCHAR(33), cc.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), cc.[UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [customer].[CustomerContacts] cc
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByCustomer(CustomerId $customerId, ContactListCriteria $criteria): ContactListResult
    {
        [$where, $filterParams] = $this->buildFilters($criteria);
        $filterParams[':customerId'] = $customerId->toInt();

        $countRows = $this->connection->fetchAll(
            "SELECT COUNT(*) AS [Total] FROM [customer].[CustomerContacts] cc WHERE cc.[CustomerId] = :customerId{$where}",
            $filterParams,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $sortColumn = $this->sortColumn($criteria->sortBy);
        $sortDirection = $criteria->normalizedSortDirection() === ContactListCriteria::SORT_ASC ? 'ASC' : 'DESC';
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            self::SUMMARY_SELECT_SQL . <<<SQL

WHERE cc.[CustomerId] = :customerId{$where}
ORDER BY {$sortColumn} {$sortDirection}
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [...$filterParams, ':offset' => $offset, ':pageSize' => $criteria->pageSize],
        );

        $items = array_map(fn (array $row): CustomerContactSummary => $this->hydrateSummary($row), $rows);

        return new ContactListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findDetailsByPublicId(CustomerContactPublicId $publicId): ?CustomerContactDetails
    {
        $rows = $this->connection->fetchAll(
            self::DETAILS_SELECT_SQL . ' WHERE cc.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrateDetails($rows[0]);
    }

    public function findIdByPublicId(CustomerContactPublicId $publicId): ?CustomerContactId
    {
        $id = $this->contactIdByPublicId($publicId);

        return $id !== null ? CustomerContactId::fromInt($id) : null;
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
        return $this->runInTransaction(
            $this->connection,
            function () use (
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
            ): CustomerContactDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [customer].[CustomerContacts] (
    [CustomerId],
    [LegacyContactId],
    [DisplayName],
    [EmailAddress],
    [NormalizedEmailAddress],
    [PositionTitle],
    [PhoneNumber],
    [MobileNumber],
    [IsPrimaryContact],
    [IsActive],
    [PortalAccessStatusCode],
    [CreatedAtUtc],
    [UpdatedAtUtc]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :customerId,
    :legacyContactId,
    :displayName,
    :email,
    :normalizedEmail,
    :position,
    :phone,
    :mobile,
    :isPrimary,
    :isActive,
    :portalAccessStatus,
    SYSUTCDATETIME(),
    SYSUTCDATETIME()
)
SQL,
                    [
                        ':customerId' => $customerId->toInt(),
                        ':legacyContactId' => $legacyContactId?->toInt(),
                        ':displayName' => $displayName,
                        ':email' => $email?->toString(),
                        ':normalizedEmail' => $email?->toString(),
                        ':position' => $position,
                        ':phone' => $phone,
                        ':mobile' => $mobile,
                        ':isPrimary' => $isPrimary ? 1 : 0,
                        ':isActive' => $isActive ? 1 : 0,
                        ':portalAccessStatus' => $portalAccessStatus->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::insertFailed('CustomerContact');
                }

                $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? null);
                $details = $this->findDetailsByPublicId(CustomerContactPublicId::fromString($publicId));

                if ($details === null) {
                    throw CustomerPersistenceException::insertFailed('CustomerContact');
                }

                return $details;
            },
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
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $displayName, $email, $position, $phone, $mobile): CustomerContactDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [customer].[CustomerContacts]
SET
    [DisplayName] = :displayName,
    [EmailAddress] = :email,
    [NormalizedEmailAddress] = :normalizedEmail,
    [PositionTitle] = :position,
    [PhoneNumber] = :phone,
    [MobileNumber] = :mobile,
    [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[CustomerContactId]
WHERE [PublicId] = :publicId
SQL,
                    [
                        ':displayName' => $displayName,
                        ':email' => $email?->toString(),
                        ':normalizedEmail' => $email?->toString(),
                        ':position' => $position,
                        ':phone' => $phone,
                        ':mobile' => $mobile,
                        ':publicId' => $publicId->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);

                if ($details === null) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function changeStatus(
        CustomerContactPublicId $publicId,
        bool $isActive,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $isActive): CustomerContactDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [customer].[CustomerContacts]
SET
    [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[CustomerContactId]
WHERE [PublicId] = :publicId
SQL,
                    [
                        ':isActive' => $isActive ? 1 : 0,
                        ':publicId' => $publicId->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);

                if ($details === null) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function changePortalAccessStatus(
        CustomerContactPublicId $publicId,
        PortalAccessStatus $status,
        UserId $actorUserId,
    ): CustomerContactDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $status): CustomerContactDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [customer].[CustomerContacts]
SET
    [PortalAccessStatusCode] = :portalAccessStatus,
    [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[CustomerContactId]
WHERE [PublicId] = :publicId
SQL,
                    [
                        ':portalAccessStatus' => $status->toString(),
                        ':publicId' => $publicId->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);

                if ($details === null) {
                    throw CustomerPersistenceException::contactNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function findByNormalizedEmail(EmailAddress $email, ?CustomerId $customerScope = null): array
    {
        $sql = self::DETAILS_SELECT_SQL . ' WHERE cc.[NormalizedEmailAddress] = :email';
        $params = [':email' => $email->toString()];

        if ($customerScope !== null) {
            $sql .= ' AND cc.[CustomerId] = :customerId';
            $params[':customerId'] = $customerScope->toInt();
        }

        $rows = $this->connection->fetchAll($sql, $params);

        return array_map(fn (array $row): CustomerContactDetails => $this->hydrateDetails($row), $rows);
    }

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function buildFilters(ContactListCriteria $criteria): array
    {
        $conditions = [];
        $params = [];

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            // Unique :search* names — sqlsrv PDO rejects repeated named parameters.
            $conditions[] = '(cc.[DisplayName] LIKE :searchName OR cc.[EmailAddress] LIKE :searchEmail)';
            $needle = '%' . $search . '%';
            $params[':searchName'] = $needle;
            $params[':searchEmail'] = $needle;
        }

        if ($criteria->isActive !== null) {
            $conditions[] = 'cc.[IsActive] = :isActive';
            $params[':isActive'] = $criteria->isActive ? 1 : 0;
        }

        if ($criteria->portalAccessStatus !== null) {
            $conditions[] = 'cc.[PortalAccessStatusCode] = :portalAccessStatus';
            $params[':portalAccessStatus'] = strtolower($criteria->portalAccessStatus);
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        return [$where, $params];
    }

    private function sortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            ContactListCriteria::SORT_DISPLAY_NAME => 'cc.[DisplayName]',
            ContactListCriteria::SORT_UPDATED_AT => 'cc.[UpdatedAtUtc]',
            default => 'cc.[CreatedAtUtc]',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row): CustomerContactSummary
    {
        return new CustomerContactSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            customerPublicId: AzureSqlValueMapper::stringValue($row['CustomerPublicId'] ?? null),
            displayName: AzureSqlValueMapper::stringValue($row['DisplayName'] ?? null),
            email: AzureSqlValueMapper::nullableString($row['EmailAddress'] ?? null),
            position: AzureSqlValueMapper::nullableString($row['PositionTitle'] ?? null),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimaryContact'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            portalAccessStatus: AzureSqlValueMapper::stringValue($row['PortalAccessStatusCode'] ?? null),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAtUtc'] ?? null, 'UpdatedAtUtc'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateDetails(array $row): CustomerContactDetails
    {
        $legacyContactId = $row['LegacyContactId'] ?? null;

        return new CustomerContactDetails(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            customerPublicId: AzureSqlValueMapper::stringValue($row['CustomerPublicId'] ?? null),
            displayName: AzureSqlValueMapper::stringValue($row['DisplayName'] ?? null),
            email: AzureSqlValueMapper::nullableString($row['EmailAddress'] ?? null),
            normalizedEmail: AzureSqlValueMapper::nullableString($row['NormalizedEmailAddress'] ?? null),
            position: AzureSqlValueMapper::nullableString($row['PositionTitle'] ?? null),
            phone: AzureSqlValueMapper::nullableString($row['PhoneNumber'] ?? null),
            mobile: AzureSqlValueMapper::nullableString($row['MobileNumber'] ?? null),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimaryContact'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            portalAccessStatus: AzureSqlValueMapper::stringValue($row['PortalAccessStatusCode'] ?? null),
            legacyContactId: $legacyContactId === null ? null : AzureSqlValueMapper::intValue($legacyContactId),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAtUtc'] ?? null, 'UpdatedAtUtc'),
            ),
        );
    }

    private function contactIdByPublicId(CustomerContactPublicId $publicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CustomerContactId] FROM [customer].[CustomerContacts] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['CustomerContactId'] ?? null);
    }
}
