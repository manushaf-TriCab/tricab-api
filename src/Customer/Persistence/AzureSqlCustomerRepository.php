<?php

declare(strict_types=1);

namespace TriCab\Api\Customer\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Application\DTO\CustomerSummary;
use TriCab\Core\Customer\Application\DTO\CustomerSummaryCounts;
use TriCab\Core\Customer\Application\DTO\Pagination;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Customer\Domain\ValueObject\LegacyCustomerId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Azure SQL implementation of the Core CustomerRepository contract. Reads and
 * writes prepared statements only; search text and sort selection never
 * reach the SQL text directly — only whitelisted fragments and bound
 * parameters do.
 */
final class AzureSqlCustomerRepository implements CustomerRepository
{
    use AzureSqlTransaction;

    private const DETAILS_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId],
    [CustomerCode],
    [CustomerName],
    [StatusCode],
    [IsActive],
    [AddressLine1],
    [AddressLine2],
    [Suburb],
    [Postcode],
    [CountryCode],
    [MarketCode],
    [SalesRepresentative],
    [TerritoryCode],
    [PhoneNumber],
    [LegacyCustomerId],
    CONVERT(VARCHAR(33), [CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), [UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [customer].[Customers]
SQL;

    private const SUMMARY_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[CustomerCode],
    c.[CustomerName],
    c.[StatusCode],
    c.[IsActive],
    c.[Suburb],
    c.[Postcode],
    c.[CountryCode],
    c.[MarketCode],
    c.[TerritoryCode],
    c.[PhoneNumber],
    (
        SELECT COUNT(*)
        FROM [customer].[CustomerContacts] cc
        WHERE cc.[CustomerId] = c.[CustomerId]
    ) AS [ContactCount],
    (
        SELECT COUNT(*)
        FROM [customer].[CustomerContactUsers] ccu
        INNER JOIN [customer].[CustomerContacts] cc2 ON cc2.[CustomerContactId] = ccu.[CustomerContactId]
        WHERE cc2.[CustomerId] = c.[CustomerId]
          AND ccu.[IsActive] = 1
    ) AS [PortalUserCount],
    CONVERT(VARCHAR(33), c.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), c.[UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [customer].[Customers] c
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function list(CustomerListCriteria $criteria): CustomerListResult
    {
        [$where, $filterParams] = $this->buildFilters($criteria);

        $countRows = $this->connection->fetchAll(
            "SELECT COUNT(*) AS [Total] FROM [customer].[Customers] c WHERE 1 = 1{$where}",
            $filterParams,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $sortColumn = $this->sortColumn($criteria->sortBy);
        $sortDirection = $criteria->normalizedSortDirection() === CustomerListCriteria::SORT_ASC ? 'ASC' : 'DESC';
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            self::SUMMARY_SELECT_SQL . <<<SQL

WHERE 1 = 1{$where}
ORDER BY {$sortColumn} {$sortDirection}
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [...$filterParams, ':offset' => $offset, ':pageSize' => $criteria->pageSize],
        );

        $items = array_map(fn (array $row): CustomerSummary => $this->hydrateSummary($row), $rows);

        return new CustomerListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
            counts: $this->summarizeCounts(),
        );
    }

    public function findDetailsByPublicId(CustomerPublicId $publicId): ?CustomerDetails
    {
        $rows = $this->connection->fetchAll(
            self::DETAILS_SELECT_SQL . ' WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrateDetails($rows[0]);
    }

    public function findIdByPublicId(CustomerPublicId $publicId): ?CustomerId
    {
        $id = $this->customerIdByPublicId($publicId);

        return $id !== null ? CustomerId::fromInt($id) : null;
    }

    public function customerCodeExists(CustomerCode $customerCode, ?CustomerPublicId $exceptPublicId = null): bool
    {
        $sql = 'SELECT TOP 1 1 AS [Found] FROM [customer].[Customers] WHERE [CustomerCode] = :customerCode';
        $params = [':customerCode' => $customerCode->toString()];

        if ($exceptPublicId !== null) {
            $sql .= ' AND [PublicId] <> :exceptPublicId';
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
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
        return $this->runInTransaction(
            $this->connection,
            function () use (
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
            ): CustomerDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [customer].[Customers] (
    [LegacyCustomerId],
    [CustomerCode],
    [CustomerName],
    [StatusCode],
    [AddressLine1],
    [AddressLine2],
    [Suburb],
    [Postcode],
    [CountryCode],
    [MarketCode],
    [SalesRepresentative],
    [TerritoryCode],
    [PhoneNumber],
    [IsActive],
    [CreatedAtUtc],
    [UpdatedAtUtc]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :legacyCustomerId,
    :customerCode,
    :customerName,
    :statusCode,
    :addressLine1,
    :addressLine2,
    :suburb,
    :postcode,
    :countryCode,
    :marketCode,
    :salesRep,
    :territory,
    :phone,
    :isActive,
    SYSUTCDATETIME(),
    SYSUTCDATETIME()
)
SQL,
                    [
                        ':legacyCustomerId' => $legacyCustomerId?->toInt(),
                        ':customerCode' => $customerCode?->toString(),
                        ':customerName' => $customerName,
                        ':statusCode' => $status->toString(),
                        ':addressLine1' => $addressLine1,
                        ':addressLine2' => $addressLine2,
                        ':suburb' => $suburb,
                        ':postcode' => $postcode,
                        ':countryCode' => $countryCode,
                        ':marketCode' => $marketCode,
                        ':salesRep' => $salesRep,
                        ':territory' => $territory,
                        ':phone' => $phone,
                        ':isActive' => $status->isActive() ? 1 : 0,
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::insertFailed('Customer');
                }

                $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? null);
                $details = $this->findDetailsByPublicId(CustomerPublicId::fromString($publicId));

                if ($details === null) {
                    throw CustomerPersistenceException::insertFailed('Customer');
                }

                return $details;
            },
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
        return $this->runInTransaction(
            $this->connection,
            function () use (
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
            ): CustomerDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [customer].[Customers]
SET
    [CustomerCode] = :customerCode,
    [CustomerName] = :customerName,
    [AddressLine1] = :addressLine1,
    [AddressLine2] = :addressLine2,
    [Suburb] = :suburb,
    [Postcode] = :postcode,
    [CountryCode] = :countryCode,
    [MarketCode] = :marketCode,
    [SalesRepresentative] = :salesRep,
    [TerritoryCode] = :territory,
    [PhoneNumber] = :phone,
    [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[CustomerId]
WHERE [PublicId] = :publicId
SQL,
                    [
                        ':customerCode' => $customerCode?->toString(),
                        ':customerName' => $customerName,
                        ':addressLine1' => $addressLine1,
                        ':addressLine2' => $addressLine2,
                        ':suburb' => $suburb,
                        ':postcode' => $postcode,
                        ':countryCode' => $countryCode,
                        ':marketCode' => $marketCode,
                        ':salesRep' => $salesRep,
                        ':territory' => $territory,
                        ':phone' => $phone,
                        ':publicId' => $publicId->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::customerNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);

                if ($details === null) {
                    throw CustomerPersistenceException::customerNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function changeStatus(
        CustomerPublicId $publicId,
        CustomerStatus $status,
        UserId $actorUserId,
    ): CustomerDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($publicId, $status): CustomerDetails {
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [customer].[Customers]
SET
    [StatusCode] = :statusCode,
    [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[CustomerId]
WHERE [PublicId] = :publicId
SQL,
                    [
                        ':statusCode' => $status->toString(),
                        ':isActive' => $status->isActive() ? 1 : 0,
                        ':publicId' => $publicId->toString(),
                    ],
                );

                if ($rows === []) {
                    throw CustomerPersistenceException::customerNotFound($publicId->toString());
                }

                $details = $this->findDetailsByPublicId($publicId);

                if ($details === null) {
                    throw CustomerPersistenceException::customerNotFound($publicId->toString());
                }

                return $details;
            },
        );
    }

    public function summarizeCounts(): CustomerSummaryCounts
    {
        // sqlsrv PDO requires unique named parameters — never reuse :name in one statement.
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    (SELECT COUNT(*) FROM [customer].[Customers]) AS [TotalCustomers],
    (SELECT COUNT(*) FROM [customer].[Customers] WHERE [StatusCode] = :activeStatus) AS [ActiveCustomers],
    (SELECT COUNT(*) FROM [customer].[Customers] WHERE [StatusCode] = :inactiveStatus) AS [InactiveCustomers],
    (SELECT COUNT(*) FROM [customer].[CustomerContacts] WHERE [PortalAccessStatusCode] = :activePortalStatus) AS [PortalEnabledCustomers]
SQL,
            [
                ':activeStatus' => CustomerStatus::ACTIVE,
                ':inactiveStatus' => CustomerStatus::INACTIVE,
                ':activePortalStatus' => 'active',
            ],
        );

        /** @var array<string, mixed> $row */
        $row = $rows[0] ?? [];

        return new CustomerSummaryCounts(
            totalCustomers: AzureSqlValueMapper::intValue($row['TotalCustomers'] ?? 0),
            activeCustomers: AzureSqlValueMapper::intValue($row['ActiveCustomers'] ?? 0),
            inactiveCustomers: AzureSqlValueMapper::intValue($row['InactiveCustomers'] ?? 0),
            portalEnabledCustomers: AzureSqlValueMapper::intValue($row['PortalEnabledCustomers'] ?? 0),
        );
    }

    /**
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function buildFilters(CustomerListCriteria $criteria): array
    {
        $conditions = [];
        $params = [];

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            // Unique :search* names — sqlsrv PDO rejects repeated named parameters.
            $conditions[] = '(c.[CustomerName] LIKE :searchName OR c.[CustomerCode] LIKE :searchCode OR CONVERT(NVARCHAR(36), c.[PublicId]) LIKE :searchPublicId)';
            $needle = '%' . $search . '%';
            $params[':searchName'] = $needle;
            $params[':searchCode'] = $needle;
            $params[':searchPublicId'] = $needle;
        }

        if ($criteria->status !== null) {
            $conditions[] = 'c.[StatusCode] = :status';
            $params[':status'] = strtolower($criteria->status);
        }

        if ($criteria->countryCode !== null) {
            $conditions[] = 'c.[CountryCode] = :countryCode';
            $params[':countryCode'] = $criteria->countryCode;
        }

        if ($criteria->marketCode !== null) {
            $conditions[] = 'c.[MarketCode] = :marketCode';
            $params[':marketCode'] = $criteria->marketCode;
        }

        if ($criteria->territoryCode !== null) {
            $conditions[] = 'c.[TerritoryCode] = :territoryCode';
            $params[':territoryCode'] = $criteria->territoryCode;
        }

        $where = $conditions === [] ? '' : (' AND ' . implode(' AND ', $conditions));

        return [$where, $params];
    }

    private function sortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            CustomerListCriteria::SORT_NAME => 'c.[CustomerName]',
            CustomerListCriteria::SORT_CODE => 'c.[CustomerCode]',
            CustomerListCriteria::SORT_STATUS => 'c.[StatusCode]',
            CustomerListCriteria::SORT_UPDATED_AT => 'c.[UpdatedAtUtc]',
            default => 'c.[CreatedAtUtc]',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row): CustomerSummary
    {
        return new CustomerSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            customerCode: AzureSqlValueMapper::nullableString($row['CustomerCode'] ?? null),
            customerName: AzureSqlValueMapper::stringValue($row['CustomerName'] ?? null),
            status: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            suburb: AzureSqlValueMapper::nullableString($row['Suburb'] ?? null),
            postcode: AzureSqlValueMapper::nullableString($row['Postcode'] ?? null),
            countryCode: AzureSqlValueMapper::nullableString($row['CountryCode'] ?? null),
            marketCode: AzureSqlValueMapper::nullableString($row['MarketCode'] ?? null),
            territoryCode: AzureSqlValueMapper::nullableString($row['TerritoryCode'] ?? null),
            phone: AzureSqlValueMapper::nullableString($row['PhoneNumber'] ?? null),
            contactCount: AzureSqlValueMapper::intValue($row['ContactCount'] ?? 0),
            portalUserCount: AzureSqlValueMapper::intValue($row['PortalUserCount'] ?? 0),
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
    private function hydrateDetails(array $row): CustomerDetails
    {
        $legacyCustomerId = $row['LegacyCustomerId'] ?? null;

        return new CustomerDetails(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? null),
            customerCode: AzureSqlValueMapper::nullableString($row['CustomerCode'] ?? null),
            customerName: AzureSqlValueMapper::stringValue($row['CustomerName'] ?? null),
            status: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            addressLine1: AzureSqlValueMapper::nullableString($row['AddressLine1'] ?? null),
            addressLine2: AzureSqlValueMapper::nullableString($row['AddressLine2'] ?? null),
            suburb: AzureSqlValueMapper::nullableString($row['Suburb'] ?? null),
            postcode: AzureSqlValueMapper::nullableString($row['Postcode'] ?? null),
            countryCode: AzureSqlValueMapper::nullableString($row['CountryCode'] ?? null),
            marketCode: AzureSqlValueMapper::nullableString($row['MarketCode'] ?? null),
            salesRep: AzureSqlValueMapper::nullableString($row['SalesRepresentative'] ?? null),
            territory: AzureSqlValueMapper::nullableString($row['TerritoryCode'] ?? null),
            phone: AzureSqlValueMapper::nullableString($row['PhoneNumber'] ?? null),
            legacyCustomerId: $legacyCustomerId === null ? null : AzureSqlValueMapper::intValue($legacyCustomerId),
            createdAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['CreatedAtUtc'] ?? null, 'CreatedAtUtc'),
            ),
            updatedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['UpdatedAtUtc'] ?? null, 'UpdatedAtUtc'),
            ),
        );
    }

    private function customerIdByPublicId(CustomerPublicId $publicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CustomerId] FROM [customer].[Customers] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['CustomerId'] ?? null);
    }
}
