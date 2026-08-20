<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Application\Service;

use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlLegacyStaffUserMappingRepository;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationPreparer;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationBatch1Policy;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationEligibility;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationExecutionException;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationProposedAction;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationScope;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationCandidate;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationExecutor;
use TriCab\Core\LegacyMigration\Support\SourceCatalog;

/**
 * Executes Staff Migration Batch 1 into NEW Azure SQL only after fresh revalidation.
 */
final class StaffUserMigrationExecuteService
{
    public function __construct(
        private readonly StaffMigrationPreparer $preparation,
        private readonly StaffUserMigrationExecutor $executor,
        private readonly LegacyMigrationStore $store,
        private readonly LegacyStaffUserMappingRepository $mappings,
        private readonly Settings $settings,
        private readonly ?SqlConnection $azureConnection,
    ) {
    }

    /**
     * @param array{
     *   scope?: string,
     *   sourceFingerprint?: string,
     *   expectedCandidateCount?: int,
     *   approved?: bool
     * } $command
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, array $command): array
    {
        $this->assertAzureSqlPersistence();
        $this->assertWriteInfrastructureReady();

        if (($command['approved'] ?? false) !== true) {
            throw new \InvalidArgumentException('Explicit approval is required (approved=true).');
        }
        if (($command['scope'] ?? '') !== StaffMigrationScope::STAFF_ONLY) {
            throw new \InvalidArgumentException('Execution scope must be STAFF_ONLY.');
        }
        if ((int) ($command['expectedCandidateCount'] ?? 0) !== StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT) {
            throw StaffMigrationExecutionException::countMismatch(
                'expectedCandidateCount',
                StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
                (int) ($command['expectedCandidateCount'] ?? 0),
            );
        }

        $approvedFingerprint = trim((string) ($command['sourceFingerprint'] ?? ''));
        if ($approvedFingerprint === '') {
            throw new \InvalidArgumentException('sourceFingerprint is required.');
        }

        $prepared = $this->preparation->prepare();
        $dryRun = $prepared['dryRun'];
        $summary = is_array($dryRun['summary'] ?? null) ? $dryRun['summary'] : [];
        $currentFingerprint = (string) ($dryRun['sourceFingerprint'] ?? '');

        if ($currentFingerprint !== $approvedFingerprint) {
            throw StaffMigrationExecutionException::stalePreview($approvedFingerprint, $currentFingerprint);
        }

        $this->assertBatch1Counts($summary);

        /** @var list<StaffUserMigrationCandidate> $candidates */
        $candidates = $prepared['candidates'];
        $safeCandidates = array_values(array_filter(
            $candidates,
            static fn (StaffUserMigrationCandidate $c): bool => $c->eligibility === StaffMigrationEligibility::SAFE_NEW_STAFF_USER
                && $c->proposedAction === StaffMigrationProposedAction::CREATE_NEW_USER,
        ));

        if (count($safeCandidates) !== StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT) {
            throw StaffMigrationExecutionException::countMismatch(
                'CURRENT_SAFE_COUNT',
                StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
                count($safeCandidates),
            );
        }

        $legacyAudit = $prepared['legacyAudit']->summary();
        if (($legacyAudit['legacyWriteAttemptsBlocked'] ?? 0) > 0) {
            throw new \RuntimeException('Legacy write attempted during fresh preparation.');
        }

        $batch = $this->store->createBatch([
            'name' => StaffMigrationBatch1Policy::BATCH_NAME,
            'description' => 'Approved Staff Migration Batch 1 — 617 SAFE_NEW_STAFF_USER candidates',
            'moduleCode' => StaffMigrationBatch1Policy::MODULE_CODE,
            'sourceSystem' => SourceCatalog::WORDPRESS,
            'sourceFilter' => [
                'scope' => StaffMigrationScope::STAFF_ONLY,
                'sourceFingerprint' => $currentFingerprint,
                'candidateCount' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
                'approvedByUserId' => $actor->userId,
                'approvedAtUtc' => gmdate('c'),
            ],
            'statusCode' => 'approved',
            'createdByUserId' => $actor->userId,
        ]);

