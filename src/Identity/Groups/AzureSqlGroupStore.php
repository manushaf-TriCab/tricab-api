<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/** `identity.Groups` and `identity.GroupMembers` on Azure SQL. */
final class AzureSqlGroupStore implements GroupStore
{
    private const SELECT = <<<'SQL'
        SELECT g.[GroupId], CONVERT(NVARCHAR(36), g.[PublicId]) AS [PublicId], g.[Name], g.[Description], g.[SourceCode],
               g.[EntraGroupId], g.[EntraMail], g.[IsUnlinked],
               CONVERT(VARCHAR(33), g.[UnlinkedAt], 127) AS [UnlinkedAt],
               CONVERT(VARCHAR(33), g.[LastSyncedAt], 127) AS [LastSyncedAt],
               g.[LastSyncOutcome], g.[LastSyncMessage],
               CONVERT(VARCHAR(33), g.[CreatedAt], 127) AS [CreatedAt]
        FROM [identity].[Groups] g
        SQL;

    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function transaction(callable $work): mixed
    {
        if ($this->connection->inTransaction()) {
            return $work();
        }

        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    public function search(?string $query, ?string $source, bool $includeUnlinked, int $page, int $pageSize): array
    {
        [$where, $params] = $this->filter($query, $source, $includeUnlinked);
        $rows = $this->connection->fetchAll(
            self::SELECT . $where . ' ORDER BY g.[Name], g.[GroupId] OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY',
            [...$params, ':offset' => ($page - 1) * $pageSize, ':pageSize' => $pageSize],
        );
        $total = $this->connection->fetchAll('SELECT COUNT(*) AS [Total] FROM [identity].[Groups] g' . $where, $params);

        return [
            'items' => array_map(self::record(...), $rows),
            'total' => AzureSqlValueMapper::intValue($total[0]['Total'] ?? 0),
        ];
    }

    public function lookup(string $query, int $limit): array
    {
        [$where, $params] = $this->filter($query, null, false);
        $rows = $this->connection->fetchAll(
            self::SELECT . $where . ' ORDER BY g.[Name], g.[GroupId] OFFSET 0 ROWS FETCH NEXT :limit ROWS ONLY',
            [...$params, ':limit' => $limit],
        );

        return array_map(self::record(...), $rows);
    }

    public function findByPublicId(string $publicId): ?GroupRecord
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $publicId) !== 1) {
            return null;
        }

        return $this->one(self::SELECT . ' WHERE g.[PublicId] = :publicId', [':publicId' => $publicId]);
    }

    public function findByEntraGroupId(string $entraGroupId): ?GroupRecord
    {
        return $this->one(self::SELECT . ' WHERE g.[EntraGroupId] = :entra', [':entra' => strtolower($entraGroupId)]);
    }

    public function platformNameExists(string $name, ?int $exceptGroupId = null): bool
    {
        $rows = $this->connection->fetchAll(
            "SELECT 1 AS [Found] FROM [identity].[Groups] WHERE [SourceCode] = N'platform' AND [NormalizedName] = :name AND [GroupId] <> :except",
            [':name' => mb_strtolower($name), ':except' => $exceptGroupId ?? 0],
        );

        return $rows !== [];
    }

    public function createPlatform(string $name, ?string $description, ?int $actorUserId): GroupRecord
    {
        return $this->insert($name, $description, GroupRecord::SOURCE_PLATFORM, null, null, $actorUserId);
    }

    public function updatePlatform(int $groupId, string $name, ?string $description): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Groups] SET [Name] = :name, [NormalizedName] = :normalized, [Description] = :description, [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [GroupId] = :id',
            [':name' => $name, ':normalized' => mb_strtolower($name), ':description' => $description, ':id' => $groupId],
        );
    }

    public function delete(int $groupId): void
    {
        $this->transaction(function () use ($groupId): void {
            $this->connection->execute('DELETE FROM [identity].[GroupMembers] WHERE [GroupId] = :id', [':id' => $groupId]);
            $this->connection->execute('DELETE FROM [identity].[Groups] WHERE [GroupId] = :id', [':id' => $groupId]);
        });
    }

    public function createEntra(EntraGroup $group, ?int $actorUserId): GroupRecord
    {
        return $this->insert($group->displayName, $group->description, GroupRecord::SOURCE_ENTRA, strtolower($group->id), $group->mail, $actorUserId);
    }

    public function relinkEntra(int $groupId, EntraGroup $group): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Groups] SET [Name] = :name, [NormalizedName] = :normalized, [Description] = :description, [EntraMail] = :mail,
                    [IsUnlinked] = 0, [UnlinkedAt] = NULL, [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [GroupId] = :id',
            [':name' => $group->displayName, ':normalized' => mb_strtolower($group->displayName), ':description' => $group->description, ':mail' => $group->mail, ':id' => $groupId],
        );
    }

    public function refreshEntra(int $groupId, EntraGroup $group): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Groups] SET [Name] = :name, [NormalizedName] = :normalized, [Description] = :description, [EntraMail] = :mail,
                    [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [GroupId] = :id',
            [':name' => $group->displayName, ':normalized' => mb_strtolower($group->displayName), ':description' => $group->description, ':mail' => $group->mail, ':id' => $groupId],
        );
    }

    public function markUnlinked(int $groupId): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Groups] SET [IsUnlinked] = 1, [UnlinkedAt] = COALESCE([UnlinkedAt], SYSDATETIMEOFFSET()), [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [GroupId] = :id',
            [':id' => $groupId],
        );
    }

    public function linkedEntraGroups(): array
    {
        $rows = $this->connection->fetchAll(
            self::SELECT . " WHERE g.[SourceCode] = N'entra' AND g.[IsUnlinked] = 0 ORDER BY g.[GroupId]",
        );

        return array_map(self::record(...), $rows);
    }

    public function members(int $groupId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [UserId], [SourceCode], CONVERT(VARCHAR(33), [AddedAt], 127) AS [AddedAt] FROM [identity].[GroupMembers] WHERE [GroupId] = :id ORDER BY [GroupMemberId]',
            [':id' => $groupId],
        );

        return array_map(static fn (array $r): array => [
            'userId' => AzureSqlValueMapper::intValue($r['UserId'] ?? 0),
            'source' => AzureSqlValueMapper::stringValue($r['SourceCode'] ?? ''),
            'addedAt' => AzureSqlValueMapper::stringValue($r['AddedAt'] ?? ''),
        ], $rows);
    }

    public function addMember(int $groupId, int $userId, string $source, ?int $actorUserId): bool
    {
        $exists = $this->connection->fetchAll(
            'SELECT 1 AS [Found] FROM [identity].[GroupMembers] WHERE [GroupId] = :g AND [UserId] = :u',
            [':g' => $groupId, ':u' => $userId],
        );
        if ($exists !== []) {
            return false;
        }
        $this->connection->execute(
            'INSERT INTO [identity].[GroupMembers] ([GroupId], [UserId], [SourceCode], [AddedByUserId]) VALUES (:g, :u, :source, :actor)',
            [':g' => $groupId, ':u' => $userId, ':source' => $source, ':actor' => $actorUserId],
        );

        return true;
    }

    public function removeMember(int $groupId, int $userId): bool
    {
        $rows = $this->connection->fetchAll(
            'DELETE FROM [identity].[GroupMembers] OUTPUT DELETED.[UserId] WHERE [GroupId] = :g AND [UserId] = :u',
            [':g' => $groupId, ':u' => $userId],
        );

        return $rows !== [];
    }

    public function removeEntraMembers(int $groupId, array $userIds): int
    {
        $removed = 0;
        foreach ($userIds as $userId) {
            $rows = $this->connection->fetchAll(
                "DELETE FROM [identity].[GroupMembers] OUTPUT DELETED.[UserId] WHERE [GroupId] = :g AND [UserId] = :u AND [SourceCode] = N'entra'",
                [':g' => $groupId, ':u' => $userId],
            );
            $removed += count($rows);
        }

        return $removed;
    }

    public function lock(int $groupId): void
    {
        $this->connection->fetchAll(
            'SELECT [GroupId] FROM [identity].[Groups] WITH (UPDLOCK, HOLDLOCK) WHERE [GroupId] = :id',
            [':id' => $groupId],
        );
    }

    public function recordSync(int $groupId, string $outcome, ?string $message): void
    {
        $this->connection->execute(
            'UPDATE [identity].[Groups] SET [LastSyncOutcome] = :outcome, [LastSyncMessage] = :message,
                    [LastSyncedAt] = CASE WHEN :ok = 1 THEN SYSDATETIMEOFFSET() ELSE [LastSyncedAt] END WHERE [GroupId] = :id',
            [':outcome' => $outcome, ':message' => $message, ':ok' => $outcome === 'ok' ? 1 : 0, ':id' => $groupId],
        );
    }

    public function recordOutcomeForLinked(string $outcome, string $message): void
    {
        $this->connection->execute(
            "UPDATE [identity].[Groups] SET [LastSyncOutcome] = :outcome, [LastSyncMessage] = :message WHERE [SourceCode] = N'entra' AND [IsUnlinked] = 0",
            [':outcome' => $outcome, ':message' => $message],
        );
    }

    public function counts(array $groupIds): array
    {
        $counts = [];
        foreach ($groupIds as $id) {
            $counts[$id] = ['members' => 0, 'pending' => 0];
        }
        if ($groupIds === []) {
            return $counts;
        }

        // Ids are our own integers, never user input.
        $in = implode(',', array_map('intval', $groupIds));
        $rows = $this->connection->fetchAll(
            "SELECT m.[GroupId], COUNT(*) AS [Members], SUM(CASE WHEN s.[StatusCode] = N'pending' THEN 1 ELSE 0 END) AS [Pending]
             FROM [identity].[GroupMembers] m
             JOIN [identity].[Users] u ON u.[UserId] = m.[UserId]
             JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
             WHERE m.[GroupId] IN ({$in})
             GROUP BY m.[GroupId]",
        );
        foreach ($rows as $row) {
            $counts[AzureSqlValueMapper::intValue($row['GroupId'] ?? 0)] = [
                'members' => AzureSqlValueMapper::intValue($row['Members'] ?? 0),
                'pending' => AzureSqlValueMapper::intValue($row['Pending'] ?? 0),
            ];
        }

        return $counts;
    }

    /** @return array{0: string, 1: array<string, scalar|null>} */
    private function filter(?string $query, ?string $source, bool $includeUnlinked): array
    {
        $where = [];
        $params = [];
        if (!$includeUnlinked) {
            $where[] = 'g.[IsUnlinked] = 0';
        }
        if ($source !== null) {
            $where[] = 'g.[SourceCode] = :source';
            $params[':source'] = $source;
        }
        if ($query !== null && trim($query) !== '') {
            $where[] = "g.[NormalizedName] LIKE :q ESCAPE N'!'";
            $params[':q'] = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], mb_strtolower(trim($query))) . '%';
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    private function insert(string $name, ?string $description, string $source, ?string $entraId, ?string $mail, ?int $actorUserId): GroupRecord
    {
        $rows = $this->connection->fetchAll(
            'INSERT INTO [identity].[Groups] ([Name], [NormalizedName], [Description], [SourceCode], [EntraGroupId], [EntraMail], [CreatedByUserId])
             OUTPUT INSERTED.[GroupId]
             VALUES (:name, :normalized, :description, :source, :entra, :mail, :actor)',
            [
                ':name' => $name, ':normalized' => mb_strtolower($name), ':description' => $description, ':source' => $source,
                ':entra' => $entraId, ':mail' => $mail, ':actor' => $actorUserId,
            ],
        );
        $id = AzureSqlValueMapper::intValue($rows[0]['GroupId'] ?? 0);
        $record = $this->one(self::SELECT . ' WHERE g.[GroupId] = :id', [':id' => $id]);
        if ($record === null) {
            throw new \RuntimeException('The group was not saved.');
        }

        return $record;
    }

    /** @param array<string, scalar|null> $params */
    private function one(string $sql, array $params): ?GroupRecord
    {
        $rows = $this->connection->fetchAll($sql, $params);

        return $rows === [] ? null : self::record($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): GroupRecord
    {
        return new GroupRecord(
            AzureSqlValueMapper::intValue($row['GroupId'] ?? 0),
            strtolower(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            AzureSqlValueMapper::stringValue($row['SourceCode'] ?? ''),
            AzureSqlValueMapper::nullableString($row['EntraGroupId'] ?? null),
            AzureSqlValueMapper::nullableString($row['EntraMail'] ?? null),
            AzureSqlValueMapper::boolValue($row['IsUnlinked'] ?? false),
            AzureSqlValueMapper::nullableString($row['UnlinkedAt'] ?? null),
            AzureSqlValueMapper::nullableString($row['LastSyncedAt'] ?? null),
            AzureSqlValueMapper::nullableString($row['LastSyncOutcome'] ?? null),
            AzureSqlValueMapper::nullableString($row['LastSyncMessage'] ?? null),
            AzureSqlValueMapper::stringValue($row['CreatedAt'] ?? ''),
        );
    }
}
