<?php
// @phpstan-ignore-file

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Product\Migration\Application\DTO\DryRunReport;
use TriCab\Core\Product\Migration\Application\DTO\MigrationBatchDetails;
use TriCab\Core\Product\Migration\Application\DTO\MigrationIssueDetails;
use TriCab\Core\Product\Migration\Application\Exception\MigrationBatchNotFound;
use TriCab\Core\Product\Migration\Application\Exception\MigrationIssueNotFound;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Azure SQL persistence for Phase 12 migration batches and staging.
 * executeImport records draft mappings only; it does not auto-publish.
 */
final class AzureSqlMigrationBatchRepository implements MigrationBatchRepository
{
    private const RAW_TABLES = [
        'product' => '[migration].[LegacyProductRaw]',
        'variant' => '[migration].[LegacyVariantRaw]',
        'specification' => '[migration].[LegacySpecificationRaw]',
        'media' => '[migration].[LegacyMediaRaw]',
        'document' => '[migration].[LegacyDocumentRaw]',
        'relationship' => '[migration].[LegacyRelationshipRaw]',
        'classification' => '[migration].[LegacyClassificationRaw]',
    ];

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function createBatch(array $data): MigrationBatchDetails
    {
        $publicId = $this->uuids->generate()->toString();
        $filterJson = isset($data['sourceFilter']) && is_array($data['sourceFilter'])
            ? json_encode($data['sourceFilter'], JSON_THROW_ON_ERROR)
            : null;

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[MigrationBatches] (
    [PublicId], [Name], [Description], [SourceSystem], [SourceFilterJson], [DryRun], [StatusCode], [CreatedByUserId]
) VALUES (
    :publicId, :name, :description, :sourceSystem, :sourceFilterJson, :dryRun, N'draft', :createdByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':name' => MigrationPayload::string($data['name'] ?? ''),
                ':description' => MigrationPayload::stringOrNull($data['description'] ?? null),
                ':sourceSystem' => MigrationPayload::string($data['sourceSystem'] ?? ''),
                ':sourceFilterJson' => $filterJson,
                ':dryRun' => !empty($data['dryRun']) ? 1 : 0,
                ':createdByUserId' => AzureSqlValueMapper::intValue($data['createdByUserId'] ?? 0),
            ],
        );

        $batch = $this->getBatchByPublicId($publicId);
        if ($batch === null) {
            throw MigrationBatchNotFound::forPublicId($publicId);
        }

