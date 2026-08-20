<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Support\ConflictDecision;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class AzureSqlLegacyMigrationStore implements LegacyMigrationStore
{
    private const RAW_TABLES = [
        'LegacyUsersRaw' => '[migration].[LegacyUsersRaw]',
        'LegacyUserMetaRaw' => '[migration].[LegacyUserMetaRaw]',
        'LegacyCustomersRaw' => '[migration].[LegacyCustomersRaw]',
        'LegacyContactsRaw' => '[migration].[LegacyContactsRaw]',
        'LegacyEmployeesRaw' => '[migration].[LegacyEmployeesRaw]',
        'LegacyRolesRaw' => '[migration].[LegacyRolesRaw]',
        'LegacyPermissionsRaw' => '[migration].[LegacyPermissionsRaw]',
        'LegacyCertificatesRaw' => '[migration].[LegacyCertificatesRaw]',
        'LegacyQuotesRaw' => '[migration].[LegacyQuotesRaw]',
        'LegacyRfqsRaw' => '[migration].[LegacyRfqsRaw]',
        'LegacyCustomerNotesRaw' => '[migration].[LegacyCustomerNotesRaw]',
    ];

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function listSources(): array
    {
        $this->ensureSources();
        $rows = $this->connection->fetchAll(
            'SELECT [SourceCode], [Label], [Kind], [Health], [Version], [LastSyncAtUtc], [RecordCountsJson], [ErrorsJson], [ConnectionJson] FROM [migration].[LegacyMigrationSources] ORDER BY [SourceCode]',
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateSource($row);
        }

        return $out;
    }

    public function upsertSource(array $row): array
    {
        $this->ensureSources();
        $code = (string) ($row['code'] ?? '');
        $current = $this->getSource($code) ?? [
            'code' => $code,
            'label' => $code,
            'kind' => 'legacy',
            'health' => 'unconfigured',
            'version' => null,
            'lastSyncAtUtc' => null,
            'recordCounts' => [],
            'errors' => [],
            'connection' => ['configured' => false, 'readOnly' => true],
        ];
        $merged = array_merge($current, $row);
        $this->connection->execute(
            <<<'SQL'
UPDATE [migration].[LegacyMigrationSources]
SET [Label] = :label,
    [Kind] = :kind,
    [Health] = :health,
    [Version] = :version,
    [LastSyncAtUtc] = :lastSync,
    [RecordCountsJson] = :counts,
    [ErrorsJson] = :errors,
    [ConnectionJson] = :connection
WHERE [SourceCode] = :code
SQL,
            [
                ':code' => $code,
                ':label' => (string) ($merged['label'] ?? $code),
                ':kind' => (string) ($merged['kind'] ?? 'legacy'),
                ':health' => (string) ($merged['health'] ?? 'unconfigured'),
                ':version' => $merged['version'] ?? null,
                ':lastSync' => $this->sqlDate($merged['lastSyncAtUtc'] ?? null),
                ':counts' => $this->json($merged['recordCounts'] ?? []),
                ':errors' => $this->json($merged['errors'] ?? []),
                ':connection' => $this->json($merged['connection'] ?? []),
            ],
        );

        return $this->getSource($code) ?? $merged;
    }

    public function getSource(string $code): ?array
    {
        $this->ensureSources();
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 [SourceCode], [Label], [Kind], [Health], [Version], [LastSyncAtUtc], [RecordCountsJson], [ErrorsJson], [ConnectionJson] FROM [migration].[LegacyMigrationSources] WHERE [SourceCode] = :code',
            [':code' => $code],
        );

        return $rows === [] ? null : $this->hydrateSource($rows[0]);
    }

    public function createBatch(array $data): array
    {
        $publicId = $this->uuids->generate()->toString();
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[LegacyMigrationBatches] (
    [PublicId], [Name], [Description], [ModuleCode], [SourceSystem], [SourceFilterJson], [StatusCode], [CreatedByUserId]
) VALUES (
    :publicId, :name, :description, :moduleCode, :sourceSystem, :sourceFilter, :statusCode, :createdByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':name' => (string) $data['name'],
                ':description' => $data['description'] ?? null,
                ':moduleCode' => (string) $data['moduleCode'],
                ':sourceSystem' => (string) $data['sourceSystem'],
                ':sourceFilter' => isset($data['sourceFilter']) ? $this->json($data['sourceFilter']) : null,
                ':statusCode' => (string) ($data['statusCode'] ?? 'created'),
                ':createdByUserId' => (int) $data['createdByUserId'],
            ],
        );

        return $this->getBatch($publicId) ?? ['publicId' => $publicId];
    }

    public function getBatch(string $publicId): ?array
    {
        $rows = $this->connection->fetchAll($this->batchSelect() . ' WHERE b.[PublicId] = :publicId', [':publicId' => $publicId]);

        return $rows === [] ? null : $this->hydrateBatch($rows[0]);
    }

    public function listBatches(?string $statusCode = null, ?string $moduleCode = null): array
    {
        $sql = $this->batchSelect() . ' WHERE 1=1';
        $params = [];
        if ($statusCode !== null) {
            $sql .= ' AND b.[StatusCode] = :statusCode';
            $params[':statusCode'] = $statusCode;
        }
        if ($moduleCode !== null) {
            $sql .= ' AND b.[ModuleCode] = :moduleCode';
            $params[':moduleCode'] = $moduleCode;
        }
        $sql .= ' ORDER BY b.[CreatedAtUtc] DESC';
        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = $this->hydrateBatch($row);
        }

        return $out;
    }

    public function updateBatch(string $publicId, array $patch): array
    {
        $batchId = $this->requireBatchId($publicId);
        $map = [
            'name' => 'Name',
            'description' => 'Description',
            'statusCode' => 'StatusCode',
            'approvedAtUtc' => 'ApprovedAtUtc',
            'approvedByUserId' => 'ApprovedByUserId',
            'startedAtUtc' => 'StartedAtUtc',
            'completedAtUtc' => 'CompletedAtUtc',
            'rolledBackAtUtc' => 'RolledBackAtUtc',
            'rolledBackByUserId' => 'RolledBackByUserId',
            'totalRecords' => 'TotalRecords',
            'importedRecords' => 'ImportedRecords',
            'updatedRecords' => 'UpdatedRecords',
            'skippedRecords' => 'SkippedRecords',
            'warningRecords' => 'WarningRecords',
            'failedRecords' => 'FailedRecords',
            'manualReviewRecords' => 'ManualReviewRecords',
        ];
        $sets = [];
        $params = [':batchId' => $batchId];
        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $patch)) {
                continue;
            }
            $sets[] = "[{$col}] = :{$key}";
            $value = $patch[$key];
            if (str_ends_with($key, 'Utc')) {
                $value = $this->sqlDate($value);
            }
            $params[':' . $key] = $value;
        }
        if ($sets !== []) {
            $this->connection->execute(
                'UPDATE [migration].[LegacyMigrationBatches] SET ' . implode(', ', $sets) . ' WHERE [LegacyMigrationBatchId] = :batchId',
                $params,
            );
        }

        return $this->getBatch($publicId) ?? [];
    }

    public function replaceItems(string $batchPublicId, array $items): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationBatchItems] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );
        foreach ($items as $item) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[LegacyMigrationBatchItems] (
    [PublicId], [LegacyMigrationBatchId], [SourceIdentifier], [StatusCode], [PayloadJson], [CanonicalJson], [SourceHash]
) VALUES (
    :publicId, :batchId, :sourceIdentifier, :statusCode, :payload, :canonical, :hash
)
SQL,
                [
                    ':publicId' => (string) ($item['publicId'] ?? $this->uuids->generate()->toString()),
                    ':batchId' => $batchId,
                    ':sourceIdentifier' => (string) ($item['sourceIdentifier'] ?? ''),
                    ':statusCode' => (string) ($item['statusCode'] ?? 'extracted'),
                    ':payload' => $this->json($item['rawPayload'] ?? $item['payload'] ?? null),
                    ':canonical' => $this->json($item['canonical'] ?? null),
                    ':hash' => $item['sourceHash'] ?? null,
                ],
            );
        }
    }

    public function listItems(string $batchPublicId): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $rows = $this->connection->fetchAll(
            'SELECT [PublicId], [SourceIdentifier], [StatusCode], [PayloadJson], [CanonicalJson], [SourceHash] FROM [migration].[LegacyMigrationBatchItems] WHERE [LegacyMigrationBatchId] = :batchId ORDER BY [ItemId]',
            [':batchId' => $batchId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'batchPublicId' => $batchPublicId,
                'sourceIdentifier' => AzureSqlValueMapper::stringValue($row['SourceIdentifier'] ?? ''),
                'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                'rawPayload' => $this->assoc($row['PayloadJson'] ?? null),
                'canonical' => $this->assoc($row['CanonicalJson'] ?? null),
                'sourceHash' => AzureSqlValueMapper::nullableString($row['SourceHash'] ?? null),
            ];
        }

        return $out;
    }

    public function updateItem(string $itemPublicId, array $patch): void
    {
        $sets = [];
        $params = [':publicId' => $itemPublicId];
        if (isset($patch['statusCode'])) {
            $sets[] = '[StatusCode] = :statusCode';
            $params[':statusCode'] = (string) $patch['statusCode'];
        }
        if (array_key_exists('canonical', $patch)) {
            $sets[] = '[CanonicalJson] = :canonical';
            $params[':canonical'] = $this->json($patch['canonical']);
        }
        if (array_key_exists('rawPayload', $patch)) {
            $sets[] = '[PayloadJson] = :payload';
            $params[':payload'] = $this->json($patch['rawPayload']);
        }
        if ($sets === []) {
            return;
        }
        $this->connection->execute(
            'UPDATE [migration].[LegacyMigrationBatchItems] SET ' . implode(', ', $sets) . ' WHERE [PublicId] = :publicId',
            $params,
        );
    }

    public function addLog(array $row): void
    {
        $batchId = isset($row['batchPublicId']) && is_string($row['batchPublicId'])
            ? $this->findBatchId($row['batchPublicId'])
            : null;
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[LegacyMigrationLogs] (
    [LegacyMigrationBatchId], [ActorUserId], [ActorPublicId], [IpAddress], [ActionCode], [ResultCode],
    [OldStatus], [NewStatus], [DurationMs], [DetailsJson], [OccurredAtUtc]
) VALUES (
    :batchId, :actorUserId, :actorPublicId, :ipAddress, :actionCode, :resultCode,
    :oldStatus, :newStatus, :durationMs, :details, :occurredAt
)
SQL,
            [
                ':batchId' => $batchId,
                ':actorUserId' => $row['actorUserId'] ?? null,
                ':actorPublicId' => $row['actorPublicId'] ?? null,
                ':ipAddress' => $row['ipAddress'] ?? null,
                ':actionCode' => (string) ($row['actionCode'] ?? ''),
                ':resultCode' => (string) ($row['resultCode'] ?? 'ok'),
                ':oldStatus' => $row['oldStatus'] ?? null,
                ':newStatus' => $row['newStatus'] ?? null,
                ':durationMs' => (int) ($row['durationMs'] ?? 0),
                ':details' => $this->json($row['details'] ?? null),
                ':occurredAt' => $this->sqlDate($row['occurredAtUtc'] ?? gmdate('Y-m-d H:i:s')),
            ],
        );
    }

    public function listLogs(?string $batchPublicId = null, int $limit = 200): array
    {
        $sql = 'SELECT TOP (' . max(1, $limit) . ') [PublicId], [LegacyMigrationBatchId], [ActorUserId], [ActorPublicId], [IpAddress], [ActionCode], [ResultCode], [OldStatus], [NewStatus], [DurationMs], [DetailsJson], [OccurredAtUtc] FROM [migration].[LegacyMigrationLogs]';
        $params = [];
        if ($batchPublicId !== null) {
            $sql .= ' WHERE [LegacyMigrationBatchId] = :batchId';
            $params[':batchId'] = $this->requireBatchId($batchPublicId);
        }
        $sql .= ' ORDER BY [OccurredAtUtc] DESC';
        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'batchPublicId' => $batchPublicId,
                'actorUserId' => AzureSqlValueMapper::nullableInt($row['ActorUserId'] ?? null),
                'actorPublicId' => AzureSqlValueMapper::nullableString($row['ActorPublicId'] ?? null),
                'ipAddress' => AzureSqlValueMapper::nullableString($row['IpAddress'] ?? null),
                'actionCode' => AzureSqlValueMapper::stringValue($row['ActionCode'] ?? ''),
                'resultCode' => AzureSqlValueMapper::stringValue($row['ResultCode'] ?? ''),
                'oldStatus' => AzureSqlValueMapper::nullableString($row['OldStatus'] ?? null),
                'newStatus' => AzureSqlValueMapper::nullableString($row['NewStatus'] ?? null),
                'durationMs' => AzureSqlValueMapper::intValue($row['DurationMs'] ?? 0),
                'details' => $this->assoc($row['DetailsJson'] ?? null),
                'occurredAtUtc' => $this->iso($row['OccurredAtUtc'] ?? null),
            ];
        }

        return $out;
    }

    public function replaceConflicts(string $batchPublicId, array $conflicts): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationConflicts] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );
        $hasStatus = $this->hasConflictStatusColumn();
        foreach ($conflicts as $conflict) {
            $sql = $hasStatus
                ? 'INSERT INTO [migration].[LegacyMigrationConflicts] ([PublicId], [LegacyMigrationBatchId], [ConflictType], [Description], [SourceIdentifier], [DecisionCode], [ManualMapping], [ResolvedByUserId], [ResolvedAtUtc], [StatusCode]) VALUES (:publicId, :batchId, :type, :description, :sourceIdentifier, :decision, :mapping, :resolvedBy, :resolvedAt, :statusCode)'
                : 'INSERT INTO [migration].[LegacyMigrationConflicts] ([PublicId], [LegacyMigrationBatchId], [ConflictType], [Description], [SourceIdentifier], [DecisionCode], [ManualMapping], [ResolvedByUserId], [ResolvedAtUtc]) VALUES (:publicId, :batchId, :type, :description, :sourceIdentifier, :decision, :mapping, :resolvedBy, :resolvedAt)';
            $params = [
                ':publicId' => (string) ($conflict['publicId'] ?? $this->uuids->generate()->toString()),
                ':batchId' => $batchId,
                ':type' => (string) ($conflict['conflictType'] ?? $conflict['errorCode'] ?? 'conflict'),
                ':description' => (string) ($conflict['description'] ?? ''),
                ':sourceIdentifier' => $conflict['sourceIdentifier'] ?? null,
                ':decision' => $conflict['decisionCode'] ?? null,
                ':mapping' => $conflict['manualMapping'] ?? null,
                ':resolvedBy' => $conflict['resolvedByUserId'] ?? null,
                ':resolvedAt' => $this->sqlDate($conflict['resolvedAtUtc'] ?? null),
            ];
            if ($hasStatus) {
                $params[':statusCode'] = (string) ($conflict['statusCode'] ?? ConflictDecision::statusForDecision($conflict['decisionCode'] ?? null));
            }
            $this->connection->execute($sql, $params);
        }
    }

    public function updateConflict(string $publicId, array $patch): void
    {
        $sets = [];
        $params = [':publicId' => $publicId];
        foreach ([
            'decisionCode' => 'DecisionCode',
            'manualMapping' => 'ManualMapping',
            'resolvedByUserId' => 'ResolvedByUserId',
            'resolvedAtUtc' => 'ResolvedAtUtc',
            'statusCode' => 'StatusCode',
        ] as $key => $col) {
            if (!array_key_exists($key, $patch)) {
                continue;
            }
            if ($key === 'statusCode' && !$this->hasConflictStatusColumn()) {
                continue;
            }
            $sets[] = "[{$col}] = :{$key}";
            $params[':' . $key] = str_ends_with($key, 'Utc') ? $this->sqlDate($patch[$key]) : $patch[$key];
        }
        if ($sets === []) {
            return;
        }
        $this->connection->execute(
            'UPDATE [migration].[LegacyMigrationConflicts] SET ' . implode(', ', $sets) . ' WHERE [PublicId] = :publicId',
            $params,
        );
    }

    public function listConflicts(?string $batchPublicId = null): array
    {
        $sql = 'SELECT c.[PublicId], b.[PublicId] AS [BatchPublicId], c.[ConflictType], c.[Description], c.[SourceIdentifier], c.[DecisionCode], c.[ManualMapping], c.[ResolvedByUserId], c.[ResolvedAtUtc]';
        if ($this->hasConflictStatusColumn()) {
            $sql .= ', c.[StatusCode]';
        }
        $sql .= ' FROM [migration].[LegacyMigrationConflicts] c INNER JOIN [migration].[LegacyMigrationBatches] b ON b.[LegacyMigrationBatchId] = c.[LegacyMigrationBatchId]';
        $params = [];
        if ($batchPublicId !== null) {
            $sql .= ' WHERE b.[PublicId] = :batchPublicId';
            $params[':batchPublicId'] = $batchPublicId;
        }
        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'batchPublicId' => AzureSqlValueMapper::stringValue($row['BatchPublicId'] ?? ''),
                'conflictType' => AzureSqlValueMapper::stringValue($row['ConflictType'] ?? ''),
                'description' => AzureSqlValueMapper::stringValue($row['Description'] ?? ''),
                'sourceIdentifier' => AzureSqlValueMapper::nullableString($row['SourceIdentifier'] ?? null),
                'decisionCode' => AzureSqlValueMapper::nullableString($row['DecisionCode'] ?? null),
                'manualMapping' => AzureSqlValueMapper::nullableString($row['ManualMapping'] ?? null),
                'resolvedByUserId' => AzureSqlValueMapper::nullableInt($row['ResolvedByUserId'] ?? null),
                'resolvedAtUtc' => $this->iso($row['ResolvedAtUtc'] ?? null),
                'statusCode' => AzureSqlValueMapper::nullableString($row['StatusCode'] ?? null)
                    ?? ConflictDecision::statusForDecision(AzureSqlValueMapper::nullableString($row['DecisionCode'] ?? null)),
            ];
        }

        return $out;
    }

    public function replaceWarnings(string $batchPublicId, array $warnings): void
    {
        $this->replaceJsonRows('[migration].[LegacyMigrationWarnings]', $batchPublicId, $warnings);
    }

    public function listWarnings(string $batchPublicId): array
    {
        return $this->listJsonRows('[migration].[LegacyMigrationWarnings]', $batchPublicId);
    }

    public function replaceValidation(string $batchPublicId, array $results): void
    {
        $this->replaceJsonRows('[migration].[LegacyMigrationValidationResults]', $batchPublicId, $results);
    }

    public function listValidation(string $batchPublicId): array
    {
        return $this->listJsonRows('[migration].[LegacyMigrationValidationResults]', $batchPublicId);
    }

    public function saveReport(array $row): array
    {
        $publicId = $this->uuids->generate()->toString();
        $batchId = isset($row['batchPublicId']) ? $this->findBatchId((string) $row['batchPublicId']) : null;
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationReports] ([PublicId], [LegacyMigrationBatchId], [ReportType], [PayloadJson]) VALUES (:publicId, :batchId, :type, :payload)',
            [
                ':publicId' => $publicId,
                ':batchId' => $batchId,
                ':type' => (string) ($row['reportType'] ?? 'preview'),
                ':payload' => $this->json($row['payload'] ?? []),
            ],
        );
        $row['publicId'] = $publicId;
        $row['createdAtUtc'] = gmdate('Y-m-d\TH:i:s\Z');

        return $row;
    }

    public function listReports(?string $batchPublicId = null): array
    {
        $sql = 'SELECT r.[PublicId], b.[PublicId] AS [BatchPublicId], r.[ReportType], r.[PayloadJson], r.[CreatedAtUtc] FROM [migration].[LegacyMigrationReports] r LEFT JOIN [migration].[LegacyMigrationBatches] b ON b.[LegacyMigrationBatchId] = r.[LegacyMigrationBatchId]';
        $params = [];
        if ($batchPublicId !== null) {
            $sql .= ' WHERE b.[PublicId] = :batchPublicId';
            $params[':batchPublicId'] = $batchPublicId;
        }
        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'batchPublicId' => AzureSqlValueMapper::nullableString($row['BatchPublicId'] ?? null),
                'reportType' => AzureSqlValueMapper::stringValue($row['ReportType'] ?? ''),
                'payload' => $this->assoc($row['PayloadJson'] ?? null),
                'createdAtUtc' => $this->iso($row['CreatedAtUtc'] ?? null),
            ];
        }

        return $out;
    }

    public function addRollback(array $row): void
    {
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationRollbackHistory] ([LegacyMigrationBatchId], [RemovedSnapshots], [ActorUserId], [Scope]) VALUES (:batchId, :removed, :actor, :scope)',
            [
                ':batchId' => $this->requireBatchId((string) $row['batchPublicId']),
                ':removed' => (int) ($row['removedSnapshots'] ?? 0),
                ':actor' => (int) ($row['actorUserId'] ?? 0),
                ':scope' => (string) ($row['scope'] ?? 'single-batch'),
            ],
        );
    }

    public function listRollback(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [PublicId], [RemovedSnapshots], [ActorUserId], [Scope], [CreatedAtUtc] FROM [migration].[LegacyMigrationRollbackHistory] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $this->requireBatchId($batchPublicId)],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'removedSnapshots' => AzureSqlValueMapper::intValue($row['RemovedSnapshots'] ?? 0),
                'actorUserId' => AzureSqlValueMapper::intValue($row['ActorUserId'] ?? 0),
                'scope' => AzureSqlValueMapper::stringValue($row['Scope'] ?? ''),
                'createdAtUtc' => $this->iso($row['CreatedAtUtc'] ?? null),
            ];
        }

        return $out;
    }

    public function addJob(array $row): array
    {
        $publicId = $this->uuids->generate()->toString();
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationJobs] ([PublicId], [LegacyMigrationBatchId], [TypeCode], [StatusCode]) VALUES (:publicId, :batchId, :type, :status)',
            [
                ':publicId' => $publicId,
                ':batchId' => isset($row['batchPublicId']) ? $this->findBatchId((string) $row['batchPublicId']) : null,
                ':type' => (string) ($row['typeCode'] ?? 'full-sync'),
                ':status' => (string) ($row['statusCode'] ?? 'running'),
            ],
        );

        return ['publicId' => $publicId] + $row;
    }

    public function updateJob(string $publicId, array $patch): array
    {
        if (isset($patch['statusCode'])) {
            $this->connection->execute(
                'UPDATE [migration].[LegacyMigrationJobs] SET [StatusCode] = :status WHERE [PublicId] = :publicId',
                [':status' => (string) $patch['statusCode'], ':publicId' => $publicId],
            );
        }

        return ['publicId' => $publicId] + $patch;
    }

    public function listJobs(?string $batchPublicId = null): array
    {
        $sql = 'SELECT j.[PublicId], b.[PublicId] AS [BatchPublicId], j.[TypeCode], j.[StatusCode], j.[CreatedAtUtc] FROM [migration].[LegacyMigrationJobs] j LEFT JOIN [migration].[LegacyMigrationBatches] b ON b.[LegacyMigrationBatchId] = j.[LegacyMigrationBatchId]';
        $params = [];
        if ($batchPublicId !== null) {
            $sql .= ' WHERE b.[PublicId] = :batchPublicId';
            $params[':batchPublicId'] = $batchPublicId;
        }
        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'batchPublicId' => AzureSqlValueMapper::nullableString($row['BatchPublicId'] ?? null),
                'typeCode' => AzureSqlValueMapper::stringValue($row['TypeCode'] ?? ''),
                'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                'createdAtUtc' => $this->iso($row['CreatedAtUtc'] ?? null),
            ];
        }

        return $out;
    }

    public function addExecution(array $row): void
    {
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationExecutionHistory] ([LegacyMigrationBatchId], [LegacyId], [ActionCode], [ResultCode]) VALUES (:batchId, :legacyId, :action, :result)',
            [
                ':batchId' => $this->requireBatchId((string) $row['batchPublicId']),
                ':legacyId' => (string) ($row['legacyId'] ?? ''),
                ':action' => (string) ($row['actionCode'] ?? ''),
                ':result' => (string) ($row['resultCode'] ?? 'ok'),
            ],
        );
    }

    public function listExecution(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [LegacyId], [ActionCode], [ResultCode] FROM [migration].[LegacyMigrationExecutionHistory] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $this->requireBatchId($batchPublicId)],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'legacyId' => AzureSqlValueMapper::stringValue($row['LegacyId'] ?? ''),
                'actionCode' => AzureSqlValueMapper::stringValue($row['ActionCode'] ?? ''),
                'resultCode' => AzureSqlValueMapper::stringValue($row['ResultCode'] ?? ''),
            ];
        }

        return $out;
    }

    public function saveSnapshot(array $row): void
    {
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationSnapshots] ([LegacyMigrationBatchId], [ModuleCode], [LegacyId], [PayloadJson]) VALUES (:batchId, :module, :legacyId, :payload)',
            [
                ':batchId' => $this->requireBatchId((string) $row['batchPublicId']),
                ':module' => (string) ($row['moduleCode'] ?? ''),
                ':legacyId' => (string) ($row['legacyId'] ?? ''),
                ':payload' => $this->json($row['payload'] ?? []),
            ],
        );
    }

    public function listSnapshots(string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [PublicId], [ModuleCode], [LegacyId], [PayloadJson] FROM [migration].[LegacyMigrationSnapshots] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $this->requireBatchId($batchPublicId)],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'moduleCode' => AzureSqlValueMapper::stringValue($row['ModuleCode'] ?? ''),
                'legacyId' => AzureSqlValueMapper::stringValue($row['LegacyId'] ?? ''),
                'payload' => $this->assoc($row['PayloadJson'] ?? null),
            ];
        }

        return $out;
    }

    public function deleteSnapshots(string $batchPublicId): int
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $countRows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [Cnt] FROM [migration].[LegacyMigrationSnapshots] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );
        $count = AzureSqlValueMapper::intValue($countRows[0]['Cnt'] ?? 0);
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationSnapshots] WHERE [LegacyMigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );

        return $count;
    }

    public function tryLock(string $batchPublicId, string $owner, int $ttlSeconds): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT [OwnerPublicId], [UntilUtc] FROM [migration].[LegacyMigrationLocks] WHERE [BatchPublicId] = :id',
            [':id' => $batchPublicId],
        );
        $now = time();
        if ($rows !== []) {
            $until = AzureSqlValueMapper::nullableDateTime($rows[0]['UntilUtc'] ?? null);
            $existingOwner = AzureSqlValueMapper::stringValue($rows[0]['OwnerPublicId'] ?? '');
            if ($until !== null && $until->getTimestamp() > $now && $existingOwner !== $owner) {
                return false;
            }
            $this->connection->execute(
                'UPDATE [migration].[LegacyMigrationLocks] SET [OwnerPublicId] = :owner, [UntilUtc] = :until WHERE [BatchPublicId] = :id',
                [
                    ':owner' => $owner,
                    ':until' => gmdate('Y-m-d H:i:s', $now + $ttlSeconds),
                    ':id' => $batchPublicId,
                ],
            );

            return true;
        }
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationLocks] ([BatchPublicId], [OwnerPublicId], [UntilUtc]) VALUES (:id, :owner, :until)',
            [
                ':id' => $batchPublicId,
                ':owner' => $owner,
                ':until' => gmdate('Y-m-d H:i:s', $now + $ttlSeconds),
            ],
        );

        return true;
    }

    public function releaseLock(string $batchPublicId, string $owner): void
    {
        $this->connection->execute(
            'DELETE FROM [migration].[LegacyMigrationLocks] WHERE [BatchPublicId] = :id AND [OwnerPublicId] = :owner',
            [':id' => $batchPublicId, ':owner' => $owner],
        );
    }

    public function recordStatistic(array $row): void
    {
        $this->connection->execute(
            'INSERT INTO [migration].[LegacyMigrationStatistics] ([LegacyMigrationBatchId], [Success], [DurationMs], [RecordCount]) VALUES (:batchId, :success, :duration, :count)',
            [
                ':batchId' => isset($row['batchPublicId']) ? $this->findBatchId((string) $row['batchPublicId']) : null,
                ':success' => !empty($row['success']) ? 1 : 0,
                ':duration' => (int) ($row['durationMs'] ?? 0),
                ':count' => (int) ($row['recordCount'] ?? 0),
            ],
        );
    }

    public function statisticsOverview(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [Success], [DurationMs], [RecordCount] FROM [migration].[LegacyMigrationStatistics]',
        );
        $success = 0;
        $fail = 0;
        $duration = 0;
        $volume = 0;
        foreach ($rows as $row) {
            if (AzureSqlValueMapper::intValue($row['Success'] ?? 0) === 1) {
                ++$success;
            } else {
                ++$fail;
            }
            $duration += AzureSqlValueMapper::intValue($row['DurationMs'] ?? 0);
            $volume += AzureSqlValueMapper::intValue($row['RecordCount'] ?? 0);
        }
        $n = max(1, $success + $fail);

        return [
            'successRate' => $success === 0 && $fail === 0 ? 0 : (int) round(100 * $success / $n),
            'averageExecutionMs' => (int) round($duration / max(1, count($rows))),
            'dataVolume' => $volume,
            'executions' => count($rows),
        ];
    }

    public function getSetting(string $key, ?string $default = null): ?string
    {
        $this->ensureSettings();
        $rows = $this->connection->fetchAll(
            'SELECT [SettingValue] FROM [migration].[LegacyMigrationSettings] WHERE [SettingKey] = :key',
            [':key' => $key],
        );

        return $rows === [] ? $default : AzureSqlValueMapper::nullableString($rows[0]['SettingValue'] ?? null) ?? $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $existing = $this->getSetting($key);
        if ($existing === null && $this->connection->fetchAll(
            'SELECT [SettingKey] FROM [migration].[LegacyMigrationSettings] WHERE [SettingKey] = :key',
            [':key' => $key],
        ) === []) {
            $this->connection->execute(
                'INSERT INTO [migration].[LegacyMigrationSettings] ([SettingKey], [SettingValue]) VALUES (:key, :value)',
                [':key' => $key, ':value' => $value],
            );

            return;
        }
        $this->connection->execute(
            'UPDATE [migration].[LegacyMigrationSettings] SET [SettingValue] = :value WHERE [SettingKey] = :key',
            [':key' => $key, ':value' => $value],
        );
    }

    public function allSettings(): array
    {
        $this->ensureSettings();
        $out = [];
        foreach ($this->connection->fetchAll('SELECT [SettingKey], [SettingValue] FROM [migration].[LegacyMigrationSettings]') as $row) {
            $out[AzureSqlValueMapper::stringValue($row['SettingKey'] ?? '')] = AzureSqlValueMapper::stringValue($row['SettingValue'] ?? '');
        }

        return $out;
    }

    public function upsertRaw(string $tableCode, array $row): void
    {
        $table = self::RAW_TABLES[$tableCode] ?? self::RAW_TABLES['LegacyUsersRaw'];
        $batchId = $this->requireBatchId((string) $row['batchPublicId']);
        $legacyId = (string) $row['legacyId'];
        $existing = $this->connection->fetchAll(
            "SELECT TOP 1 [RawId] FROM {$table} WHERE [LegacyMigrationBatchId] = :batchId AND [LegacyId] = :legacyId",
            [':batchId' => $batchId, ':legacyId' => $legacyId],
        );
        $params = [
            ':legacyId' => $legacyId,
            ':sourceSystem' => (string) ($row['sourceSystem'] ?? ''),
            ':extractedAt' => $this->sqlDate($row['extractedAtUtc'] ?? gmdate('Y-m-d H:i:s')),
            ':batchId' => $batchId,
            ':json' => $this->json($row['originalJson'] ?? []),
            ':hash' => (string) ($row['hash'] ?? ''),
            ':status' => (string) ($row['statusCode'] ?? 'extracted'),
        ];
        if ($existing !== []) {
            $this->connection->execute(
                "UPDATE {$table} SET [SourceSystem] = :sourceSystem, [ExtractedAtUtc] = :extractedAt, [OriginalJson] = :json, [Hash] = :hash, [StatusCode] = :status WHERE [LegacyMigrationBatchId] = :batchId AND [LegacyId] = :legacyId",
                $params,
            );

            return;
        }
        $this->connection->execute(
            "INSERT INTO {$table} ([LegacyId], [SourceSystem], [ExtractedAtUtc], [LegacyMigrationBatchId], [OriginalJson], [Hash], [StatusCode]) VALUES (:legacyId, :sourceSystem, :extractedAt, :batchId, :json, :hash, :status)",
            $params,
        );
    }

    public function listRaw(string $tableCode, string $batchPublicId): array
    {
        $table = self::RAW_TABLES[$tableCode] ?? self::RAW_TABLES['LegacyUsersRaw'];
        $rows = $this->connection->fetchAll(
            "SELECT [LegacyId], [SourceSystem], [ExtractedAtUtc], [OriginalJson], [Hash], [StatusCode] FROM {$table} WHERE [LegacyMigrationBatchId] = :batchId",
            [':batchId' => $this->requireBatchId($batchPublicId)],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'batchPublicId' => $batchPublicId,
                'legacyId' => AzureSqlValueMapper::stringValue($row['LegacyId'] ?? ''),
                'sourceSystem' => AzureSqlValueMapper::stringValue($row['SourceSystem'] ?? ''),
                'extractedAtUtc' => $this->iso($row['ExtractedAtUtc'] ?? null),
                'originalJson' => $this->assoc($row['OriginalJson'] ?? null),
                'hash' => AzureSqlValueMapper::stringValue($row['Hash'] ?? ''),
                'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            ];
        }

        return $out;
    }

    private function ensureSources(): void
    {
        $count = $this->connection->fetchAll('SELECT COUNT(*) AS [Cnt] FROM [migration].[LegacyMigrationSources]');
        if (AzureSqlValueMapper::intValue($count[0]['Cnt'] ?? 0) > 0) {
            return;
        }
        foreach (SourceCatalog::sources() as $source) {
            $this->connection->execute(
                'INSERT INTO [migration].[LegacyMigrationSources] ([SourceCode], [Label], [Kind], [Health], [ConnectionJson]) VALUES (:code, :label, :kind, :health, :connection)',
                [
                    ':code' => $source['code'],
                    ':label' => $source['label'],
                    ':kind' => $source['kind'],
                    ':health' => $source['kind'] === 'harness' ? 'healthy' : 'unconfigured',
                    ':connection' => $this->json(['configured' => false, 'readOnly' => true]),
                ],
            );
        }
    }

    private function ensureSettings(): void
    {
        $count = $this->connection->fetchAll('SELECT COUNT(*) AS [Cnt] FROM [migration].[LegacyMigrationSettings]');
        if (AzureSqlValueMapper::intValue($count[0]['Cnt'] ?? 0) > 0) {
            return;
        }
        foreach ([
            'allowFixtureExtract' => 'true',
            'requireApproval' => 'true',
            'refuseProductionWrites' => 'true',
        ] as $key => $value) {
            $this->connection->execute(
                'INSERT INTO [migration].[LegacyMigrationSettings] ([SettingKey], [SettingValue]) VALUES (:key, :value)',
                [':key' => $key, ':value' => $value],
            );
        }
    }

    private function hasConflictStatusColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $rows = $this->connection->fetchAll(
            "SELECT COL_LENGTH(N'migration.LegacyMigrationConflicts', N'StatusCode') AS [Len]",
        );
        $cached = AzureSqlValueMapper::intValue($rows[0]['Len'] ?? 0) > 0;

        return $cached;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function replaceJsonRows(string $table, string $batchPublicId, array $rows): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $this->connection->execute("DELETE FROM {$table} WHERE [LegacyMigrationBatchId] = :batchId", [':batchId' => $batchId]);
        $this->connection->execute(
            "INSERT INTO {$table} ([LegacyMigrationBatchId], [PayloadJson]) VALUES (:batchId, :payload)",
            [':batchId' => $batchId, ':payload' => $this->json($rows)],
        );
    }

    /** @return list<array<string, mixed>> */
    private function listJsonRows(string $table, string $batchPublicId): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT [PayloadJson] FROM {$table} WHERE [LegacyMigrationBatchId] = :batchId",
            [':batchId' => $this->requireBatchId($batchPublicId)],
        );
        if ($rows === []) {
            return [];
        }
        $decoded = json_decode(AzureSqlValueMapper::stringValue($rows[0]['PayloadJson'] ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function batchSelect(): string
    {
        return <<<'SQL'
SELECT b.[PublicId], b.[Name], b.[Description], b.[ModuleCode], b.[SourceSystem], b.[SourceFilterJson], b.[StatusCode],
       b.[CreatedAtUtc], b.[CreatedByUserId], b.[ApprovedAtUtc], b.[ApprovedByUserId], b.[StartedAtUtc], b.[CompletedAtUtc],
       b.[RolledBackAtUtc], b.[RolledBackByUserId], b.[TotalRecords], b.[ImportedRecords], b.[UpdatedRecords],
       b.[SkippedRecords], b.[WarningRecords], b.[FailedRecords], b.[ManualReviewRecords], CAST(b.[RowVersion] AS VARBINARY(8)) AS [RowVersion]
FROM [migration].[LegacyMigrationBatches] b
SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrateBatch(array $row): array
    {
        return [
            'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            'name' => AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            'description' => AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            'moduleCode' => AzureSqlValueMapper::stringValue($row['ModuleCode'] ?? ''),
            'sourceSystem' => AzureSqlValueMapper::stringValue($row['SourceSystem'] ?? ''),
            'sourceFilter' => $this->assoc($row['SourceFilterJson'] ?? null) ?: null,
            'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            'createdAtUtc' => $this->iso($row['CreatedAtUtc'] ?? null),
            'createdByUserId' => AzureSqlValueMapper::intValue($row['CreatedByUserId'] ?? 0),
            'approvedAtUtc' => $this->iso($row['ApprovedAtUtc'] ?? null),
            'approvedByUserId' => AzureSqlValueMapper::nullableInt($row['ApprovedByUserId'] ?? null),
            'startedAtUtc' => $this->iso($row['StartedAtUtc'] ?? null),
            'completedAtUtc' => $this->iso($row['CompletedAtUtc'] ?? null),
            'rolledBackAtUtc' => $this->iso($row['RolledBackAtUtc'] ?? null),
            'rolledBackByUserId' => AzureSqlValueMapper::nullableInt($row['RolledBackByUserId'] ?? null),
            'totalRecords' => AzureSqlValueMapper::intValue($row['TotalRecords'] ?? 0),
            'importedRecords' => AzureSqlValueMapper::intValue($row['ImportedRecords'] ?? 0),
            'updatedRecords' => AzureSqlValueMapper::intValue($row['UpdatedRecords'] ?? 0),
            'skippedRecords' => AzureSqlValueMapper::intValue($row['SkippedRecords'] ?? 0),
            'warningRecords' => AzureSqlValueMapper::intValue($row['WarningRecords'] ?? 0),
            'failedRecords' => AzureSqlValueMapper::intValue($row['FailedRecords'] ?? 0),
            'manualReviewRecords' => AzureSqlValueMapper::intValue($row['ManualReviewRecords'] ?? 0),
            'rowVersion' => bin2hex((string) ($row['RowVersion'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrateSource(array $row): array
    {
        return [
            'code' => AzureSqlValueMapper::stringValue($row['SourceCode'] ?? ''),
            'label' => AzureSqlValueMapper::stringValue($row['Label'] ?? ''),
            'kind' => AzureSqlValueMapper::stringValue($row['Kind'] ?? ''),
            'health' => AzureSqlValueMapper::stringValue($row['Health'] ?? ''),
            'version' => AzureSqlValueMapper::nullableString($row['Version'] ?? null),
            'lastSyncAtUtc' => $this->iso($row['LastSyncAtUtc'] ?? null),
            'recordCounts' => $this->assoc($row['RecordCountsJson'] ?? null),
            'errors' => $this->assoc($row['ErrorsJson'] ?? null) ?: [],
            'connection' => $this->assoc($row['ConnectionJson'] ?? null),
        ];
    }

    private function requireBatchId(string $publicId): int
    {
        $id = $this->findBatchId($publicId);
        if ($id === null) {
            throw new \InvalidArgumentException('Batch not found.');
        }

        return $id;
    }

    private function findBatchId(string $publicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [LegacyMigrationBatchId] FROM [migration].[LegacyMigrationBatches] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::nullableInt($rows[0]['LegacyMigrationBatchId'] ?? null);
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function assoc(mixed $value): array
    {
        if (is_array($value)) {
            return MigrationPayload::assoc($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? MigrationPayload::assoc($decoded) : [];
    }

    private function sqlDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = AzureSqlValueMapper::nullableDateTime(is_string($value) ? str_replace('T', ' ', rtrim($value, 'Z')) : $value);

        return $dt?->format('Y-m-d H:i:s.u');
    }

    private function iso(mixed $value): ?string
    {
        $dt = AzureSqlValueMapper::nullableDateTime($value);

        return $dt?->format('Y-m-d\TH:i:s\Z');
    }
}
