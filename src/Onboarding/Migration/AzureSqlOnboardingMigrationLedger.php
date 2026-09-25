<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Migration;

use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Migration\OnboardingMigrationRefused;
use TriCab\Core\Onboarding\Migration\Source\SourceSnapshotSchema;

/**
 * The record of what an onboarding migration batch is and did (Phase 7.4).
 *
 *  - The batch header is a row in the generic ledger,
 *    `migration.LegacyMigrationBatches`, with `ModuleCode = onboarding`, so
 *    onboarding batches appear beside every other migration. Its status runs
 *    `preview → executing → completed | failed → rolled-back`, and every change
 *    is logged in `migration.LegacyMigrationLogs`.
 *  - `migration.OnboardingMigrationRows` holds one row per thing created. It
 *    is what verification counts and what rollback removes, and nothing else.
 *  - One run at a time: `migration.LegacyMigrationLocks` under a fixed key for
 *    the whole module, with an expiry so a crashed run does not hold it for ever.
 */
final readonly class AzureSqlOnboardingMigrationLedger
{
    public const MODULE_CODE = 'onboarding';
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled-back';

    /** One lock for the module: two onboarding runs never overlap. */
    private const LOCK_KEY = '6f6e626f-6172-4469-8e67-6d6967726174';
    private const LOCK_MINUTES = 60;

    public function __construct(private SqlConnection $connection)
    {
    }

    public function assertInstalled(): void
    {
        $rows = $this->connection->fetchAll("SELECT OBJECT_ID(N'[migration].[OnboardingMigrationRows]', N'U') AS [Rows], OBJECT_ID(N'[migration].[LegacyMigrationBatches]', N'U') AS [Batches]");
        if (($rows[0]['Rows'] ?? null) === null || ($rows[0]['Batches'] ?? null) === null) {
            throw OnboardingMigrationRefused::because('LEDGER_MISSING', 'migration.OnboardingMigrationRows does not exist: apply database/scripts/development/create-onboarding-migration-rows.sql (owner approval).');
        }
    }

    /**
     * @param array<string, mixed> $summary counts and hashes; never personal data
     *
     * @return string the batch public id
     */
    public function createBatch(ActorContext $actor, string $fingerprint, string $snapshotHash, bool $synthetic, array $summary): string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            INSERT INTO [migration].[LegacyMigrationBatches]
                ([Name], [Description], [ModuleCode], [SourceSystem], [SourceFilterJson], [StatusCode], [CreatedByUserId], [TotalRecords])
            OUTPUT LOWER(CONVERT(NVARCHAR(36), INSERTED.[PublicId])) AS [PublicId]
            VALUES (:name, :description, :module, :source, :filter, :status, :actor, :total)
            SQL,
            [
                ':name' => sprintf('Onboarding migration %s%s', gmdate('Y-m-d H:i'), $synthetic ? ' (synthetic)' : ''),
                ':description' => 'Preview ' . substr($fingerprint, 0, 16) . '. Execute with --fingerprint=' . $fingerprint . ' --approved.',
                ':module' => self::MODULE_CODE,
                ':source' => SourceSnapshotSchema::SOURCE_SYSTEM,
                ':filter' => json_encode(['fingerprint' => $fingerprint, 'snapshotHash' => $snapshotHash, 'synthetic' => $synthetic, 'summary' => $summary], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':status' => self::STATUS_PREVIEW,
                ':actor' => $actor->userId,
                ':total' => is_int($summary['sourceRecords'] ?? null) ? $summary['sourceRecords'] : 0,
            ],
        );
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $this->log($publicId, $actor, 'preview', 'ok', null, self::STATUS_PREVIEW, ['fingerprint' => $fingerprint]);

        return $publicId;
    }

    /** @return array{publicId: string, status: string, fingerprint: string, snapshotHash: string, synthetic: bool} */
    public function batch(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT LOWER(CONVERT(NVARCHAR(36), [PublicId])) AS [PublicId], [StatusCode], [SourceFilterJson] FROM [migration].[LegacyMigrationBatches] WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :id) AND [ModuleCode] = :module',
            [':id' => $batchPublicId, ':module' => self::MODULE_CODE],
        );
        if ($rows === []) {
            throw OnboardingMigrationRefused::because('BATCH_NOT_FOUND', 'no onboarding batch ' . $batchPublicId);
        }
        $filter = json_decode(AzureSqlValueMapper::stringValue($rows[0]['SourceFilterJson'] ?? '{}'), true);
        $filter = is_array($filter) ? $filter : [];

        return [
            'publicId' => AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''),
            'status' => AzureSqlValueMapper::stringValue($rows[0]['StatusCode'] ?? ''),
            'fingerprint' => is_string($filter['fingerprint'] ?? null) ? $filter['fingerprint'] : '',
            'snapshotHash' => is_string($filter['snapshotHash'] ?? null) ? $filter['snapshotHash'] : '',
            'synthetic' => ($filter['synthetic'] ?? false) === true,
        ];
    }

    /**
     * @param list<string> $from
     * @param array<string, mixed> $details
     */
    public function transition(string $batchPublicId, ActorContext $actor, array $from, string $to, string $action, array $details = []): void
    {
        $params = [':to' => $to, ':id' => $batchPublicId, ':actor' => $actor->userId];
        $in = [];
        foreach ($from as $i => $status) {
            $params[':from' . $i] = $status;
            $in[] = ':from' . $i;
        }
        $stamp = match ($to) {
            self::STATUS_EXECUTING => ', [ApprovedAtUtc] = COALESCE([ApprovedAtUtc], SYSUTCDATETIME()), [ApprovedByUserId] = COALESCE([ApprovedByUserId], :actor), [StartedAtUtc] = SYSUTCDATETIME()',
            self::STATUS_COMPLETED => ', [CompletedAtUtc] = SYSUTCDATETIME(), [ApprovedByUserId] = COALESCE([ApprovedByUserId], :actor)',
            self::STATUS_ROLLED_BACK => ', [RolledBackAtUtc] = SYSUTCDATETIME(), [RolledBackByUserId] = :actor',
            default => ', [ApprovedByUserId] = COALESCE([ApprovedByUserId], :actor)',
        };
        $rows = $this->connection->fetchAll(
            sprintf(
                'SET NOCOUNT ON; UPDATE [migration].[LegacyMigrationBatches] SET [StatusCode] = :to%s WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :id) AND [StatusCode] IN (%s); SELECT @@ROWCOUNT AS [Affected];',
                $stamp,
                implode(', ', $in),
            ),
            $params,
        );
        if (AzureSqlValueMapper::intValue($rows[0]['Affected'] ?? 0) !== 1) {
            $current = $this->batch($batchPublicId)['status'];
            throw OnboardingMigrationRefused::because('BATCH_STATE', sprintf('the batch is %s; %s needs it to be %s', $current, $action, implode(' or ', $from)));
        }
        $this->log($batchPublicId, $actor, $action, 'ok', implode('|', $from), $to, $details);
    }

    public function recordCounts(string $batchPublicId, int $imported, int $skipped, int $warnings): void
    {
        $this->connection->execute(
            'UPDATE [migration].[LegacyMigrationBatches] SET [ImportedRecords] = :imported, [SkippedRecords] = :skipped, [WarningRecords] = :warnings WHERE [PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :id)',
            [':imported' => $imported, ':skipped' => $skipped, ':warnings' => $warnings, ':id' => $batchPublicId],
        );
    }

    /** @param array<string, mixed> $details */
    public function log(string $batchPublicId, ActorContext $actor, string $action, string $result, ?string $oldStatus, ?string $newStatus, array $details = []): void
    {
        $this->connection->execute(
            <<<'SQL'
            INSERT INTO [migration].[LegacyMigrationLogs] ([LegacyMigrationBatchId], [ActorUserId], [ActorPublicId], [ActionCode], [ResultCode], [OldStatus], [NewStatus], [DetailsJson])
            SELECT b.[LegacyMigrationBatchId], :actor, :actorPublicId, :action, :result, :old, :new, :details
            FROM [migration].[LegacyMigrationBatches] b WHERE b.[PublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :id)
            SQL,
            [
                ':actor' => $actor->userId,
                ':actorPublicId' => $actor->publicId,
                ':action' => 'onboarding.' . $action,
                ':result' => $result,
                ':old' => $oldStatus,
                ':new' => $newStatus,
                ':details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ':id' => $batchPublicId,
            ],
        );
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param array{table: string, id?: int|null, publicId?: string|null, container?: string|null, path?: string|null} $target
     */
    public function record(string $batchPublicId, int $subjectUserId, string $sourceTable, int $sourceId, string $role, array $target, ?string $sourceHash = null): void
    {
        $this->connection->execute(
            <<<'SQL'
            INSERT INTO [migration].[OnboardingMigrationRows]
                ([BatchPublicId], [SourceSystem], [SourceTable], [SourceId], [SubjectUserId], [TargetRole], [TargetTable], [TargetId], [TargetPublicId], [TargetBlobContainer], [TargetBlobPath], [SourceHash])
            VALUES (TRY_CONVERT(UNIQUEIDENTIFIER, :batch), :system, :sourceTable, :sourceId, :subject, :role, :targetTable, :targetId, TRY_CONVERT(UNIQUEIDENTIFIER, :targetPublicId), :container, :path, :hash)
            SQL,
            [
                ':batch' => $batchPublicId,
                ':system' => SourceSnapshotSchema::SOURCE_SYSTEM,
                ':sourceTable' => $sourceTable,
                ':sourceId' => $sourceId,
                ':subject' => $subjectUserId,
                ':role' => $role,
                ':targetTable' => $target['table'],
                ':targetId' => $target['id'] ?? null,
                ':targetPublicId' => $target['publicId'] ?? null,
                ':container' => $target['container'] ?? null,
                ':path' => $target['path'] ?? null,
                ':hash' => $sourceHash,
            ],
        );
    }

    /**
     * Everything the batch created and has not rolled back, newest first — the
     * order rollback undoes it in.
     *
     * @return list<array{rowId: int, sourceTable: string, sourceId: int, subjectUserId: int, role: string, targetTable: string, targetId: ?int, targetPublicId: ?string, container: ?string, path: ?string, sourceHash: ?string, createdAtUtc: string}>
     */
    public function rows(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
            SELECT [OnboardingMigrationRowId], [SourceTable], [SourceId], [SubjectUserId], [TargetRole], [TargetTable], [TargetId],
                   LOWER(CONVERT(NVARCHAR(36), [TargetPublicId])) AS [TargetPublicId], [TargetBlobContainer], [TargetBlobPath], [SourceHash],
                   CONVERT(VARCHAR(33), [CreatedAtUtc], 127) AS [CreatedAtUtc]
            FROM [migration].[OnboardingMigrationRows]
            WHERE [BatchPublicId] = TRY_CONVERT(UNIQUEIDENTIFIER, :batch) AND [RolledBackAtUtc] IS NULL
            ORDER BY [OnboardingMigrationRowId] DESC
            SQL,
            [':batch' => $batchPublicId],
        );

        return array_map(static fn (array $r): array => [
            'rowId' => AzureSqlValueMapper::intValue($r['OnboardingMigrationRowId'] ?? 0),
            'sourceTable' => AzureSqlValueMapper::stringValue($r['SourceTable'] ?? ''),
            'sourceId' => AzureSqlValueMapper::intValue($r['SourceId'] ?? 0),
            'subjectUserId' => AzureSqlValueMapper::intValue($r['SubjectUserId'] ?? 0),
            'role' => AzureSqlValueMapper::stringValue($r['TargetRole'] ?? ''),
            'targetTable' => AzureSqlValueMapper::stringValue($r['TargetTable'] ?? ''),
            'targetId' => ($r['TargetId'] ?? null) === null ? null : AzureSqlValueMapper::intValue($r['TargetId']),
            'targetPublicId' => AzureSqlValueMapper::nullableString($r['TargetPublicId'] ?? null),
            'container' => AzureSqlValueMapper::nullableString($r['TargetBlobContainer'] ?? null),
            'path' => AzureSqlValueMapper::nullableString($r['TargetBlobPath'] ?? null),
            'sourceHash' => AzureSqlValueMapper::nullableString($r['SourceHash'] ?? null),
            'createdAtUtc' => AzureSqlValueMapper::stringValue($r['CreatedAtUtc'] ?? ''),
        ], $rows);
    }

    /** @param list<int> $rowIds */
    public function markRolledBack(array $rowIds): void
    {
        foreach (array_chunk($rowIds, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $id) {
                $params[':r' . $i] = $id;
            }
            $this->connection->execute(
                sprintf('UPDATE [migration].[OnboardingMigrationRows] SET [RolledBackAtUtc] = SYSUTCDATETIME() WHERE [OnboardingMigrationRowId] IN (%s)', implode(', ', array_keys($params))),
                $params,
            );
        }
    }

    // ------------------------------------------------------------------ lock

    public function acquireLock(ActorContext $actor): void
    {
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationLocks] WHERE [BatchPublicId] = CONVERT(UNIQUEIDENTIFIER, :key) AND [UntilUtc] < SYSUTCDATETIME()',
            [':key' => self::LOCK_KEY],
        );
        try {
            $this->connection->execute(
                'INSERT INTO [migration].[LegacyMigrationLocks] ([BatchPublicId], [OwnerPublicId], [UntilUtc]) VALUES (CONVERT(UNIQUEIDENTIFIER, :key), :owner, DATEADD(MINUTE, :minutes, SYSUTCDATETIME()))',
                [':key' => self::LOCK_KEY, ':owner' => $actor->publicId, ':minutes' => self::LOCK_MINUTES],
            );
        } catch (RuntimeException | \PDOException) {
            throw OnboardingMigrationRefused::because('LOCKED', 'another onboarding migration run holds the lock (it expires after ' . self::LOCK_MINUTES . ' minutes)');
        }
    }

    public function releaseLock(): void
    {
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationLocks] WHERE [BatchPublicId] = CONVERT(UNIQUEIDENTIFIER, :key)',
            [':key' => self::LOCK_KEY],
        );
    }
}
