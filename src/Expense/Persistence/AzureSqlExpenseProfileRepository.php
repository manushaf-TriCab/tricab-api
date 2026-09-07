<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class AzureSqlExpenseProfileRepository implements ExpenseProfileRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS = <<<'SQL'
    p.[UserId],
    p.[LegacyLogin],
    p.[LegacyEmail],
    p.[IsActive],
    p.[IsFinance],
    p.[IsExpenseAdmin],
    p.[ManagerUserId],
    p.[LegacyManagerLogin]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findByUserId(UserId $userId): ?ExpenseProfile
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseProfiles] p
WHERE p.[UserId] = :userId',
            [':userId' => $userId->toInt()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByLegacyLogin(string $legacyLogin): ?ExpenseProfile
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseProfiles] p
WHERE LOWER(p.[LegacyLogin]) = LOWER(:legacyLogin)',
            [':legacyLogin' => trim($legacyLogin)],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByLegacyEmail(string $legacyEmail): ?ExpenseProfile
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::SELECT_COLUMNS . '
FROM [expense].[ExpenseProfiles] p
WHERE LOWER(p.[LegacyEmail]) = LOWER(:legacyEmail)',
            [':legacyEmail' => trim($legacyEmail)],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function save(ExpenseProfile $profile): ExpenseProfile
    {
        return $this->runInTransaction($this->connection, function () use ($profile): ExpenseProfile {
            $existing = $this->findByUserId($profile->userId());
            if ($existing === null) {
                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [expense].[ExpenseProfiles] (
    [UserId],
    [LegacyLogin],
    [LegacyEmail],
    [IsActive],
    [IsFinance],
    [IsExpenseAdmin],
    [ManagerUserId],
    [LegacyManagerLogin],
    [CreatedAtUtc],
    [UpdatedAtUtc]
)
VALUES (
    :userId,
    :legacyLogin,
    :legacyEmail,
    :isActive,
    :isFinance,
    :isExpenseAdmin,
    :managerUserId,
    :legacyManagerLogin,
    SYSUTCDATETIME(),
    SYSUTCDATETIME()
)
SQL,
                    $this->writeParams($profile),
                );
            } else {
                $this->connection->execute(
                    <<<'SQL'
UPDATE [expense].[ExpenseProfiles]
SET
    [LegacyLogin] = :legacyLogin,
    [LegacyEmail] = :legacyEmail,
    [IsActive] = :isActive,
    [IsFinance] = :isFinance,
    [IsExpenseAdmin] = :isExpenseAdmin,
    [ManagerUserId] = :managerUserId,
    [LegacyManagerLogin] = :legacyManagerLogin,
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [UserId] = :userId
SQL,
                    $this->writeParams($profile),
                );
            }

            $saved = $this->findByUserId($profile->userId());
            if ($saved === null) {
                throw new \RuntimeException('Failed to persist expense profile for user ' . $profile->userId()->toInt());
            }

            return $saved;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(ExpenseProfile $profile): array
    {
        return [
            ':userId' => $profile->userId()->toInt(),
            ':legacyLogin' => $profile->legacyLogin(),
            ':legacyEmail' => $profile->legacyEmail(),
            ':isActive' => $profile->isActive() ? 1 : 0,
            ':isFinance' => $profile->isFinance() ? 1 : 0,
            ':isExpenseAdmin' => $profile->isExpenseAdmin() ? 1 : 0,
            ':managerUserId' => $profile->managerUserId()?->toInt(),
            ':legacyManagerLogin' => $profile->managerLegacyLogin(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ExpenseProfile
    {
        $managerUserId = AzureSqlValueMapper::nullableInt($row['ManagerUserId'] ?? null);

        return new ExpenseProfile(
            UserId::fromInt(AzureSqlValueMapper::intValue($row['UserId'] ?? 0)),
            AzureSqlValueMapper::stringValue($row['LegacyLogin'] ?? ''),
            AzureSqlValueMapper::stringValue($row['LegacyEmail'] ?? ''),
            AzureSqlValueMapper::boolValue($row['IsActive'] ?? 0),
            AzureSqlValueMapper::boolValue($row['IsFinance'] ?? 0),
            AzureSqlValueMapper::boolValue($row['IsExpenseAdmin'] ?? 0),
            AzureSqlValueMapper::nullableString($row['LegacyManagerLogin'] ?? null),
            $managerUserId !== null ? UserId::fromInt($managerUserId) : null,
        );
    }
}