        return $batch;
    }

    public function getBatchByPublicId(string $publicId): ?MigrationBatchDetails
    {
        $rows = $this->connection->fetchAll(
            $this->batchSelectSql() . ' WHERE b.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrateBatch($rows[0]);
    }

    public function listBatches(?string $statusCode = null): array
    {
        $sql = $this->batchSelectSql();
        $params = [];
        if ($statusCode !== null) {
            $sql .= ' WHERE b.[StatusCode] = :statusCode';
            $params[':statusCode'] = $statusCode;
        }
        $sql .= ' ORDER BY b.[CreatedAtUtc] DESC';

        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = $this->hydrateBatch($row);
        }

        return $out;
    }

    public function updateBatchStatus(string $publicId, string $statusCode, array $counts = [], ?array $timestamps = null): MigrationBatchDetails
    {
        $batchId = $this->requireBatchId($publicId);
        $sets = ['[StatusCode] = :statusCode'];
        $params = [':statusCode' => $statusCode, ':batchId' => $batchId];
        $map = [
            'totalRecords' => 'TotalRecords',
            'importedRecords' => 'ImportedRecords',
            'updatedRecords' => 'UpdatedRecords',
            'skippedRecords' => 'SkippedRecords',
            'warningRecords' => 'WarningRecords',
            'failedRecords' => 'FailedRecords',
            'manualReviewRecords' => 'ManualReviewRecords',
        ];
        foreach ($map as $key => $col) {
            if (isset($counts[$key])) {
                $sets[] = "[{$col}] = :{$key}";
                $params[":{$key}"] = (int) $counts[$key];
            }
        }
        $tsMap = [
            'startedAtUtc' => 'StartedAtUtc',
            'completedAtUtc' => 'CompletedAtUtc',
            'rolledBackAtUtc' => 'RolledBackAtUtc',
            'rolledBackByUserId' => 'RolledBackByUserId',
            'cancelledAtUtc' => 'CancelledAtUtc',
            'cancelledByUserId' => 'CancelledByUserId',
        ];
        if ($timestamps !== null) {
            foreach ($tsMap as $key => $col) {
                if (array_key_exists($key, $timestamps)) {
                    $sets[] = "[{$col}] = :{$key}";
                    $params[":{$key}"] = $timestamps[$key];
                }
            }
        }

        $sql = 'UPDATE [migration].[MigrationBatches] SET ' . implode(', ', $sets) . ' WHERE [MigrationBatchId] = :batchId';
        $this->connection->execute($sql, $params);

        $batch = $this->getBatchByPublicId($publicId);
        if ($batch === null) {
            throw MigrationBatchNotFound::forPublicId($publicId);
        }

        return $batch;
    }

    public function upsertRaw(string $batchPublicId, array $row): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $recordType = strtolower(MigrationPayload::string($row['recordType'] ?? 'product'));
        $table = self::RAW_TABLES[$recordType] ?? self::RAW_TABLES['product'];
        $payloadJson = json_encode($row, JSON_THROW_ON_ERROR);

        // sqlsrv PDO rejects reused named parameters in a single statement (MERGE USING+INSERT).
        $existing = $this->connection->fetchAll(
            <<<SQL
SELECT TOP 1 1 AS [ExistsFlag]
FROM {$table}
WHERE [MigrationBatchId] = :batchId
  AND [SourceSystem] = :sourceSystem
  AND [SourceTable] = :sourceTable
  AND [SourceIdentifier] = :sourceIdentifier
SQL,
            [
                ':batchId' => $batchId,
                ':sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? ''),
                ':sourceTable' => MigrationPayload::string($row['sourceTable'] ?? ''),
                ':sourceIdentifier' => MigrationPayload::string($row['sourceIdentifier'] ?? ''),
            ],
        );

        if ($existing !== []) {
            $this->connection->execute(
                <<<SQL
UPDATE {$table}
SET
    [ParentSourceIdentifier] = :parentSourceIdentifier,
    [SourceHash] = :sourceHash,
    [RawPayloadJson] = :rawPayloadJson,
    [StatusCode] = :statusCode,
    [ErrorMessage] = :errorMessage,
    [WarningMessage] = :warningMessage,
    [ExtractedAtUtc] = SYSUTCDATETIME()
WHERE [MigrationBatchId] = :batchId
  AND [SourceSystem] = :sourceSystem
  AND [SourceTable] = :sourceTable
  AND [SourceIdentifier] = :sourceIdentifier
SQL,
                [
                    ':batchId' => $batchId,
                    ':sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? ''),
                    ':sourceTable' => MigrationPayload::string($row['sourceTable'] ?? ''),
                    ':sourceIdentifier' => MigrationPayload::string($row['sourceIdentifier'] ?? ''),
                    ':parentSourceIdentifier' => MigrationPayload::stringOrNull($row['parentSourceIdentifier'] ?? null),
                    ':sourceHash' => MigrationPayload::string($row['sourceHash'] ?? ''),
                    ':rawPayloadJson' => $payloadJson,
                    ':statusCode' => MigrationPayload::string($row['statusCode'] ?? 'extracted'),
                    ':errorMessage' => MigrationPayload::stringOrNull($row['errorMessage'] ?? null),
                    ':warningMessage' => MigrationPayload::stringOrNull($row['warningMessage'] ?? null),
                ],
            );

            return;
        }

        $this->connection->execute(
            <<<SQL
INSERT INTO {$table} (
    [MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier], [ParentSourceIdentifier],
    [SourceHash], [RawPayloadJson], [StatusCode], [ErrorMessage], [WarningMessage]
) VALUES (
    :batchId, :sourceSystem, :sourceTable, :sourceIdentifier, :parentSourceIdentifier,
    :sourceHash, :rawPayloadJson, :statusCode, :errorMessage, :warningMessage
)
SQL,
            [
                ':batchId' => $batchId,
                ':sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? ''),
                ':sourceTable' => MigrationPayload::string($row['sourceTable'] ?? ''),
                ':sourceIdentifier' => MigrationPayload::string($row['sourceIdentifier'] ?? ''),
                ':parentSourceIdentifier' => MigrationPayload::stringOrNull($row['parentSourceIdentifier'] ?? null),
                ':sourceHash' => MigrationPayload::string($row['sourceHash'] ?? ''),
                ':rawPayloadJson' => $payloadJson,
                ':statusCode' => MigrationPayload::string($row['statusCode'] ?? 'extracted'),
                ':errorMessage' => MigrationPayload::stringOrNull($row['errorMessage'] ?? null),
                ':warningMessage' => MigrationPayload::stringOrNull($row['warningMessage'] ?? null),
            ],
        );
    }

    public function listRaw(string $batchPublicId, ?string $recordType = null): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $types = $recordType === null ? array_keys(self::RAW_TABLES) : [strtolower($recordType)];
        $out = [];
        foreach ($types as $type) {
            $table = self::RAW_TABLES[$type] ?? null;
            if ($table === null) {
                continue;
            }
            $rows = $this->connection->fetchAll(
                "SELECT [RawPayloadJson] FROM {$table} WHERE [MigrationBatchId] = :batchId",
                [':batchId' => $batchId],
            );
            foreach ($rows as $row) {
                $decoded = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($row['RawPayloadJson'] ?? ''), true));
                if ($decoded !== []) {
                    $decoded['recordType'] = $decoded['recordType'] ?? $type;
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    public function upsertCanonical(string $batchPublicId, CanonicalProduct $product, array $meta = []): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $json = json_encode(['product' => $product->toArray(), 'meta' => $meta], JSON_THROW_ON_ERROR);
        $sourceKey = $product->sourceKey;
        $params = [
            ':batchId' => $batchId,
            ':sourceKey' => $sourceKey,
            ':canonicalJson' => $json,
            ':statusCode' => MigrationPayload::string($meta['statusCode'] ?? 'normalized'),
            ':productDecisionCode' => MigrationPayload::stringOrNull($product->productDecisionCode ?? ($meta['productDecisionCode'] ?? null)),
            ':productDecisionReason' => MigrationPayload::stringOrNull($product->productDecisionReason ?? ($meta['productDecisionReason'] ?? null)),
            ':matchMethod' => MigrationPayload::stringOrNull($meta['matchMethod'] ?? null),
            ':matchConfidence' => MigrationPayload::stringOrNull($meta['matchConfidence'] ?? null),
            ':targetProductPublicId' => MigrationPayload::stringOrNull($meta['targetProductPublicId'] ?? null),
        ];

        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 1 AS [ExistsFlag]
FROM [migration].[CanonicalProducts]
WHERE [MigrationBatchId] = :batchId AND [SourceKey] = :sourceKey
SQL,
            [
                ':batchId' => $batchId,
                ':sourceKey' => $sourceKey,
            ],
        );

        if ($existing !== []) {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[CanonicalProducts]
SET
    [CanonicalJson] = :canonicalJson,
    [StatusCode] = :statusCode,
    [ProductDecisionCode] = :productDecisionCode,
    [ProductDecisionReason] = :productDecisionReason,
    [MatchMethod] = :matchMethod,
    [MatchConfidence] = :matchConfidence,
    [TargetProductPublicId] = :targetProductPublicId,
    [NormalizedAtUtc] = SYSUTCDATETIME()
WHERE [MigrationBatchId] = :batchId AND [SourceKey] = :sourceKey
SQL,
                $params,
            );

            return;
        }

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[CanonicalProducts] (
    [MigrationBatchId], [SourceKey], [CanonicalJson], [StatusCode], [ProductDecisionCode],
    [ProductDecisionReason], [MatchMethod], [MatchConfidence], [TargetProductPublicId]
) VALUES (
    :batchId, :sourceKey, :canonicalJson, :statusCode, :productDecisionCode,
    :productDecisionReason, :matchMethod, :matchConfidence, :targetProductPublicId
)
SQL,
            $params,
        );
    }

    public function listCanonical(string $batchPublicId): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $rows = $this->connection->fetchAll(
            'SELECT [CanonicalJson] FROM [migration].[CanonicalProducts] WHERE [MigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );
        $out = [];
        foreach ($rows as $row) {
            $decoded = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($row['CanonicalJson'] ?? ''), true));
            if ($decoded === []) {
                continue;
            }
            $productData = MigrationPayload::assoc($decoded['product'] ?? $decoded);
            $out[] = CanonicalProduct::fromArray($productData);
        }

        return $out;
    }

    public function addIssue(string $batchPublicId, array $issue): MigrationIssueDetails
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $publicId = $this->uuids->generate()->toString();
        $suggested = isset($issue['suggestedMatch']) && is_array($issue['suggestedMatch'])
            ? json_encode($issue['suggestedMatch'], JSON_THROW_ON_ERROR)
            : null;

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[MigrationIssues] (
    [PublicId], [MigrationBatchId], [IssueTypeCode], [SeverityCode], [SourceSystem], [SourceTable],
    [SourceIdentifier], [SourceKey], [Message], [SuggestedMatchJson], [MatchConfidence], [StatusCode]
) VALUES (
    :publicId, :batchId, :issueTypeCode, :severityCode, :sourceSystem, :sourceTable,
    :sourceIdentifier, :sourceKey, :message, :suggestedMatchJson, :matchConfidence, N'open'
)
SQL,
            [
                ':publicId' => $publicId,
                ':batchId' => $batchId,
                ':issueTypeCode' => MigrationPayload::string($issue['issueTypeCode'] ?? ''),
                ':severityCode' => MigrationPayload::string($issue['severityCode'] ?? 'warning'),
                ':sourceSystem' => MigrationPayload::stringOrNull($issue['sourceSystem'] ?? null),
                ':sourceTable' => MigrationPayload::stringOrNull($issue['sourceTable'] ?? null),
                ':sourceIdentifier' => MigrationPayload::stringOrNull($issue['sourceIdentifier'] ?? null),
                ':sourceKey' => MigrationPayload::stringOrNull($issue['sourceKey'] ?? null),
                ':message' => MigrationPayload::string($issue['message'] ?? ''),
                ':suggestedMatchJson' => $suggested,
                ':matchConfidence' => MigrationPayload::stringOrNull($issue['matchConfidence'] ?? null),
            ],
        );

        $found = $this->findIssue($publicId);
        if ($found === null) {
            throw MigrationIssueNotFound::forPublicId($publicId);
        }

        return $found;
    }

    public function listIssues(?string $batchPublicId = null, ?string $statusCode = 'open', int $limit = 100, int $offset = 0): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), i.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), b.[PublicId]) AS [BatchPublicId],
    i.[IssueTypeCode], i.[SeverityCode], i.[SourceSystem], i.[SourceTable], i.[SourceIdentifier],
    i.[SourceKey], i.[Message], i.[SuggestedMatchJson], i.[MatchConfidence], i.[StatusCode],
    i.[ResolutionJson], CONVERT(VARCHAR(33), i.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [migration].[MigrationIssues] i
INNER JOIN [migration].[MigrationBatches] b ON b.[MigrationBatchId] = i.[MigrationBatchId]
WHERE 1 = 1
SQL;
        $params = [];
        if ($batchPublicId !== null) {
            $sql .= ' AND b.[PublicId] = :batchPublicId';
            $params[':batchPublicId'] = $batchPublicId;
        }
        if ($statusCode !== null) {
            $sql .= ' AND i.[StatusCode] = :statusCode';
            $params[':statusCode'] = $statusCode;
        }
        $sql .= ' ORDER BY i.[CreatedAtUtc] DESC OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';
        $params[':offset'] = $offset;
        $params[':limit'] = $limit;

        $out = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $out[] = $this->hydrateIssue($row);
        }

        return $out;
    }

    public function resolveIssue(string $issuePublicId, array $resolution, int $resolvedByUserId): MigrationIssueDetails
    {
        $existing = $this->findIssue($issuePublicId);
        if ($existing === null) {
            throw MigrationIssueNotFound::forPublicId($issuePublicId);
        }

        $payload = array_merge($resolution, [
            'resolvedByUserId' => $resolvedByUserId,
            'resolvedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        $this->connection->execute(
            <<<'SQL'
UPDATE [migration].[MigrationIssues]
SET [StatusCode] = :statusCode,
    [ResolutionJson] = :resolutionJson,
    [ResolvedByUserId] = :resolvedByUserId,
    [ResolvedAtUtc] = SYSUTCDATETIME()
WHERE [PublicId] = :publicId
SQL,
            [
                ':statusCode' => MigrationPayload::string($resolution['statusCode'] ?? 'resolved'),
                ':resolutionJson' => json_encode($payload, JSON_THROW_ON_ERROR),
                ':resolvedByUserId' => $resolvedByUserId,
                ':publicId' => $issuePublicId,
            ],
        );

        $found = $this->findIssue($issuePublicId);
        if ($found === null) {
            throw MigrationIssueNotFound::forPublicId($issuePublicId);
        }

        return $found;
    }

    public function saveDryRunReport(string $batchPublicId, DryRunReport $report): void
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[MigrationDryRunReports] ([PublicId], [MigrationBatchId], [ReportJson])
VALUES (:publicId, :batchId, :reportJson)
SQL,
            [
                ':publicId' => $this->uuids->generate()->toString(),
                ':batchId' => $batchId,
                ':reportJson' => json_encode($report->toArray(), JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function getLatestDryRunReport(string $batchPublicId): ?DryRunReport
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 [ReportJson]
FROM [migration].[MigrationDryRunReports]
WHERE [MigrationBatchId] = :batchId
ORDER BY [CreatedAtUtc] DESC
SQL,
            [':batchId' => $batchId],
        );
        if ($rows === []) {
            return null;
        }

        $data = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($rows[0]['ReportJson'] ?? ''), true));
        if ($data === []) {
            return null;
        }

        $counts = [];
        if (is_array($data['counts'] ?? null)) {
            foreach ($data['counts'] as $k => $v) {
                if (is_string($k)) {
                    $counts[$k] = is_int($v) ? $v : (int) $v;
                }
            }
        }

        $unresolved = [];
        if (is_array($data['unresolved'] ?? null)) {
            foreach ($data['unresolved'] as $item) {
                if (is_array($item)) {
                    $unresolved[] = MigrationPayload::assoc($item);
                }
            }
        }

        return new DryRunReport(
            batchPublicId: MigrationPayload::string($data['batchPublicId'] ?? $batchPublicId),
            counts: $counts,
            warnings: MigrationPayload::stringList($data['warnings'] ?? null, []),
            blockingErrors: MigrationPayload::stringList($data['blockingErrors'] ?? null, []),
            unresolved: $unresolved,
            wouldWriteProducts: (bool) ($data['wouldWriteProducts'] ?? false),
            wouldWriteBlobs: (bool) ($data['wouldWriteBlobs'] ?? false),
        );
    }

    public function saveReconciliation(string $batchPublicId, array $report): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $publicId = $this->uuids->generate()->toString();
        $report['publicId'] = $publicId;
        $report['createdAtUtc'] = gmdate('Y-m-d\TH:i:s\Z');

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[MigrationReconciliationReports] ([PublicId], [MigrationBatchId], [ReportJson])
VALUES (:publicId, :batchId, :reportJson)
SQL,
            [
                ':publicId' => $publicId,
                ':batchId' => $batchId,
                ':reportJson' => json_encode($report, JSON_THROW_ON_ERROR),
            ],
        );

        return $report;
    }

    public function getLatestReconciliation(string $batchPublicId): ?array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1 [ReportJson]
