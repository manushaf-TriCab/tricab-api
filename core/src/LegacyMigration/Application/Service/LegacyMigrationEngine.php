<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\LegacyMigration\Application\Exception\InvalidLegacyMigrationState;
use TriCab\Core\LegacyMigration\Application\Exception\LegacyMigrationBatchNotFound;
use TriCab\Core\LegacyMigration\Application\Exception\ProductionWriteRefused;
use TriCab\Core\LegacyMigration\Live\LegacyMigrationDevelopmentOverride;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationModuleRegistry;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Plugin\NullLegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Support\BatchStatus;
use TriCab\Core\LegacyMigration\Support\ConflictDecision;
use TriCab\Core\LegacyMigration\Support\CredentialRedactor;
use TriCab\Core\LegacyMigration\Support\ModuleCatalog;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\RawRecord;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;
use TriCab\Core\LegacyMigration\Support\UserPreviewStats;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Shared pipeline for every future legacy module. Does not write production tables.
 */
final class LegacyMigrationEngine
{
    public function __construct(
        private readonly LegacyMigrationStore $store,
        private readonly LegacyMigrationModuleRegistry $registry,
        private readonly ApplicationTransaction $transaction,
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
        private readonly LegacySourceHealthProbe $healthProbe = new NullLegacySourceHealthProbe(),
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $stats = $this->store->statisticsOverview();
        $batches = $this->store->listBatches();
        $byStatus = [];
        foreach (BatchStatus::all() as $status) {
            $byStatus[$status] = 0;
        }
        foreach ($batches as $batch) {
            $status = (string) ($batch['statusCode'] ?? '');
            if (isset($byStatus[$status])) {
                ++$byStatus[$status];
            }
        }

        $recent = array_slice($batches, 0, 10);

        return [
            'sources' => count($this->store->listSources()),
            'pending' => $byStatus[BatchStatus::CREATED] + $byStatus[BatchStatus::EXTRACTED] + $byStatus[BatchStatus::NORMALIZED],
            'valid' => $byStatus[BatchStatus::VALIDATED] + $byStatus[BatchStatus::PREVIEW] + $byStatus[BatchStatus::APPROVED],
            'conflicts' => count($this->store->listConflicts()),
            'executed' => $byStatus[BatchStatus::COMPLETED],
            'failed' => $byStatus[BatchStatus::FAILED],
            'rolledBack' => $byStatus[BatchStatus::ROLLED_BACK],
            'statusCounts' => $byStatus,
            'recentBatches' => $recent,
            'latestActivity' => array_slice($this->store->listLogs(null, 20), 0, 20),
            'successRate' => $stats['successRate'] ?? 0,
            'averageExecutionMs' => $stats['averageExecutionMs'] ?? 0,
            'dataVolume' => $stats['dataVolume'] ?? 0,
            'modules' => array_map(
                fn ($m) => [
                    'code' => $m->code(),
                    'label' => $m->label(),
                    'sourceSystem' => $m->sourceSystem(),
                    'implemented' => $m->isImplemented(),
                    'writesProductionTables' => $m->executor()->writesProductionTables(),
                    'sortOrder' => $m->sortOrder(),
                ],
                $this->registry->all(),
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        return $this->store->listSources();
    }

    /** @return array<string, mixed> */
    public function sourceHealth(string $code): array
    {
        $source = $this->store->getSource($code);
        if ($source === null) {
            return [
                'code' => $code,
                'health' => 'unknown',
                'message' => 'Source is not registered.',
                'liveConnectionAttempted' => false,
            ];
        }

        $probed = $this->healthProbe->probe($code);
        if ($probed !== null) {
            $this->store->upsertSource([
                'code' => $code,
                'health' => (string) ($probed['health'] ?? $source['health'] ?? 'unknown'),
                'version' => $probed['database'] ?? $source['version'] ?? null,
                'errors' => $probed['errors'] ?? [],
                'connection' => [
                    'configured' => (bool) ($probed['configured'] ?? false),
                    'connected' => (bool) ($probed['connected'] ?? false),
                    'ssl' => (bool) ($probed['ssl'] ?? false),
                    'readOnlyConfigured' => (bool) ($probed['readOnlyConfigured'] ?? false),
                    'readOnlyVerified' => (bool) ($probed['readOnlyVerified'] ?? false),
                    'selectOnlyGrantVerified' => (bool) ($probed['selectOnlyGrantVerified'] ?? $probed['readOnlyVerified'] ?? false),
                    'safeForExtraction' => (bool) ($probed['safeForExtraction'] ?? false),
                    'extractionAllowed' => (bool) ($probed['extractionAllowed'] ?? false),
                    'developmentOverrideEnabled' => (bool) ($probed['developmentOverrideEnabled'] ?? false),
                    'safetyLevel' => (string) ($probed['safetyLevel'] ?? LegacyMigrationDevelopmentOverride::SAFETY_BLOCKED),
                    'database' => $probed['database'] ?? null,
                    'lastCheckedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
            ]);

            return $probed + [
                'lastSyncAtUtc' => $source['lastSyncAtUtc'] ?? null,
                'recordCounts' => $source['recordCounts'] ?? [],
            ];
        }

        if ($code === SourceCatalog::MOODLE) {
            return [
                'code' => $code,
                'health' => 'skipped',
                'message' => 'Moodle is outside this phase. No live Moodle connection is opened.',
                'liveConnectionAttempted' => false,
                'configured' => false,
                'connected' => false,
                'ssl' => false,
                'readOnlyConfigured' => false,
                'readOnlyVerified' => false,
                'safeForExtraction' => false,
            ];
        }

        $kind = (string) ($source['kind'] ?? '');
        if ($kind === 'harness' || $code === SourceCatalog::FIXTURE) {
            return [
                'code' => $code,
                'health' => 'healthy',
                'message' => 'Fixture harness is local and read-only.',
                'liveConnectionAttempted' => false,
                'version' => 'framework-fixture',
                'lastSyncAtUtc' => $source['lastSyncAtUtc'] ?? null,
                'recordCounts' => $source['recordCounts'] ?? [],
                'errors' => [],
            ];
        }

        return [
            'code' => $code,
            'health' => (string) ($source['health'] ?? 'unconfigured'),
            'message' => 'Live legacy connections are not opened by the framework. Configure a read-only extractor in a future module PR.',
            'liveConnectionAttempted' => false,
            'version' => $source['version'] ?? null,
            'lastSyncAtUtc' => $source['lastSyncAtUtc'] ?? null,
            'recordCounts' => $source['recordCounts'] ?? [],
            'errors' => $source['errors'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed>|null $filter
     * @return array<string, mixed>
     */
    public function createBatch(ActorContext $actor, string $name, string $moduleCode, ?string $description = null, ?array $filter = null): array
    {
        $module = $this->registry->get($moduleCode);
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Batch name is required.');
        }

        return $this->transaction->run(function () use ($actor, $name, $module, $description, $filter): array {
            $batch = $this->store->createBatch([
                'name' => trim($name),
                'description' => $description,
                'moduleCode' => $module->code(),
                'sourceSystem' => $module->sourceSystem(),
                'sourceFilter' => $filter,
                'statusCode' => BatchStatus::CREATED,
                'createdByUserId' => $actor->userId,
            ]);
            $this->audit($actor, $batch['publicId'], 'batch-created', BatchStatus::CREATED, BatchStatus::CREATED, [
                'moduleCode' => $module->code(),
            ]);

            return $batch;
        });
    }

    /** @return list<array<string, mixed>> */
    public function listBatches(?string $statusCode = null, ?string $moduleCode = null): array
    {
        return $this->store->listBatches($statusCode, $moduleCode);
    }

    /** @return array<string, mixed> */
    public function getBatch(string $publicId): array
    {
        return $this->requireBatch($publicId);
    }

    /** @return array<string, mixed> */
    public function extract(ActorContext $actor, string $batchPublicId, bool $incremental = false): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::CREATED, BatchStatus::EXTRACTED, BatchStatus::FAILED, BatchStatus::CANCELLED], 'extract');
        $module = $this->registry->get((string) $batch['moduleCode']);
        $developmentOverrideUsed = false;
        if ($module->isImplemented() && $module->code() === ModuleCatalog::USERS) {
            $wp = $this->sourceHealth(SourceCatalog::WORDPRESS);
            $tc = $this->sourceHealth(SourceCatalog::BUSINESS_DB);
            if (empty($wp['extractionAllowed']) || empty($tc['extractionAllowed'])) {
                throw InvalidLegacyMigrationState::because(
                    'extract (' . (string) ($wp['message'] ?? $tc['message'] ?? 'legacy source not safe for extraction') . ')',
                    'blocked',
                );
            }
            $developmentOverrideUsed = !empty($wp['developmentOverrideEnabled']) || !empty($tc['developmentOverrideEnabled']);
        }
        $started = hrtime(true);

        return $this->transaction->run(function () use ($actor, $batch, $module, $incremental, $started, $developmentOverrideUsed): array {
            $forcedFull = $incremental && !$module->extractor()->supportsIncremental();
            $job = $this->store->addJob([
                'batchPublicId' => $batch['publicId'],
                'typeCode' => ($incremental && !$forcedFull) ? 'incremental-sync' : 'full-sync',
                'statusCode' => 'running',
            ]);
            $this->audit($actor, $batch['publicId'], 'extraction-started', (string) $batch['statusCode'], BatchStatus::EXTRACTED, [
                'source' => $module->sourceSystem(),
                'module' => $module->code(),
                'incrementalRequested' => $incremental,
                'incrementalHonoured' => $incremental && !$forcedFull,
            ]);
            $filter = is_array($batch['sourceFilter'] ?? null) ? $batch['sourceFilter'] : [];
            if ($incremental && !$forcedFull) {
                $filter['since'] = $batch['completedAtUtc'] ?? $batch['createdAtUtc'] ?? null;
            }

            $items = [];
            $rowsRead = 0;
            $rowsStaged = 0;
            foreach ($module->extractor()->extract($filter) as $raw) {
                if (!$raw instanceof RawRecord) {
                    continue;
                }
                ++$rowsRead;
                $payload = CredentialRedactor::strip($raw->payload);
                $rawTable = $this->rawTableFor($module->code(), $raw->recordType);
                $this->store->upsertRaw($rawTable, [
                    'batchPublicId' => $batch['publicId'],
                    'legacyId' => $raw->sourceIdentifier,
                    'sourceSystem' => $raw->sourceSystem,
                    'extractedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'originalJson' => $payload,
                    'hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'statusCode' => 'extracted',
                ]);
                ++$rowsStaged;
                if ($raw->recordType !== $module->code() && $raw->recordType !== 'users') {
                    continue;
                }
                $items[] = [
                    'sourceIdentifier' => $raw->sourceIdentifier,
                    'statusCode' => 'extracted',
                    'rawPayload' => $payload,
                    'sourceHash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                ];
            }

            $this->store->replaceItems($batch['publicId'], $items);
            $userCount = count(array_filter(
                $items,
                static fn (array $item): bool => !str_starts_with((string) ($item['sourceIdentifier'] ?? ''), '__'),
            ));
            $updated = $this->store->updateBatch($batch['publicId'], [
                'statusCode' => BatchStatus::EXTRACTED,
                'totalRecords' => $userCount,
            ]);
            $this->store->updateJob((string) $job['publicId'], ['statusCode' => 'completed']);
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->audit($actor, $batch['publicId'], 'extraction-completed', BatchStatus::CREATED, BatchStatus::EXTRACTED, [
                'source' => $module->sourceSystem(),
                'module' => $module->code(),
                'started' => true,
                'finished' => true,
                'durationMs' => $durationMs,
                'rowsRead' => $rowsRead,
                'rowsStaged' => $rowsStaged,
                'totalRecords' => $userCount,
                'incremental' => $incremental && !$forcedFull,
                'incrementalFallbackFull' => $forcedFull,
                'developmentOverrideUsed' => $developmentOverrideUsed,
                'environment' => (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')),
                'result' => 'ok',
            ]);
            $this->store->upsertSource([
                'code' => $module->isImplemented() ? $module->sourceSystem() : SourceCatalog::FIXTURE,
                'lastSyncAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                'recordCounts' => [$module->code() => $userCount],
            ]);
            if ($module->isImplemented() && $module->code() === ModuleCatalog::USERS) {
                $this->store->upsertSource([
                    'code' => SourceCatalog::WORDPRESS,
                    'lastSyncAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'recordCounts' => [$module->code() => $userCount],
                ]);
                $this->store->upsertSource([
                    'code' => SourceCatalog::BUSINESS_DB,
                    'lastSyncAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function normalize(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::EXTRACTED, BatchStatus::NORMALIZED, BatchStatus::VALIDATED], 'normalize');
        $module = $this->registry->get((string) $batch['moduleCode']);

        return $this->transaction->run(function () use ($actor, $batch, $module): array {
            $this->audit($actor, $batch['publicId'], 'normalize-started', (string) $batch['statusCode'], BatchStatus::NORMALIZED);
            $rawTable = ModuleCatalog::rawTable($module->code());
            $rawRows = $this->store->listRaw($rawTable, $batch['publicId']);
            $items = [];
            foreach ($rawRows as $row) {
                $payload = is_array($row['originalJson'] ?? null) ? $row['originalJson'] : [];
                $raw = new RawRecord(
                    sourceSystem: (string) ($row['sourceSystem'] ?? 'fixture'),
                    sourceTable: $rawTable,
                    sourceIdentifier: (string) ($row['legacyId'] ?? ''),
                    payload: $payload,
                    recordType: $module->code(),
                );
                $normalized = $module->normalizer()->normalize($raw);
                $items[] = [
                    'sourceIdentifier' => $normalized->sourceIdentifier,
                    'statusCode' => 'normalized',
                    'rawPayload' => $normalized->rawPayload,
                    'canonical' => $normalized->canonical,
                    'sourceHash' => $normalized->sourceHash,
                ];
            }
            $this->store->replaceItems($batch['publicId'], $items);
            $updated = $this->store->updateBatch($batch['publicId'], ['statusCode' => BatchStatus::NORMALIZED]);
            $this->audit($actor, $batch['publicId'], 'normalize-completed', BatchStatus::EXTRACTED, BatchStatus::NORMALIZED);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function validate(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::NORMALIZED, BatchStatus::VALIDATED, BatchStatus::PREVIEW], 'validate');
        $module = $this->registry->get((string) $batch['moduleCode']);

        return $this->transaction->run(function () use ($actor, $batch, $module): array {
            $records = $this->normalizedFromItems($batch['publicId'], $module->code());
            $findings = [];
            foreach ($module->validators() as $validator) {
                foreach ($validator->validate($records) as $finding) {
                    $findings[] = $finding;
                }
            }

            $validation = [];
            $warnings = [];
            $conflicts = [];
            foreach ($findings as $finding) {
                $row = [
                    'severityCode' => $finding->severity,
                    'errorCode' => $finding->errorCode,
                    'description' => $finding->description,
                    'sourceIdentifier' => $finding->sourceIdentifier,
                    'recommendation' => $finding->recommendation,
                ];
                $validation[] = $row;
                if ($finding->isConflict) {
                    $conflicts[] = $row + [
                        'conflictType' => $finding->errorCode,
                        'decisionCode' => null,
                        'statusCode' => ConflictDecision::STATUS_OPEN,
                    ];
                } elseif ($finding->severity === 'warning') {
                    $warnings[] = $row;
                }
            }

            $this->store->replaceValidation($batch['publicId'], $validation);
            $this->store->replaceWarnings($batch['publicId'], $warnings);
            $this->store->replaceConflicts($batch['publicId'], $this->mergeConflicts($batch['publicId'], $conflicts));
            $errorCount = count(array_filter($findings, static fn ($f) => $f->severity === 'error' && !$f->isConflict));
            $updated = $this->store->updateBatch($batch['publicId'], [
                'statusCode' => BatchStatus::VALIDATED,
                'warningRecords' => count($warnings),
                'failedRecords' => $errorCount,
                'manualReviewRecords' => count($conflicts),
            ]);
            $this->audit($actor, $batch['publicId'], 'validate-completed', BatchStatus::NORMALIZED, BatchStatus::VALIDATED, [
                'findings' => count($findings),
                'conflicts' => count($conflicts),
            ]);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function preview(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::VALIDATED, BatchStatus::PREVIEW], 'preview');
        $module = $this->registry->get((string) $batch['moduleCode']);
        $items = $this->store->listItems($batch['publicId']);
        $conflicts = $this->store->listConflicts($batch['publicId']);
        $unresolved = array_values(array_filter(
            $conflicts,
            static fn (array $c): bool => self::conflictIsBlocking($c),
        ));
        $errors = array_values(array_filter(
            $this->store->listValidation($batch['publicId']),
            static fn (array $v): bool => ($v['severityCode'] ?? '') === 'error',
        ));

        $insertable = 0;
        foreach ($items as $item) {
            $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : [];
            $email = $canonical['email'] ?? 'x';
            if ($email !== '' && !str_starts_with((string) ($item['sourceIdentifier'] ?? ''), '__')) {
                ++$insertable;
            }
        }

        $userStats = $module->code() === ModuleCatalog::USERS
            ? UserPreviewStats::aggregate(
                $items,
                $this->store->listValidation($batch['publicId']),
                $conflicts,
                $this->store->listWarnings($batch['publicId']),
            )
            : null;

        $report = [
            'batchPublicId' => $batch['publicId'],
            'moduleCode' => $module->code(),
            'summary' => [
                'inserted' => $insertable,
                'updated' => 0,
                'skipped' => max(0, count(UserPreviewStats::userItems($items)) - $insertable),
                'warnings' => count($this->store->listWarnings($batch['publicId'])),
                'errors' => count($errors),
                'conflicts' => count($unresolved),
            ],
            'userStats' => $userStats,
            'estimatedExecutionMs' => max(50, count($items) * 5),
            'estimatedRollbackSize' => $insertable,
            'wouldWriteProductionTables' => $module->executor()->writesProductionTables(),
            'blocking' => count($unresolved) > 0 || $module->executor()->writesProductionTables(),
            'requiresApproval' => true,
            'productionUsersModified' => false,
            'legacyDatabaseModified' => false,
        ];

        $this->transaction->run(function () use ($actor, $batch, $report): void {
            $this->store->saveReport([
                'batchPublicId' => $batch['publicId'],
                'reportType' => 'preview',
                'payload' => $report,
            ]);
            $this->store->updateBatch($batch['publicId'], ['statusCode' => BatchStatus::PREVIEW]);
            $this->audit($actor, $batch['publicId'], 'preview-generated', BatchStatus::VALIDATED, BatchStatus::PREVIEW, [
                'summary' => $report['summary'],
            ]);
        });

        return $report;
    }

    /** @return array<string, mixed> */
    public function approve(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::PREVIEW], 'approve');
        $module = $this->registry->get((string) $batch['moduleCode']);
        if ($module->executor()->writesProductionTables()) {
            throw ProductionWriteRefused::because('Cannot approve a module that writes production tables until that module is implemented.');
        }
        $unresolved = array_values(array_filter(
            $this->store->listConflicts($batch['publicId']),
            static fn (array $c): bool => self::conflictIsBlocking($c),
        ));
        if ($unresolved !== []) {
            throw InvalidLegacyMigrationState::because('approve (unresolved conflicts)', (string) $batch['statusCode']);
        }

        return $this->transaction->run(function () use ($actor, $batch): array {
            $updated = $this->store->updateBatch($batch['publicId'], [
                'statusCode' => BatchStatus::APPROVED,
                'approvedByUserId' => $actor->userId,
                'approvedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            $this->audit($actor, $batch['publicId'], 'approved', BatchStatus::PREVIEW, BatchStatus::APPROVED);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::APPROVED, BatchStatus::FAILED], 'execute');
        $module = $this->registry->get((string) $batch['moduleCode']);
        if ($module->executor()->writesProductionTables()) {
            throw ProductionWriteRefused::because('Legacy migration framework will not write production tables.');
        }
        if (!$this->store->tryLock($batch['publicId'], $actor->publicId, 120)) {
            throw InvalidLegacyMigrationState::because('execute (locked)', (string) $batch['statusCode']);
        }

        $started = hrtime(true);
        try {
            return $this->transaction->run(function () use ($actor, $batch, $module, $started): array {
                $this->store->updateBatch($batch['publicId'], [
                    'statusCode' => BatchStatus::EXECUTING,
                    'startedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $this->audit($actor, $batch['publicId'], 'execution-started', BatchStatus::APPROVED, BatchStatus::EXECUTING);

                $records = $this->normalizedFromItems($batch['publicId'], $module->code());
                $imported = 0;
                $skipped = 0;
                foreach ($records as $record) {
                    $mapped = $module->mapper()->map($record);
                    $result = $module->executor()->execute($record, $mapped);
                    if (($result['action'] ?? '') === 'snapshot') {
                        $this->store->saveSnapshot([
                            'batchPublicId' => $batch['publicId'],
                            'moduleCode' => $module->code(),
                            'legacyId' => $record->sourceIdentifier,
                            'payload' => $result['snapshot'],
                        ]);
                        ++$imported;
                    } else {
                        ++$skipped;
                    }
                    $this->store->addExecution([
                        'batchPublicId' => $batch['publicId'],
                        'legacyId' => $record->sourceIdentifier,
                        'actionCode' => (string) ($result['action'] ?? 'skip'),
                        'resultCode' => 'ok',
                    ]);
                }

                $verify = $module->verifier()->verify($this->store->listSnapshots($batch['publicId']));
                $duration = (int) ((hrtime(true) - $started) / 1_000_000);
                $updated = $this->store->updateBatch($batch['publicId'], [
                    'statusCode' => BatchStatus::COMPLETED,
                    'completedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'importedRecords' => $imported,
                    'skippedRecords' => $skipped,
                ]);
                $this->store->recordStatistic([
                    'batchPublicId' => $batch['publicId'],
                    'success' => true,
                    'durationMs' => $duration,
                    'recordCount' => $imported,
                ]);
                $this->store->saveReport([
                    'batchPublicId' => $batch['publicId'],
                    'reportType' => 'execution',
                    'payload' => [
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'durationMs' => $duration,
                        'verification' => $verify,
                        'wroteProductionTables' => false,
                    ],
                ]);
                $this->audit($actor, $batch['publicId'], 'execution-completed', BatchStatus::EXECUTING, BatchStatus::COMPLETED, [
                    'imported' => $imported,
                    'durationMs' => $duration,
                ]);

                return $updated;
            });
        } catch (\Throwable $e) {
            $this->store->updateBatch($batch['publicId'], ['statusCode' => BatchStatus::FAILED]);
            $this->audit($actor, $batch['publicId'], 'execution-failed', BatchStatus::EXECUTING, BatchStatus::FAILED, [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $this->store->releaseLock($batch['publicId'], $actor->publicId);
        }
    }

    /** @return array<string, mixed> */
    public function rollback(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::COMPLETED, BatchStatus::EXECUTING], 'rollback');

        return $this->transaction->run(function () use ($actor, $batch): array {
            $removed = $this->store->deleteSnapshots($batch['publicId']);
            $this->store->addRollback([
                'batchPublicId' => $batch['publicId'],
                'removedSnapshots' => $removed,
                'actorUserId' => $actor->userId,
                'scope' => 'single-batch',
            ]);
            $updated = $this->store->updateBatch($batch['publicId'], [
                'statusCode' => BatchStatus::ROLLED_BACK,
                'rolledBackAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
                'rolledBackByUserId' => $actor->userId,
            ]);
            $this->store->saveReport([
                'batchPublicId' => $batch['publicId'],
                'reportType' => 'rollback',
                'payload' => ['removedSnapshots' => $removed, 'productionTablesTouched' => false],
            ]);
            $this->audit($actor, $batch['publicId'], 'rolled-back', (string) $batch['statusCode'], BatchStatus::ROLLED_BACK, [
                'removedSnapshots' => $removed,
            ]);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function cancel(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [
            BatchStatus::CREATED,
            BatchStatus::EXTRACTED,
            BatchStatus::NORMALIZED,
            BatchStatus::VALIDATED,
            BatchStatus::PREVIEW,
            BatchStatus::APPROVED,
        ], 'cancel');

        return $this->transaction->run(function () use ($actor, $batch): array {
            $updated = $this->store->updateBatch($batch['publicId'], ['statusCode' => BatchStatus::CANCELLED]);
            $this->audit($actor, $batch['publicId'], 'cancelled', (string) $batch['statusCode'], BatchStatus::CANCELLED);

            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function retry(ActorContext $actor, string $batchPublicId): array
    {
        $batch = $this->requireBatch($batchPublicId);
        $this->assertStatus($batch, [BatchStatus::FAILED, BatchStatus::CANCELLED], 'retry');

        return $this->transaction->run(function () use ($actor, $batch): array {
            $updated = $this->store->updateBatch($batch['publicId'], ['statusCode' => BatchStatus::CREATED]);
            $this->audit($actor, $batch['publicId'], 'retried', (string) $batch['statusCode'], BatchStatus::CREATED);

            return $updated;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConflict(ActorContext $actor, string $conflictPublicId, string $decision, ?string $mapping = null): array
    {
        if (!in_array($decision, ConflictDecision::all(), true)) {
            throw new \InvalidArgumentException('Unsupported conflict decision.');
        }
        $this->store->updateConflict($conflictPublicId, [
            'decisionCode' => $decision,
            'statusCode' => ConflictDecision::statusForDecision($decision),
            'manualMapping' => $mapping,
            'resolvedByUserId' => $actor->userId,
            'resolvedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->audit($actor, null, 'conflict-resolved', null, $decision, [
            'conflictPublicId' => $conflictPublicId,
        ]);

        return [
            'publicId' => $conflictPublicId,
            'decisionCode' => $decision,
            'manualMapping' => $mapping,
        ];
    }

    /** @return array<string, mixed> */
    public function report(string $batchPublicId, string $type, string $format = 'json'): array
    {
        $reports = $this->store->listReports($batchPublicId);
        $match = null;
        foreach ($reports as $report) {
            if (($report['reportType'] ?? '') === $type) {
                $match = $report;
            }
        }
        $payload = is_array($match['payload'] ?? null) ? $match['payload'] : ['batchPublicId' => $batchPublicId, 'reportType' => $type];
        if ($format === 'csv') {
            return ['format' => 'csv', 'csv' => $this->toCsv($payload), 'report' => $payload];
        }
        if ($format === 'excel') {
            return ['format' => 'excel', 'note' => 'Excel uses CSV-compatible rows in this framework version.', 'csv' => $this->toCsv($payload), 'report' => $payload];
        }
        if ($format === 'pdf') {
            return ['format' => 'pdf', 'note' => 'PDF export is queued as JSON payload until document generation is wired.', 'report' => $payload];
        }

        return ['format' => 'json', 'report' => $payload];
    }

    /** @return list<array<string, mixed>> */
    public function logs(?string $batchPublicId = null): array
    {
        return $this->store->listLogs($batchPublicId);
    }

    /** @return array<string, mixed> */
    public function statistics(): array
    {
        return $this->store->statisticsOverview();
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        return $this->store->allSettings();
    }

    public function updateSetting(ActorContext $actor, string $key, string $value): void
    {
        $this->store->setSetting($key, $value);
        $this->audit($actor, null, 'setting-updated', null, $key, ['key' => $key]);
    }

    /** @return list<array<string, mixed>> */
    public function listItems(string $batchPublicId): array
    {
        $this->requireBatch($batchPublicId);
        $out = [];
        foreach ($this->store->listItems($batchPublicId) as $item) {
            $out[] = CredentialRedactor::strip([
                'publicId' => $item['publicId'] ?? null,
                'sourceIdentifier' => $item['sourceIdentifier'] ?? null,
                'statusCode' => $item['statusCode'] ?? null,
                'canonical' => is_array($item['canonical'] ?? null) ? $item['canonical'] : [],
                'rawPayload' => is_array($item['rawPayload'] ?? null) ? $item['rawPayload'] : [],
                'sourceHash' => $item['sourceHash'] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function userWorkspace(?string $batchPublicId = null, array $query = []): array
    {
        $batch = $this->latestUsersBatch($batchPublicId);
        if ($batch === null) {
            return [
                'batch' => null,
                'stats' => UserPreviewStats::aggregate([], [], [], []),
                'items' => [],
                'conflicts' => [],
                'extractionMode' => 'SELECT ONLY',
                'destination' => 'Migration staging',
                'productionUsersModified' => false,
                'legacyDatabaseModified' => false,
                'framework' => self::frameworkContext(),
            ];
        }
        $id = (string) $batch['publicId'];
        $items = $this->listItems($id);
        $validation = $this->store->listValidation($id);
        $conflicts = $this->store->listConflicts($id);
        $warnings = $this->store->listWarnings($id);
        $stats = UserPreviewStats::aggregate($items, $validation, $conflicts, $warnings);
        $filtered = $this->filterUserItems($items, $validation, $conflicts, $query);

        return [
            'batch' => $batch,
            'stats' => $stats,
            'items' => $filtered,
            'conflicts' => $conflicts,
            'extractionMode' => 'SELECT ONLY',
            'destination' => 'Migration staging',
            'productionUsersModified' => false,
            'legacyDatabaseModified' => false,
            'framework' => self::frameworkContext(),
        ];
    }

    /** @return array<string, mixed> */
    private static function frameworkContext(): array
    {
        $mode = strtolower((string) (getenv('LEGACY_MIGRATION_STORE') ?: ($_ENV['LEGACY_MIGRATION_STORE'] ?? 'memory')));
        if ($mode === '') {
            $mode = 'memory';
        }
        $memory = $mode === 'memory';

        return [
            'persistenceMode' => strtoupper($mode),
            'persistenceWarning' => $memory
                ? 'Migration batches will disappear when the API process restarts.'
                : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function conflicts(?string $batchPublicId = null): array
    {
        return $this->store->listConflicts($batchPublicId);
    }

    /** @return list<NormalizedRecord> */
    private function normalizedFromItems(string $batchPublicId, string $moduleCode): array
    {
        $out = [];
        foreach ($this->store->listItems($batchPublicId) as $item) {
            $canonical = is_array($item['canonical'] ?? null)
                ? $item['canonical']
                : (is_array($item['rawPayload'] ?? null) ? $item['rawPayload'] : []);
            $out[] = new NormalizedRecord(
                sourceIdentifier: (string) ($item['sourceIdentifier'] ?? ''),
                moduleCode: $moduleCode,
                canonical: $canonical,
                rawPayload: is_array($item['rawPayload'] ?? null) ? $item['rawPayload'] : $canonical,
                sourceHash: (string) ($item['sourceHash'] ?? ''),
            );
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function requireBatch(string $publicId): array
    {
        $batch = $this->store->getBatch($publicId);
        if ($batch === null) {
            throw LegacyMigrationBatchNotFound::forPublicId($publicId);
        }

        return $batch;
    }

    /**
     * @param list<string> $allowed
     */
    private function assertStatus(array $batch, array $allowed, string $action): void
    {
        $status = (string) ($batch['statusCode'] ?? '');
        if (!in_array($status, $allowed, true)) {
            throw InvalidLegacyMigrationState::because($action, $status);
        }
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function audit(
        ActorContext $actor,
        ?string $batchPublicId,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?array $details = null,
    ): void {
        $durationMs = 0;
        if (is_array($details) && isset($details['durationMs'])) {
            $durationMs = (int) $details['durationMs'];
        }
        $this->store->addLog([
            'batchPublicId' => $batchPublicId,
            'actorUserId' => $actor->userId,
            'actorPublicId' => $actor->publicId,
            'ipAddress' => $actor->ipAddress,
            'actionCode' => $action,
            'resultCode' => 'ok',
            'oldStatus' => $oldStatus,
            'newStatus' => $newStatus,
            'durationMs' => $durationMs,
            'details' => is_array($details) ? CredentialRedactor::strip($details) : $details,
            'occurredAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeConflicts(string $batchPublicId, array $incoming): array
    {
        $existing = [];
        foreach ($this->store->listConflicts($batchPublicId) as $row) {
            $key = strtolower((string) ($row['conflictType'] ?? '') . '|' . (string) ($row['sourceIdentifier'] ?? ''));
            $existing[$key] = $row;
        }
        $out = [];
        foreach ($incoming as $row) {
            $key = strtolower((string) ($row['conflictType'] ?? '') . '|' . (string) ($row['sourceIdentifier'] ?? ''));
            $prior = $existing[$key] ?? null;
            if (is_array($prior) && ($prior['decisionCode'] ?? null) !== null) {
                $row['publicId'] = $prior['publicId'] ?? null;
                $row['decisionCode'] = $prior['decisionCode'];
                $row['statusCode'] = $prior['statusCode'] ?? ConflictDecision::statusForDecision((string) $prior['decisionCode']);
                $row['manualMapping'] = $prior['manualMapping'] ?? null;
                $row['resolvedByUserId'] = $prior['resolvedByUserId'] ?? null;
                $row['resolvedAtUtc'] = $prior['resolvedAtUtc'] ?? null;
            } else {
                $row['statusCode'] = $row['statusCode'] ?? ConflictDecision::STATUS_OPEN;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $conflict */
    private static function conflictIsBlocking(array $conflict): bool
    {
        $status = strtolower((string) ($conflict['statusCode'] ?? ''));
        if ($status === ConflictDecision::STATUS_BLOCKED) {
            return true;
        }
        $decision = $conflict['decisionCode'] ?? null;

        return $decision === null || $decision === '';
    }

    private function rawTableFor(string $moduleCode, string $recordType): string
    {
        return match ($recordType) {
            'users' => 'LegacyUsersRaw',
            'user-meta', 'user-meta-inventory' => 'LegacyUserMetaRaw',
            'employees' => 'LegacyEmployeesRaw',
            'contacts' => 'LegacyContactsRaw',
            'roles' => 'LegacyRolesRaw',
            default => ModuleCatalog::rawTable($moduleCode),
        };
    }

    /** @return array<string, mixed>|null */
    private function latestUsersBatch(?string $batchPublicId): ?array
    {
        if ($batchPublicId !== null && $batchPublicId !== '') {
            return $this->requireBatch($batchPublicId);
        }
        $batches = $this->store->listBatches(null, ModuleCatalog::USERS);

        return $batches[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $validation
     * @param list<array<string, mixed>> $conflicts
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function filterUserItems(array $items, array $validation, array $conflicts, array $query): array
    {
        $needle = strtolower(trim((string) ($query['q'] ?? '')));
        $filter = strtolower(trim((string) ($query['filter'] ?? 'all')));
        $byValidation = [];
        foreach ($validation as $row) {
            $byValidation[(string) ($row['sourceIdentifier'] ?? '')][] = $row;
        }
        $blocking = [];
        foreach ($conflicts as $row) {
            if (self::conflictIsBlocking($row)) {
                $blocking[(string) ($row['sourceIdentifier'] ?? '')] = true;
            }
        }
        $out = [];
        foreach (UserPreviewStats::userItems($items) as $item) {
            $canonical = UserPreviewStats::canonical($item);
            $id = (string) ($item['sourceIdentifier'] ?? '');
            $haystack = strtolower(implode(' ', [
                (string) ($canonical['legacyWordPressUserId'] ?? $id),
                (string) ($canonical['displayName'] ?? ''),
                (string) ($canonical['legacyLogin'] ?? ''),
                (string) ($canonical['email'] ?? ''),
            ]));
            if ($needle !== '' && !str_contains($haystack, $needle)) {
                continue;
            }
            $tags = is_array($canonical['classificationTags'] ?? null) ? $canonical['classificationTags'] : [];
            $codes = array_map(
                static fn (array $f): string => (string) ($f['errorCode'] ?? ''),
                $byValidation[$id] ?? [],
            );
            $severities = array_map(
                static fn (array $f): string => (string) ($f['severityCode'] ?? ''),
                $byValidation[$id] ?? [],
            );
            $match = match ($filter) {
                '', 'all' => true,
                'ready' => !isset($blocking[$id]) && !in_array('error', $severities, true),
                'warnings' => in_array('warning', $severities, true),
                'conflicts', 'blocked' => isset($blocking[$id]),
                'staff' => in_array('STAFF_MICROSOFT', $tags, true) || in_array('STAFF_OTHER', $tags, true),
                'customers' => in_array('CUSTOMER', $tags, true),
                'customer-contacts', 'customer_contacts' => in_array('CUSTOMER_CONTACT', $tags, true),
                'ali-flex', 'aliflex' => !empty($canonical['hasAliFlexRecords']),
                'missing-email' => in_array('MISSING_EMAIL', $codes, true),
                'duplicate-email' => in_array('DUPLICATE_NORMALIZED_EMAIL', $codes, true) || in_array('DUPLICATE_EMAIL', $codes, true),
                'unknown-role' => in_array('UNKNOWN_ROLE', $codes, true),
                'entra-required' => in_array('ENTRA_LINK_REQUIRED', $codes, true) || !empty($canonical['entraLinkRequired']),
                default => true,
            };
            if (!$match) {
                continue;
            }
            $issues = array_values(array_filter($codes, static fn (string $c): bool => $c !== ''));
            $out[] = $item + [
                'issues' => $issues,
                'blocked' => isset($blocking[$id]),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function toCsv(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : $payload;
        $lines = ['key,value'];
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $lines[] = sprintf('%s,%s', $key, $value);
        }

        return implode("\n", $lines);
    }
}
