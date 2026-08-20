<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Application\Service;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkBatchPolicy;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkExecutionException;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkExecutor;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkProposedAction;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;

/**
 * Executes approved STAFF_ENTRA_LINK_ACTIVATION safe links into NEW Azure SQL only.
 */
final class StaffEntraLinkExecuteService
{
    public function __construct(
        private readonly StaffEntraLinkDryRunService $dryRunService,
        private readonly StaffEntraLinkExecutor $executor,
        private readonly LegacyMigrationStore $store,
        private readonly Settings $settings,
        private readonly ?SqlConnection $azureConnection,
    ) {
    }

    /**
     * @param array{
     *   sourceFingerprint?: string,
     *   expectedSafeLinkCount?: int,
     *   explicitApproval?: bool
     * } $command
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, array $command): array
    {
        $this->assertAzureSqlPersistence();
        $this->assertWriteInfrastructureReady();

        if (($command['explicitApproval'] ?? false) !== true) {
            throw new \InvalidArgumentException('Explicit approval is required (explicitApproval=true).');
        }

        $approvedFingerprint = trim((string) ($command['sourceFingerprint'] ?? ''));
        if ($approvedFingerprint === '') {
            throw new \InvalidArgumentException('sourceFingerprint is required.');
        }

        $expectedSafeLinkCount = (int) ($command['expectedSafeLinkCount'] ?? 0);
        if ($expectedSafeLinkCount <= 0) {
            throw new \InvalidArgumentException('expectedSafeLinkCount must be greater than zero.');
        }

        $dryRun = $this->dryRunService->run();
        $summary = is_array($dryRun['summary'] ?? null) ? $dryRun['summary'] : [];
        $currentFingerprint = (string) ($dryRun['sourceFingerprint'] ?? '');

        if ($currentFingerprint !== $approvedFingerprint) {
            throw StaffEntraLinkExecutionException::stalePreview($approvedFingerprint, $currentFingerprint);
        }

        $actualSafeCount = (int) ($summary['safeLinksToMappedUsers'] ?? 0)
            + (int) ($summary['safeLinksToExistingUsers'] ?? 0);
        if ($actualSafeCount !== $expectedSafeLinkCount) {
            throw StaffEntraLinkExecutionException::countMismatch(
                'expectedSafeLinkCount',
                $expectedSafeLinkCount,
                $actualSafeCount,
            );
        }

        $safeItems = array_values(array_filter(
            is_array($dryRun['items'] ?? null) ? $dryRun['items'] : [],
            static fn (array $item): bool => in_array(
                (string) ($item['proposedAction'] ?? ''),
                [
                    StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER,
                    StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER,
                ],
                true,
            ),
        ));

        $batch = $this->store->createBatch([
            'name' => StaffEntraLinkBatchPolicy::BATCH_NAME,
            'description' => 'Approved staff Entra linking + activation',
            'moduleCode' => StaffEntraLinkBatchPolicy::MODULE_CODE,
            'sourceSystem' => SourceCatalog::WORDPRESS,
            'sourceFilter' => [
                'batchType' => StaffEntraLinkBatchPolicy::BATCH_TYPE,
                'sourceFingerprint' => $currentFingerprint,
                'mappedUserCount' => StaffEntraLinkBatchPolicy::EXPECTED_MAPPING_COUNT,
                'safeLinkCount' => $actualSafeCount,
                'alreadyLinkedCount' => (int) ($summary['alreadyLinked'] ?? 0),
                'activationCount' => (int) ($summary['wouldActivateUsers'] ?? 0),
                'conflictCount' => (int) ($summary['conflictsExcluded'] ?? 0),
                'approvedByUserId' => $actor->userId,
                'approvedAtUtc' => gmdate('c'),
            ],
            'statusCode' => 'approved',
            'createdByUserId' => $actor->userId,
        ]);

        $batchPublicId = (string) ($batch['publicId'] ?? '');
        $this->store->updateBatch($batchPublicId, [
            'statusCode' => 'executing',
            'startedAtUtc' => gmdate('c'),
        ]);

        $execution = $this->executor->execute(
            $safeItems,
            $batchPublicId,
            $currentFingerprint,
            $actor->userId,
            (string) ($dryRun['providerCode'] ?? 'microsoft_entra'),
            trim($this->settings->entraTenantId),
        );

        $this->store->addExecution([
            'batchPublicId' => $batchPublicId,
            'executionType' => StaffEntraLinkBatchPolicy::BATCH_TYPE,
            'sourceFingerprint' => $currentFingerprint,
            'result' => $execution,
            'executedByUserId' => $actor->userId,
            'executedAtUtc' => gmdate('c'),
        ]);

        $finalStatus = ($execution['failed'] ?? 0) > 0 ? 'failed' : 'executed';
        $this->store->updateBatch($batchPublicId, [
            'statusCode' => $finalStatus,
            'completedAtUtc' => gmdate('c'),
            'importedRecords' => (int) ($execution['succeeded'] ?? 0),
            'failedRecords' => (int) ($execution['failed'] ?? 0),
            'skippedRecords' => (int) ($execution['skipped'] ?? 0),
        ]);

        $this->store->addLog([
            'batchPublicId' => $batchPublicId,
            'level' => 'info',
            'message' => 'Staff Entra link activation completed.',
            'context' => $execution,
        ]);

        return [
            'batchType' => StaffEntraLinkBatchPolicy::BATCH_TYPE,
            'batchId' => $batchPublicId,
            'batchStatus' => $finalStatus,
            'sourceFingerprint' => $currentFingerprint,
            'summary' => $summary,
            'execution' => $execution,
            'verification' => [
                'identityUsersCreated' => 0,
                'userRolesModified' => 0,
                'externalIdentitiesCreated' => (int) ($execution['externalIdentitiesCreated'] ?? 0),
                'usersActivated' => (int) ($execution['usersActivated'] ?? 0),
                'legacyWrites' => 0,
                'graphWrites' => 0,
            ],
        ];
    }

    private function assertAzureSqlPersistence(): void
    {
        $mode = strtolower((string) (getenv('LEGACY_MIGRATION_STORE') ?: ($_ENV['LEGACY_MIGRATION_STORE'] ?? '')));
        if ($mode !== 'azure-sql') {
            throw StaffEntraLinkExecutionException::persistenceModeInvalid($mode !== '' ? $mode : 'memory');
        }
    }

    private function assertWriteInfrastructureReady(): void
    {
        if (!$this->settings->usesAzureSqlUserRepository() || $this->azureConnection === null) {
            throw new \RuntimeException('Staff Entra linking execution requires USER_REPOSITORY=azure-sql.');
        }
    }
}