FROM [migration].[MigrationReconciliationReports]
WHERE [MigrationBatchId] = :batchId
ORDER BY [CreatedAtUtc] DESC
SQL,
            [':batchId' => $batchId],
        );
        if ($rows === []) {
            return null;
        }

        $decoded = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($rows[0]['ReportJson'] ?? ''), true));

        return $decoded === [] ? null : $decoded;
    }

    public function recordAudit(?string $batchPublicId, string $actionCode, ?int $actorUserId, ?string $correlationId, ?array $payload): void
    {
        if ($payload !== null) {
            foreach (array_keys($payload) as $key) {
                $lower = strtolower(MigrationPayload::string($key));
                if (
                    str_contains($lower, 'password')
                    || str_contains($lower, 'token')
                    || str_contains($lower, 'sas')
                    || str_contains($lower, 'credential')
                ) {
                    $payload[$key] = '***';
                }
            }
        }

        $batchId = $batchPublicId === null ? null : $this->findBatchId($batchPublicId);
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [migration].[MigrationAuditEvents] (
    [MigrationBatchId], [ActionCode], [ActorUserId], [CorrelationId], [PayloadJson]
) VALUES (:batchId, :actionCode, :actorUserId, :correlationId, :payloadJson)
SQL,
            [
                ':batchId' => $batchId,
                ':actionCode' => $actionCode,
                ':actorUserId' => $actorUserId,
                ':correlationId' => $correlationId,
                ':payloadJson' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function upsertEntityMapping(array $mapping): void
    {
        $notes = json_encode([
            'targetEntityType' => $mapping['targetEntityType'] ?? 'product',
            'manuallyEdited' => (bool) ($mapping['manuallyEdited'] ?? false),
            'migrationBatchId' => $mapping['migrationBatchId'] ?? null,
        ], JSON_THROW_ON_ERROR);

        $existing = $this->findEntityMapping(
            MigrationPayload::string($mapping['sourceSystem'] ?? ''),
            MigrationPayload::string($mapping['sourceTable'] ?? ''),
            MigrationPayload::string($mapping['sourceIdentifier'] ?? ''),
        );
        $status = MigrationPayload::string($mapping['migrationStatus'] ?? 'imported');

        if ($existing === null) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[LegacyProductMappings] (
    [SourceSystemCode], [SourceProductKey], [SourceTable], [SourceHash], [TargetEntityType],
    [TargetPublicId], [MigrationStatusCode], [MigrationNotes], [UpdatedAtUtc], [MigratedAtUtc]
) VALUES (
    :sourceSystem, :sourceKey, :sourceTable, :sourceHash, :targetEntityType,
    :targetPublicId, :status, :notes, SYSUTCDATETIME(), SYSUTCDATETIME()
)
SQL,
                [
                    ':sourceSystem' => MigrationPayload::string($mapping['sourceSystem'] ?? ''),
                    ':sourceKey' => MigrationPayload::string($mapping['sourceIdentifier'] ?? ''),
                    ':sourceTable' => MigrationPayload::string($mapping['sourceTable'] ?? ''),
                    ':sourceHash' => MigrationPayload::stringOrNull($mapping['sourceHash'] ?? null),
                    ':targetEntityType' => MigrationPayload::string($mapping['targetEntityType'] ?? 'product'),
                    ':targetPublicId' => MigrationPayload::stringOrNull($mapping['targetPublicId'] ?? null),
                    ':status' => $status,
                    ':notes' => $notes,
                ],
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[LegacyProductMappings]
SET [SourceTable] = :sourceTable,
    [SourceHash] = :sourceHash,
    [TargetEntityType] = :targetEntityType,
    [TargetPublicId] = :targetPublicId,
    [MigrationStatusCode] = :status,
    [MigrationNotes] = :notes,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [MigratedAtUtc] = CASE WHEN :statusForMigratedAt IN (N'imported', N'updated') THEN SYSUTCDATETIME() ELSE [MigratedAtUtc] END
WHERE [SourceSystemCode] = :sourceSystem AND [SourceProductKey] = :sourceKey
SQL,
                [
                    ':sourceSystem' => MigrationPayload::string($mapping['sourceSystem'] ?? ''),
                    ':sourceKey' => MigrationPayload::string($mapping['sourceIdentifier'] ?? ''),
                    ':sourceTable' => MigrationPayload::string($mapping['sourceTable'] ?? ''),
                    ':sourceHash' => MigrationPayload::stringOrNull($mapping['sourceHash'] ?? null),
                    ':targetEntityType' => MigrationPayload::string($mapping['targetEntityType'] ?? 'product'),
                    ':targetPublicId' => MigrationPayload::stringOrNull($mapping['targetPublicId'] ?? null),
                    ':status' => $status,
                    ':statusForMigratedAt' => $status,
                    ':notes' => $notes,
                ],
            );
        }
    }

    public function findEntityMapping(string $sourceSystem, string $sourceTable, string $sourceIdentifier): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1
    [SourceSystemCode], [SourceProductKey], [SourceTable], [SourceHash], [TargetEntityType],
    CONVERT(NVARCHAR(36), [TargetPublicId]) AS [TargetPublicId],
    [MigrationStatusCode], [MigrationNotes], [MigrationBatchId]