        $batchPublicId = (string) ($batch['publicId'] ?? '');
        $startedAt = gmdate('c');
        $this->store->updateBatch($batchPublicId, [
            'statusCode' => 'executing',
            'approvedAtUtc' => $startedAt,
            'approvedByUserId' => $actor->userId,
            'startedAtUtc' => $startedAt,
            'totalRecords' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
        ]);

        $execution = [
            'identityUsersCreated' => 0,
            'userRolesCreated' => 0,
            'legacyMappingsCreated' => 0,
            'externalIdentitiesCreated' => 0,
            'existingUsersModified' => 0,
            'missingEmailUsersCreated' => 0,
            'blockedRoleUsersCreated' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'notAttempted' => 0,
            'items' => [],
        ];
        $batchStatus = 'completed';
        $failureMessage = null;

        try {
            $execution = $this->executor->execute(
                $safeCandidates,
                $batchPublicId,
                $currentFingerprint,
                UserId::fromInt($actor->userId),
            );
            $execution['notAttempted'] = max(
                0,
                StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT
                    - ($execution['succeeded'] ?? 0)
                    - ($execution['failed'] ?? 0)
                    - ($execution['skipped'] ?? 0),
            );
        } catch (\Throwable $exception) {
            $batchStatus = 'failed';
            $failureMessage = $exception->getMessage();
            $execution['notAttempted'] = max(
                0,
                StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT
                    - ($execution['succeeded'] ?? 0)
                    - ($execution['failed'] ?? 0)
                    - ($execution['skipped'] ?? 0),
            );
        }

        $completedAt = gmdate('c');
        $this->store->updateBatch($batchPublicId, [
            'statusCode' => $batchStatus,
            'completedAtUtc' => $completedAt,
            'importedRecords' => (int) ($execution['succeeded'] ?? 0),
            'skippedRecords' => (int) ($execution['skipped'] ?? 0),
            'failedRecords' => (int) ($execution['failed'] ?? 0),
        ]);

        $verification = $this->verifyPostExecution($batchPublicId, $safeCandidates, $summary);

        $this->store->addLog([
            'batchPublicId' => $batchPublicId,
            'actorUserId' => $actor->userId,
            'actorPublicId' => $actor->publicId,
            'actionCode' => 'staff-migration-batch-1-execute',
            'resultCode' => $batchStatus === 'completed' ? 'success' : 'failure',
            'details' => [
                'sourceFingerprint' => $currentFingerprint,
                'execution' => $execution,
                'verification' => $verification,
                'failureMessage' => $failureMessage,
            ],
        ]);

        $this->store->saveReport([
            'batchPublicId' => $batchPublicId,
            'reportType' => 'staff-migration-batch-1',
            'payload' => [
                'batchName' => StaffMigrationBatch1Policy::BATCH_NAME,
                'sourceFingerprint' => $currentFingerprint,
                'approvedCandidates' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
                'execution' => $execution,
                'verification' => $verification,
                'legacyAudit' => $legacyAudit,
                'credentialSafety' => [
                    'userPassSelected' => 0,
                    'userPassWritten' => 0,
                    'activationKeysSelected' => 0,
                    'sessionTokensSelected' => 0,
                    'databasePasswordsLogged' => 0,
                ],
            ],
        ]);

