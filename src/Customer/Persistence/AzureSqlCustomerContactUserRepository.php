<?php

declare(strict_types=1);

namespace TriCab\Api\Customer\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Azure SQL implementation of the Core CustomerContactUserRepository
 * contract. Reads and writes prepared statements only.
 */
final class AzureSqlCustomerContactUserRepository implements CustomerContactUserRepository
{
    use AzureSqlTransaction;

    private const LINK_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), cc.[PublicId]) AS [ContactPublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CustomerPublicId],
    CONVERT(NVARCHAR(36), u.[PublicId]) AS [UserPublicId],
    l.[IsPrimary],
    l.[IsActive],
    CONVERT(VARCHAR(33), l.[LinkedAtUtc], 127) AS [LinkedAtUtc],
    CONVERT(VARCHAR(33), l.[UnlinkedAtUtc], 127) AS [UnlinkedAtUtc]
FROM [customer].[CustomerContactUsers] l
INNER JOIN [customer].[CustomerContacts] cc ON cc.[CustomerContactId] = l.[CustomerContactId]
INNER JOIN [customer].[Customers] c ON c.[CustomerId] = cc.[CustomerId]
INNER JOIN [identity].[Users] u ON u.[UserId] = l.[UserId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function link(
        CustomerContactId $contactId,
        UserId $userId,
        bool $isPrimary,
        ?UserId $createdByUserId,
    ): CustomerContactUserLinkDetails {
        return $this->runInTransaction(
            $this->connection,
            function () use ($contactId, $userId, $isPrimary, $createdByUserId): CustomerContactUserLinkDetails {
                $existingRows = $this->connection->fetchAll(
                    <<<'SQL'
SELECT [CustomerContactUserId]
FROM [customer].[CustomerContactUsers]
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId
SQL,
                    [':contactId' => $contactId->toInt(), ':userId' => $userId->toInt()],
                );

                if ($existingRows === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [customer].[CustomerContactUsers] (
    [CustomerContactId],
    [UserId],
    [IsPrimary],
    [IsActive],
    [LinkedAtUtc],
    [CreatedByUserId]
)
VALUES (
    :contactId,
    :userId,
    :isPrimary,
    1,
    SYSUTCDATETIME(),
    :createdByUserId
)
SQL,
                        [
                            ':contactId' => $contactId->toInt(),
                            ':userId' => $userId->toInt(),
                            ':isPrimary' => $isPrimary ? 1 : 0,
                            ':createdByUserId' => $createdByUserId?->toInt(),
                        ],
                    );
                } else {
                    $this->connection->execute(
                        <<<'SQL'
UPDATE [customer].[CustomerContactUsers]
SET
    [IsPrimary] = :isPrimary,
    [IsActive] = 1,
    [LinkedAtUtc] = SYSUTCDATETIME(),
    [UnlinkedAtUtc] = NULL,
    [CreatedByUserId] = :createdByUserId
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId
SQL,
                        [
                            ':isPrimary' => $isPrimary ? 1 : 0,
                            ':createdByUserId' => $createdByUserId?->toInt(),
                            ':contactId' => $contactId->toInt(),
                            ':userId' => $userId->toInt(),
                        ],
                    );
                }

                $details = $this->findLinkDetails($contactId, $userId);
                if ($details === null) {
                    throw CustomerPersistenceException::insertFailed('CustomerContactUser');
                }

                return $details;
            },
        );
    }

    public function unlink(CustomerContactId $contactId, UserId $userId): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE [customer].[CustomerContactUsers]
SET
    [IsActive] = 0,
    [UnlinkedAtUtc] = SYSUTCDATETIME()
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId AND [IsActive] = 1
SQL,
            [':contactId' => $contactId->toInt(), ':userId' => $userId->toInt()],
        );
    }

    public function hasActiveLink(CustomerContactId $contactId, UserId $userId): bool
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [customer].[CustomerContactUsers]
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId AND [IsActive] = 1
SQL,
            [':contactId' => $contactId->toInt(), ':userId' => $userId->toInt()],
        );

        return $rows !== [];
    }

    public function findActivePrimaryUser(CustomerContactId $contactId): ?UserId
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [UserId]
FROM [customer].[CustomerContactUsers]
WHERE [CustomerContactId] = :contactId AND [IsActive] = 1 AND [IsPrimary] = 1
SQL,
            [':contactId' => $contactId->toInt()],
        );

        if ($rows === []) {
            return null;
        }

        return UserId::fromInt(AzureSqlValueMapper::intValue($rows[0]['UserId'] ?? null));
    }

    public function listActiveLinksForUser(UserId $userId): array
    {
        $rows = $this->connection->fetchAll(
            self::LINK_SELECT_SQL . ' WHERE l.[UserId] = :userId AND l.[IsActive] = 1',
            [':userId' => $userId->toInt()],
        );

        return array_map(fn (array $row): CustomerContactUserLinkDetails => $this->hydrateLink($row), $rows);
    }

    public function listPortalUsersForCustomer(CustomerId $customerId): array
    {
        $rows = $this->connection->fetchAll(
            self::LINK_SELECT_SQL . ' WHERE cc.[CustomerId] = :customerId AND l.[IsActive] = 1',
            [':customerId' => $customerId->toInt()],
        );

        return array_map(fn (array $row): CustomerContactUserLinkDetails => $this->hydrateLink($row), $rows);
    }

    private function findLinkDetails(CustomerContactId $contactId, UserId $userId): ?CustomerContactUserLinkDetails
    {
        $rows = $this->connection->fetchAll(
            self::LINK_SELECT_SQL . ' WHERE l.[CustomerContactId] = :contactId AND l.[UserId] = :userId',
            [':contactId' => $contactId->toInt(), ':userId' => $userId->toInt()],
        );

        return $rows === [] ? null : $this->hydrateLink($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLink(array $row): CustomerContactUserLinkDetails
    {
        $unlinkedAt = AzureSqlValueMapper::nullableDateTime($row['UnlinkedAtUtc'] ?? null);

        return new CustomerContactUserLinkDetails(
            contactPublicId: AzureSqlValueMapper::stringValue($row['ContactPublicId'] ?? null),
            customerPublicId: AzureSqlValueMapper::stringValue($row['CustomerPublicId'] ?? null),
            userPublicId: AzureSqlValueMapper::stringValue($row['UserPublicId'] ?? null),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            linkedAtUtc: AzureSqlValueMapper::formatIso8601(
                AzureSqlValueMapper::requireDateTime($row['LinkedAtUtc'] ?? null, 'LinkedAtUtc'),
            ),
            unlinkedAtUtc: $unlinkedAt !== null ? AzureSqlValueMapper::formatIso8601($unlinkedAt) : null,
        );
    }
}