FROM [migration].[LegacyProductMappings]
WHERE [SourceSystemCode] = :sourceSystem AND [SourceProductKey] = :sourceKey
  AND ( [SourceTable] = :sourceTable OR ([SourceTable] IS NULL AND :sourceTableDefault = N'product') )
SQL,
            [
                ':sourceSystem' => $sourceSystem,
                ':sourceKey' => $sourceIdentifier,
                ':sourceTable' => $sourceTable,
                ':sourceTableDefault' => $sourceTable,
            ],
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $notes = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($row['MigrationNotes'] ?? ''), true));

        return [
            'sourceSystem' => AzureSqlValueMapper::stringValue($row['SourceSystemCode']),
            'sourceTable' => AzureSqlValueMapper::nullableString($row['SourceTable']) ?? $sourceTable,
            'sourceIdentifier' => AzureSqlValueMapper::stringValue($row['SourceProductKey']),
            'targetEntityType' => AzureSqlValueMapper::nullableString($row['TargetEntityType']) ?? 'product',
            'targetPublicId' => AzureSqlValueMapper::nullableString($row['TargetPublicId']),
            'sourceHash' => AzureSqlValueMapper::nullableString($row['SourceHash']),
            'migrationStatus' => AzureSqlValueMapper::stringValue($row['MigrationStatusCode']),
            'migrationBatchId' => $notes['migrationBatchId'] ?? null,
            'manuallyEdited' => (bool) ($notes['manuallyEdited'] ?? false),
        ];
    }

    public function listClassificationMappings(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [publicId], [SourceSystem] AS [sourceSystem],
    [LegacyClassificationValue] AS [legacyClassificationValue], [LegacyTaxonomy] AS [legacyTaxonomy],
    CONVERT(NVARCHAR(36), [TargetFamilyPublicId]) AS [targetFamilyPublicId],
    CONVERT(NVARCHAR(36), [TargetCategoryPublicId]) AS [targetCategoryPublicId],
    CONVERT(NVARCHAR(36), [TargetSeriesPublicId]) AS [targetSeriesPublicId],
    [StatusCode] AS [statusCode], [Notes] AS [notes]
FROM [migration].[ClassificationMappings]
ORDER BY [UpdatedAtUtc] DESC
SQL,
        );

        return array_map(static fn (array $r): array => self::stringifyRow($r), $rows);
    }

    public function upsertClassificationMapping(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }

        $existing = $this->connection->fetchAll(
            'SELECT TOP 1 [ClassificationMappingId] FROM [migration].[ClassificationMappings] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        $params = [
            ':publicId' => $publicId,
            ':sourceSystem' => MigrationPayload::string($data['sourceSystem'] ?? 'wordpress'),
            ':legacyValue' => MigrationPayload::string($data['legacyClassificationValue'] ?? $data['legacyValue'] ?? ''),
            ':legacyTaxonomy' => MigrationPayload::stringOrNull($data['legacyTaxonomy'] ?? null),
            ':family' => MigrationPayload::stringOrNull($data['targetFamilyPublicId'] ?? null),
            ':category' => MigrationPayload::stringOrNull($data['targetCategoryPublicId'] ?? null),
            ':series' => MigrationPayload::stringOrNull($data['targetSeriesPublicId'] ?? null),
            ':statusCode' => MigrationPayload::string($data['statusCode'] ?? 'needs-review'),
            ':notes' => MigrationPayload::stringOrNull($data['notes'] ?? null),
            ':mappedBy' => isset($data['mappedByUserId']) ? AzureSqlValueMapper::intValue($data['mappedByUserId']) : null,
        ];

        if ($existing === []) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[ClassificationMappings] (
    [PublicId], [SourceSystem], [LegacyClassificationValue], [LegacyTaxonomy],
    [TargetFamilyPublicId], [TargetCategoryPublicId], [TargetSeriesPublicId],
    [StatusCode], [Notes], [MappedByUserId], [MappedAtUtc]
) VALUES (
    :publicId, :sourceSystem, :legacyValue, :legacyTaxonomy,
    :family, :category, :series, :statusCode, :notes, :mappedBy, SYSUTCDATETIME()
)
SQL,
                $params,
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[ClassificationMappings]
SET [SourceSystem] = :sourceSystem,
    [LegacyClassificationValue] = :legacyValue,
    [LegacyTaxonomy] = :legacyTaxonomy,
    [TargetFamilyPublicId] = :family,
    [TargetCategoryPublicId] = :category,
    [TargetSeriesPublicId] = :series,
    [StatusCode] = :statusCode,
    [Notes] = :notes,
    [MappedByUserId] = :mappedBy,
    [MappedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [PublicId] = :publicId
SQL,
                $params,
            );
        }

        foreach ($this->listClassificationMappings() as $row) {
            if (($row['publicId'] ?? null) === $publicId) {
                return $row;
            }
        }

        return array_merge($data, ['publicId' => $publicId]);
    }

    public function listSpecificationMappings(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [publicId], [SourceSystem] AS [sourceSystem],
    [SourceTable] AS [sourceTable], [SourceField] AS [sourceField],
    CONVERT(NVARCHAR(36), [TargetSpecificationDefinitionPublicId]) AS [targetSpecificationDefinitionPublicId],
    [TargetDefinitionCode] AS [targetDefinitionCode], [TargetScopeCode] AS [targetScopeCode],
    [TransformationRule] AS [transformationRule], [SourceUnit] AS [sourceUnit], [TargetUnit] AS [targetUnit],
    [StatusCode] AS [statusCode], [Notes] AS [notes]
FROM [migration].[SpecificationFieldMappings]
ORDER BY [UpdatedAtUtc] DESC
SQL,
        );

        return array_map(static fn (array $r): array => self::stringifyRow($r), $rows);
    }

    public function upsertSpecificationMapping(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }

        $existing = $this->connection->fetchAll(
            'SELECT TOP 1 [SpecificationFieldMappingId] FROM [migration].[SpecificationFieldMappings] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        $params = [
            ':publicId' => $publicId,
            ':sourceSystem' => MigrationPayload::string($data['sourceSystem'] ?? 'tc'),
            ':sourceTable' => MigrationPayload::string($data['sourceTable'] ?? 'products'),
            ':sourceField' => MigrationPayload::string($data['sourceField'] ?? ''),
            ':defId' => MigrationPayload::stringOrNull($data['targetSpecificationDefinitionPublicId'] ?? null),
            ':defCode' => MigrationPayload::stringOrNull($data['targetDefinitionCode'] ?? null),
            ':scope' => MigrationPayload::stringOrNull($data['targetScopeCode'] ?? null),
            ':rule' => MigrationPayload::string($data['transformationRule'] ?? 'text'),
            ':sourceUnit' => MigrationPayload::stringOrNull($data['sourceUnit'] ?? null),
            ':targetUnit' => MigrationPayload::stringOrNull($data['targetUnit'] ?? null),
            ':statusCode' => MigrationPayload::string($data['statusCode'] ?? 'needs-review'),
            ':notes' => MigrationPayload::stringOrNull($data['notes'] ?? null),
            ':mappedBy' => isset($data['mappedByUserId']) ? AzureSqlValueMapper::intValue($data['mappedByUserId']) : null,
        ];

        if ($existing === []) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[SpecificationFieldMappings] (
    [PublicId], [SourceSystem], [SourceTable], [SourceField], [TargetSpecificationDefinitionPublicId],
    [TargetDefinitionCode], [TargetScopeCode], [TransformationRule], [SourceUnit], [TargetUnit],
    [StatusCode], [Notes], [MappedByUserId], [MappedAtUtc]
) VALUES (
    :publicId, :sourceSystem, :sourceTable, :sourceField, :defId,
    :defCode, :scope, :rule, :sourceUnit, :targetUnit, :statusCode, :notes, :mappedBy, SYSUTCDATETIME()
)
SQL,
                $params,
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[SpecificationFieldMappings]
SET [SourceSystem] = :sourceSystem, [SourceTable] = :sourceTable, [SourceField] = :sourceField,
    [TargetSpecificationDefinitionPublicId] = :defId, [TargetDefinitionCode] = :defCode,
    [TargetScopeCode] = :scope, [TransformationRule] = :rule, [SourceUnit] = :sourceUnit,
    [TargetUnit] = :targetUnit, [StatusCode] = :statusCode, [Notes] = :notes,
    [MappedByUserId] = :mappedBy, [MappedAtUtc] = SYSUTCDATETIME(), [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [PublicId] = :publicId
SQL,
                $params,
            );
        }

        foreach ($this->listSpecificationMappings() as $row) {
            if (($row['publicId'] ?? null) === $publicId) {
                return $row;
            }
        }

        return array_merge($data, ['publicId' => $publicId]);
    }

    public function listOptionAliases(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [publicId], [DefinitionCode] AS [definitionCode],
    [LegacyValue] AS [legacyValue], [NormalizedLegacyValue] AS [normalizedLegacyValue],
    CONVERT(NVARCHAR(36), [TargetOptionPublicId]) AS [targetOptionPublicId],
    [TargetOptionCode] AS [targetOptionCode], [SourceSystem] AS [sourceSystem],
    [StatusCode] AS [statusCode], [Notes] AS [notes]
FROM [migration].[SpecificationOptionAliases]
ORDER BY [UpdatedAtUtc] DESC
SQL,
        );

        return array_map(static fn (array $r): array => self::stringifyRow($r), $rows);
    }

    public function upsertOptionAlias(string $publicId, array $data): array
    {
        if ($publicId === '' || $publicId === 'new') {
            $publicId = $this->uuids->generate()->toString();
        }

        $legacy = MigrationPayload::string($data['legacyValue'] ?? '');
        $normalized = MigrationPayload::string($data['normalizedLegacyValue'] ?? strtolower(trim($legacy)));

        $existing = $this->connection->fetchAll(
            'SELECT TOP 1 [SpecificationOptionAliasId] FROM [migration].[SpecificationOptionAliases] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        $params = [
            ':publicId' => $publicId,
            ':definitionCode' => MigrationPayload::string($data['definitionCode'] ?? ''),
            ':legacyValue' => $legacy,
            ':normalized' => $normalized,
            ':optionId' => MigrationPayload::stringOrNull($data['targetOptionPublicId'] ?? null),
            ':optionCode' => MigrationPayload::stringOrNull($data['targetOptionCode'] ?? null),
            ':sourceSystem' => MigrationPayload::string($data['sourceSystem'] ?? 'tc'),
            ':statusCode' => MigrationPayload::string($data['statusCode'] ?? 'needs-review'),
            ':notes' => MigrationPayload::stringOrNull($data['notes'] ?? null),
            ':mappedBy' => isset($data['mappedByUserId']) ? AzureSqlValueMapper::intValue($data['mappedByUserId']) : null,
        ];

        if ($existing === []) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[SpecificationOptionAliases] (
    [PublicId], [DefinitionCode], [LegacyValue], [NormalizedLegacyValue], [TargetOptionPublicId],
    [TargetOptionCode], [SourceSystem], [StatusCode], [Notes], [MappedByUserId], [MappedAtUtc]
) VALUES (
    :publicId, :definitionCode, :legacyValue, :normalized, :optionId,
    :optionCode, :sourceSystem, :statusCode, :notes, :mappedBy, SYSUTCDATETIME()
)
SQL,
                $params,
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[SpecificationOptionAliases]
SET [DefinitionCode] = :definitionCode, [LegacyValue] = :legacyValue, [NormalizedLegacyValue] = :normalized,
    [TargetOptionPublicId] = :optionId, [TargetOptionCode] = :optionCode, [SourceSystem] = :sourceSystem,
    [StatusCode] = :statusCode, [Notes] = :notes, [MappedByUserId] = :mappedBy,
    [MappedAtUtc] = SYSUTCDATETIME(), [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [PublicId] = :publicId
SQL,
                $params,
            );
        }

        foreach ($this->listOptionAliases() as $row) {
            if (($row['publicId'] ?? null) === $publicId) {
                return $row;
            }
        }

        return array_merge($data, ['publicId' => $publicId]);
    }

    public function executeImport(string $batchPublicId, bool $dryRun): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $result = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'warning' => 0,
            'manualReview' => 0,
            'productWrites' => 0,
            'blobWrites' => 0,
        ];

        $rows = $this->connection->fetchAll(
            'SELECT [CanonicalJson] FROM [migration].[CanonicalProducts] WHERE [MigrationBatchId] = :batchId',
            [':batchId' => $batchId],
        );

        foreach ($rows as $row) {
            $decoded = MigrationPayload::assoc(json_decode(AzureSqlValueMapper::stringValue($row['CanonicalJson'] ?? ''), true));
            if ($decoded === []) {
                ++$result['failed'];
                continue;
            }

            $productData = MigrationPayload::assoc($decoded['product'] ?? $decoded);
            $meta = MigrationPayload::assoc($decoded['meta'] ?? []);
            $product = CanonicalProduct::fromArray($productData);

            if (($meta['requiresReview'] ?? false) === true || ($product->productDecisionCode ?? '') === 'manual-review') {
                ++$result['manualReview'];
                continue;
            }

            [$system, $table, $id] = $this->splitSourceKey($product->sourceKey);
            $existing = $this->findEntityMapping($system, $table !== '' ? $table : 'product', $id);
            $hash = MigrationPayload::string($meta['sourceHash'] ?? '');

            if ($existing !== null && ($existing['sourceHash'] ?? null) === $hash) {
                ++$result['skipped'];
                continue;
            }

            if ($dryRun) {
                ++$result['imported'];
                continue;
            }

            $targetPublicId = $existing['targetPublicId'] ?? $this->uuids->generate()->toString();
            $action = $existing === null ? 'imported' : 'updated';
            if ($action === 'imported') {
                ++$result['imported'];
            } else {
                ++$result['updated'];
            }
            ++$result['productWrites'];

            foreach ($product->media as $media) {
                if ($media->sourceExists) {
                    ++$result['blobWrites'];
                } else {
                    ++$result['warning'];
                }
            }
            foreach ($product->documents as $doc) {
                if ($doc->sourceExists) {
                    ++$result['blobWrites'];
                } else {
                    ++$result['warning'];
                }
            }

            $this->upsertEntityMapping([
                'sourceSystem' => $system,
                'sourceTable' => $table !== '' ? $table : 'product',
                'sourceIdentifier' => $id,
                'targetEntityType' => 'product',
                'targetPublicId' => $targetPublicId,
                'migrationBatchId' => $batchPublicId,
                'sourceHash' => $hash,
                'migrationStatus' => $action,
                'manuallyEdited' => false,
            ]);

            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[CanonicalProducts]
SET [TargetProductPublicId] = :targetPublicId, [StatusCode] = N'imported'
WHERE [MigrationBatchId] = :batchId AND [SourceKey] = :sourceKey
SQL,
                [
                    ':targetPublicId' => $targetPublicId,
                    ':batchId' => $batchId,
                    ':sourceKey' => $product->sourceKey,
                ],
            );
        }

        return $result;
    }

    public function rollbackBatch(string $batchPublicId, bool $forceProtected = false): array
    {
        $batchId = $this->requireBatchId($batchPublicId);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [SourceKey], CONVERT(NVARCHAR(36), [TargetProductPublicId]) AS [TargetProductPublicId]
FROM [migration].[CanonicalProducts]
WHERE [MigrationBatchId] = :batchId AND [TargetProductPublicId] IS NOT NULL AND [StatusCode] = N'imported'
SQL,
            [':batchId' => $batchId],
        );

        $rolled = 0;
        $protected = 0;
        foreach ($rows as $row) {
            $sourceKey = AzureSqlValueMapper::stringValue($row['SourceKey']);
            [$system, $table, $id] = $this->splitSourceKey($sourceKey);
            $mapping = $this->findEntityMapping($system, $table !== '' ? $table : 'product', $id);
            $edited = ($mapping['manuallyEdited'] ?? false) === true;

            if ($edited && !$forceProtected) {
                ++$protected;
                continue;
            }

            if ($mapping !== null) {
                $mapping['migrationStatus'] = 'rolled-back';
                $this->upsertEntityMapping($mapping);
            }

            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[CanonicalProducts]
SET [StatusCode] = N'rolled-back'
WHERE [MigrationBatchId] = :batchId AND [SourceKey] = :sourceKey
SQL,
                [':batchId' => $batchId, ':sourceKey' => $sourceKey],
            );
            ++$rolled;
        }

        return ['rolledBack' => $rolled, 'protected' => $protected];
    }

    private function batchSelectSql(): string
    {
        return <<<'SQL'
SELECT
    b.[MigrationBatchId],
    CONVERT(NVARCHAR(36), b.[PublicId]) AS [PublicId],
    b.[Name], b.[Description], b.[SourceSystem], b.[SourceFilterJson], b.[DryRun], b.[StatusCode],
    CONVERT(VARCHAR(33), b.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    b.[CreatedByUserId],
    CONVERT(VARCHAR(33), b.[StartedAtUtc], 127) AS [StartedAtUtc],
    CONVERT(VARCHAR(33), b.[CompletedAtUtc], 127) AS [CompletedAtUtc],
    CONVERT(VARCHAR(33), b.[RolledBackAtUtc], 127) AS [RolledBackAtUtc],
    b.[RolledBackByUserId],
    b.[TotalRecords], b.[ImportedRecords], b.[UpdatedRecords], b.[SkippedRecords],
    b.[WarningRecords], b.[FailedRecords], b.[ManualReviewRecords],
    CONVERT(VARCHAR(50), b.[RowVersion], 1) AS [RowVersion]
FROM [migration].[MigrationBatches] b
SQL;
    }

    /** @param array<string, mixed> $row */
    private function hydrateBatch(array $row): MigrationBatchDetails
    {
        $filter = null;
        if (!empty($row['SourceFilterJson'])) {
            $decoded = json_decode(AzureSqlValueMapper::stringValue($row['SourceFilterJson'] ?? ''), true);
            $filter = is_array($decoded) ? $decoded : null;
        }

        return new MigrationBatchDetails(
            migrationBatchId: AzureSqlValueMapper::intValue($row['MigrationBatchId']),
            publicId: AzureSqlValueMapper::stringValue($row['PublicId']),
            name: AzureSqlValueMapper::stringValue($row['Name']),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            sourceSystem: AzureSqlValueMapper::stringValue($row['SourceSystem']),
            sourceFilter: $filter,
            dryRun: AzureSqlValueMapper::boolValue($row['DryRun']),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode']),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc']),
            createdByUserId: AzureSqlValueMapper::intValue($row['CreatedByUserId']),
            startedAtUtc: AzureSqlValueMapper::nullableString($row['StartedAtUtc'] ?? null),
            completedAtUtc: AzureSqlValueMapper::nullableString($row['CompletedAtUtc'] ?? null),
            rolledBackAtUtc: AzureSqlValueMapper::nullableString($row['RolledBackAtUtc'] ?? null),
            rolledBackByUserId: AzureSqlValueMapper::nullableInt($row['RolledBackByUserId'] ?? null),
            totalRecords: AzureSqlValueMapper::intValue($row['TotalRecords']),
            importedRecords: AzureSqlValueMapper::intValue($row['ImportedRecords']),
            updatedRecords: AzureSqlValueMapper::intValue($row['UpdatedRecords']),
            skippedRecords: AzureSqlValueMapper::intValue($row['SkippedRecords']),
            warningRecords: AzureSqlValueMapper::intValue($row['WarningRecords']),
            failedRecords: AzureSqlValueMapper::intValue($row['FailedRecords']),
            manualReviewRecords: AzureSqlValueMapper::intValue($row['ManualReviewRecords']),
            rowVersion: AzureSqlValueMapper::stringValue($row['RowVersion']),
        );
    }

    private function findIssue(string $publicId): ?MigrationIssueDetails
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), i.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), b.[PublicId]) AS [BatchPublicId],
    i.[IssueTypeCode], i.[SeverityCode], i.[SourceSystem], i.[SourceTable], i.[SourceIdentifier],
    i.[SourceKey], i.[Message], i.[SuggestedMatchJson], i.[MatchConfidence], i.[StatusCode],
    i.[ResolutionJson], CONVERT(VARCHAR(33), i.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [migration].[MigrationIssues] i
INNER JOIN [migration].[MigrationBatches] b ON b.[MigrationBatchId] = i.[MigrationBatchId]
WHERE i.[PublicId] = :publicId
SQL,
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrateIssue($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private function hydrateIssue(array $row): MigrationIssueDetails
    {
        $suggested = null;
        if (!empty($row['SuggestedMatchJson'])) {
            $decoded = json_decode(AzureSqlValueMapper::stringValue($row['SuggestedMatchJson'] ?? ''), true);
            $suggested = is_array($decoded) ? $decoded : null;
        }
        $resolution = null;
        if (!empty($row['ResolutionJson'])) {
            $decoded = json_decode(AzureSqlValueMapper::stringValue($row['ResolutionJson'] ?? ''), true);
            $resolution = is_array($decoded) ? $decoded : null;
        }

        return new MigrationIssueDetails(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId']),
            batchPublicId: AzureSqlValueMapper::stringValue($row['BatchPublicId']),
            issueTypeCode: AzureSqlValueMapper::stringValue($row['IssueTypeCode']),
            severityCode: AzureSqlValueMapper::stringValue($row['SeverityCode']),
            sourceSystem: AzureSqlValueMapper::nullableString($row['SourceSystem'] ?? null),
            sourceTable: AzureSqlValueMapper::nullableString($row['SourceTable'] ?? null),
            sourceIdentifier: AzureSqlValueMapper::nullableString($row['SourceIdentifier'] ?? null),
            sourceKey: AzureSqlValueMapper::nullableString($row['SourceKey'] ?? null),
            message: AzureSqlValueMapper::stringValue($row['Message']),
            suggestedMatch: $suggested,
            matchConfidence: AzureSqlValueMapper::nullableString($row['MatchConfidence'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode']),
            resolution: $resolution,
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc']),
        );
    }

    private function requireBatchId(string $publicId): int
    {
        $id = $this->findBatchId($publicId);
        if ($id === null) {
            throw MigrationBatchNotFound::forPublicId($publicId);
        }

        return $id;
    }

    private function findBatchId(string $publicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [MigrationBatchId] FROM [migration].[MigrationBatches] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::intValue($rows[0]['MigrationBatchId']);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function splitSourceKey(string $sourceKey): array
    {
        $parts = explode(':', $sourceKey);
        if (count($parts) >= 3) {
            return [$parts[0], $parts[1], implode(':', array_slice($parts, 2))];
        }
        if (count($parts) === 2) {
            return [$parts[0], '', $parts[1]];
        }

        return ['unknown', '', $sourceKey];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function stringifyRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $out[$k] = $v;
        }

        return MigrationPayload::assoc($out);
    }
}