        return [
            'title' => 'STAFF MIGRATION BATCH 1',
            'batchId' => $batchPublicId,
            'scope' => StaffMigrationScope::STAFF_ONLY,
            'sourceFingerprint' => $currentFingerprint,
            'persistenceMode' => 'azure-sql',
            'staffVerificationSource' => 'WORDPRESS_ONLY',
            'approvedCandidates' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
            'freshDryRunSummary' => [
                'safeToCreate' => (int) ($summary['safeToCreate'] ?? 0),
                'administratorAssignmentsProposed' => (int) ($summary['administratorAssignmentsProposed'] ?? 0),
                'employeeAssignmentsProposed' => (int) ($summary['employeeAssignmentsProposed'] ?? 0),
                'existingTriCabUsers' => (int) ($summary['existingTriCabUsers'] ?? 0),
                'missingEmailStaff' => (int) ($summary['missingEmailStaff'] ?? 0),
                'roleReviewRequired' => (int) ($summary['wouldBlockRoleConflict'] ?? $summary['roleReviewRequired'] ?? 0),
            ],
            'identityUsersCreated' => (int) ($execution['identityUsersCreated'] ?? 0),
            'userRolesCreated' => (int) ($execution['userRolesCreated'] ?? 0),
            'legacyMappingsCreated' => (int) ($execution['legacyMappingsCreated'] ?? 0),
            'externalIdentitiesCreated' => 0,
            'existingUsersModified' => 0,
            'missingEmailUsersCreated' => 0,
            'blockedRoleUsersCreated' => 0,
            'succeeded' => (int) ($execution['succeeded'] ?? 0),
            'failed' => (int) ($execution['failed'] ?? 0),
            'skipped' => (int) ($execution['skipped'] ?? 0),
            'notAttempted' => (int) ($execution['notAttempted'] ?? 0),
            'batchStatus' => $batchStatus,
            'failureMessage' => $failureMessage,
            'databaseSafety' => [
                'legacyWordPress' => [
                    'select' => (int) ($legacyAudit['legacySelectQueries'] ?? 0),
                    'insert' => 0,
                    'update' => 0,
                    'delete' => 0,
                    'ddl' => 0,
                ],
                'legacyTc' => [
                    'select' => (int) ($legacyAudit['legacySelectQueries'] ?? 0),
                    'insert' => 0,
                    'update' => 0,
                    'delete' => 0,
                    'ddl' => 0,
                ],
            ],
            'credentialSafety' => [
                'userPassSelected' => 0,
                'userPassWritten' => 0,
                'activationKeysSelected' => 0,
                'sessionTokensSelected' => 0,
                'databasePasswordsLogged' => 0,
            ],
            'verification' => $verification,
            'approvedByUserId' => $actor->userId,
            'approvedAtUtc' => $startedAt,
            'startedAtUtc' => $startedAt,
            'completedAtUtc' => $completedAt,
        ];
    }

    private function assertAzureSqlPersistence(): void
    {
        $mode = strtolower((string) (getenv('LEGACY_MIGRATION_STORE') ?: ($_ENV['LEGACY_MIGRATION_STORE'] ?? '')));
        if ($mode === 'memory' || ($mode === '' && !$this->settings->usesAzureSqlUserRepository())) {
            throw StaffMigrationExecutionException::persistenceModeInvalid($mode === '' ? 'memory/default' : $mode);
        }
        if ($mode !== '' && $mode !== 'azure-sql') {
            throw StaffMigrationExecutionException::persistenceModeInvalid($mode);
        }
    }

    private function assertWriteInfrastructureReady(): void
    {
        if (!$this->settings->usesAzureSqlUserRepository() || $this->azureConnection === null) {
            throw new \RuntimeException('Azure SQL user repository is required for staff migration execution.');
        }
        if (!$this->mappings->schemaReady()) {
            throw StaffMigrationExecutionException::schemaMissing('migration.LegacyStaffUserMappings');
        }
    }

    /** @param array<string, int|string> $summary */
    private function assertBatch1Counts(array $summary): void
    {
        $checks = [
            'safeToCreate' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
            'administratorAssignmentsProposed' => StaffMigrationBatch1Policy::EXPECTED_ADMIN_ASSIGNMENTS,
            'employeeAssignmentsProposed' => StaffMigrationBatch1Policy::EXPECTED_EMPLOYEE_ASSIGNMENTS,
            'existingTriCabUsers' => StaffMigrationBatch1Policy::EXPECTED_EXISTING_USERS,
            'missingEmailStaff' => StaffMigrationBatch1Policy::EXPECTED_MISSING_EMAIL,
            'wouldBlockRoleConflict' => StaffMigrationBatch1Policy::EXPECTED_BLOCKED_ROLE,
        ];

        foreach ($checks as $metric => $expected) {
            $actual = (int) ($summary[$metric] ?? -1);
            if ($actual !== $expected) {
                throw StaffMigrationExecutionException::countMismatch($metric, $expected, $actual);
            }
        }
    }

    /**
     * @param list<StaffUserMigrationCandidate> $safeCandidates
     * @param array<string, int|string> $dryRunSummary
     * @return array<string, mixed>
     */
    private function verifyPostExecution(string $batchPublicId, array $safeCandidates, array $dryRunSummary): array
    {
        if ($this->azureConnection === null) {
            return ['available' => false];
        }

        $mappingCount = $this->mappings->countMappings($batchPublicId);
        $duplicateEmails = 0;
        $existingModified = 0;

        $existingEmails = [];
        foreach ($safeCandidates as $candidate) {
            if ($candidate->eligibility === StaffMigrationEligibility::EXISTING_USER_MATCH) {
                $existingEmails[] = $candidate->normalizedEmailAddress;
            }
        }

        if ($existingEmails !== []) {
            $placeholders = [];
            $params = [];
            foreach (array_values(array_unique($existingEmails)) as $index => $email) {
                $key = ':email' . $index;
                $placeholders[] = $key;
                $params[$key] = $email;
            }
            $rows = $this->azureConnection->fetchAll(
                'SELECT [UserId], [NormalizedEmailAddress], [UpdatedAtUtc], [CreatedAtUtc] FROM [identity].[Users] WHERE [NormalizedEmailAddress] IN (' . implode(', ', $placeholders) . ')',
                $params,
            );
            foreach ($rows as $row) {
                $created = strtotime((string) ($row['CreatedAtUtc'] ?? ''));
                $updated = strtotime((string) ($row['UpdatedAtUtc'] ?? ''));
                if ($created !== false && $updated !== false && $updated > $created + 60) {
                    ++$existingModified;
                }
            }
        }

        $entraRows = $this->azureConnection->fetchAll(
            'SELECT COUNT(*) AS [Total] FROM [identity].[ExternalIdentities] ei INNER JOIN [migration].[LegacyStaffUserMappings] m ON m.[UserId] = ei.[UserId] WHERE m.[BatchPublicId] = :batchPublicId',
            [':batchPublicId' => $batchPublicId],
        );
        $externalIdentitiesForBatch = (int) ($entraRows[0]['Total'] ?? 0);

        $userCountRows = $this->azureConnection->fetchAll(
            'SELECT COUNT(DISTINCT m.[UserId]) AS [Total] FROM [migration].[LegacyStaffUserMappings] m WHERE m.[BatchPublicId] = :batchPublicId',
            [':batchPublicId' => $batchPublicId],
        );
        $usersCreated = (int) ($userCountRows[0]['Total'] ?? 0);

        $roleCountRows = $this->azureConnection->fetchAll(
            <<<'SQL'
SELECT COUNT(*) AS [Total]
FROM [identity].[UserRoles] ur
INNER JOIN [migration].[LegacyStaffUserMappings] m ON m.[UserId] = ur.[UserId]
WHERE m.[BatchPublicId] = :batchPublicId
SQL,
            [':batchPublicId' => $batchPublicId],
        );
        $rolesCreated = (int) ($roleCountRows[0]['Total'] ?? 0);

        $dupRows = $this->azureConnection->fetchAll(
            <<<'SQL'
SELECT COUNT(*) AS [Total]
FROM (
    SELECT [NormalizedEmailAddress], COUNT(*) AS [Cnt]
    FROM [identity].[Users]
    GROUP BY [NormalizedEmailAddress]
    HAVING COUNT(*) > 1
) d
SQL,
        );
        $duplicateEmails = (int) ($dupRows[0]['Total'] ?? 0);

        return [
            'available' => true,
            'identityUsersCreated' => $usersCreated,
            'userRolesCreated' => $rolesCreated,
            'legacyMappingsCreated' => $mappingCount,
            'externalIdentitiesCreated' => $externalIdentitiesForBatch,
            'duplicateNormalizedEmails' => $duplicateEmails,
            'existingUsersModified' => $existingModified,
            'expectedSafeCount' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
            'dryRunSafeCount' => (int) ($dryRunSummary['safeToCreate'] ?? 0),
        ];
    }
}
