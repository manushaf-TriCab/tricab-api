<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;

final class AzureSqlLegacyStaffUserMappingRepository implements LegacyStaffUserMappingRepository
{
    private const TABLE = '[migration].[LegacyStaffUserMappings]';

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function schemaReady(): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT OBJECT_ID(N\'migration.LegacyStaffUserMappings\', N\'U\') AS [ObjectId]',
        );

        return ($rows[0]['ObjectId'] ?? null) !== null;
    }

    public function findByLegacyWordPressUserId(int $legacyWordPressUserId): ?array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 * FROM ' . self::TABLE . ' WHERE [LegacyWordPressUserId] = :id',
            [':id' => $legacyWordPressUserId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?array
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 * FROM ' . self::TABLE . ' WHERE [NormalizedEmailAddress] = :email',
            [':email' => strtolower(trim($normalizedEmail))],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function insertMapping(array $data): void
    {
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[LegacyStaffUserMappings] (
    [SourceSystem],
    [LegacyWordPressUserId],
    [LegacyUserLogin],
    [LegacyEmailAddress],
    [NormalizedEmailAddress],
    [UserId],
    [BatchPublicId],
    [SourceFingerprint],
    [MigrationStatusCode],
    [MigratedAtUtc]
) VALUES (
    N'wordpress',
    :legacyWordPressUserId,
    :legacyLogin,
    :legacyEmailAddress,
    :normalizedEmailAddress,
    :userId,
    :batchPublicId,
    :sourceFingerprint,
    :migrationStatusCode,
    SYSUTCDATETIME()
)
SQL,
            [
                ':legacyWordPressUserId' => $data['legacyWordPressUserId'],
                ':legacyLogin' => $data['legacyLogin'],
                ':legacyEmailAddress' => $data['legacyEmailAddress'],
                ':normalizedEmailAddress' => $data['normalizedEmailAddress'],
                ':userId' => $data['userId'],
                ':batchPublicId' => $data['batchPublicId'],
                ':sourceFingerprint' => $data['sourceFingerprint'],
                ':migrationStatusCode' => $data['migrationStatusCode'],
            ],
        );
    }

    public function countMappings(?string $batchPublicId = null): int
    {
        if ($batchPublicId === null) {
            $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [Total] FROM ' . self::TABLE);

            return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
        }
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [Total] FROM ' . self::TABLE . ' WHERE [BatchPublicId] = :batchPublicId',
            [':batchPublicId' => $batchPublicId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Total'] ?? 0);
    }

    public function listByBatchPublicId(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' WHERE [BatchPublicId] = :batchPublicId ORDER BY [LegacyWordPressUserId] ASC',
            [':batchPublicId' => $batchPublicId],
        );

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'legacyStaffUserMappingId' => AzureSqlValueMapper::intValue($row['LegacyStaffUserMappingId'] ?? 0),
            'sourceSystem' => AzureSqlValueMapper::stringValue($row['SourceSystem'] ?? ''),
            'legacyWordPressUserId' => AzureSqlValueMapper::intValue($row['LegacyWordPressUserId'] ?? 0),
            'legacyLogin' => AzureSqlValueMapper::stringValue($row['LegacyUserLogin'] ?? ''),
            'legacyEmailAddress' => AzureSqlValueMapper::stringValue($row['LegacyEmailAddress'] ?? ''),
            'normalizedEmailAddress' => AzureSqlValueMapper::stringValue($row['NormalizedEmailAddress'] ?? ''),
            'userId' => AzureSqlValueMapper::intValue($row['UserId'] ?? 0),
            'batchPublicId' => AzureSqlValueMapper::stringValue($row['BatchPublicId'] ?? ''),
            'sourceFingerprint' => AzureSqlValueMapper::stringValue($row['SourceFingerprint'] ?? ''),
            'migrationStatusCode' => AzureSqlValueMapper::stringValue($row['MigrationStatusCode'] ?? ''),
        ];
    }
}
