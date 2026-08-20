<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Config\Settings;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationPreparer;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationExecuteService;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationWriteUnit;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationBatch1Policy;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationEligibility;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationExecutionException;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationProposedAction;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationScope;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationCandidate;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationExecutor;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;

final class StaffUserMigrationExecuteTest extends TestCase
{
    public function test_executor_skips_already_migrated_without_failing_batch(): void
    {
        $candidate = $this->safeCandidate(10, 'staff10@tricab.com');
        $mappings = new InMemoryStaffMappingRepository([
            10 => ['userId' => 500, 'legacyWordPressUserId' => 10],
        ]);
        $executor = new StaffUserMigrationExecutor(
            new StubStaffIdentityUserCreator(),
            $mappings,
            new StubStaffMigrationWriteUnit(),
        );

        $result = $executor->execute([$candidate], 'batch-1', 'fp-1', UserId::fromInt(1));

        self::assertSame(0, $result['succeeded']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['failed']);
    }

    public function test_executor_fails_on_concurrent_existing_user_conflict(): void
    {
        $candidate = $this->safeCandidate(11, 'exists@tricab.com');
        $users = new StubStaffIdentityUserCreator();
        $users->existingEmails[] = 'exists@tricab.com';
        $executor = new StaffUserMigrationExecutor(
            $users,
            new InMemoryStaffMappingRepository(),
            new StubStaffMigrationWriteUnit(),
        );

        $this->expectException(StaffMigrationExecutionException::class);
        $this->expectExceptionMessage('CONCURRENT_EXISTING_USER_CONFLICT');
        $executor->execute([$candidate], 'batch-1', 'fp-1', UserId::fromInt(1));
    }

    public function test_execute_service_blocks_memory_persistence(): void
    {
        putenv('LEGACY_MIGRATION_STORE=memory');
        $_ENV['LEGACY_MIGRATION_STORE'] = 'memory';

        $service = new StaffUserMigrationExecuteService(
            new StubStaffMigrationPreparer([]),
            new StaffUserMigrationExecutor(
                new StubStaffIdentityUserCreator(),
                new InMemoryStaffMappingRepository(),
                new StubStaffMigrationWriteUnit(),
            ),
            $this->createMock(LegacyMigrationStore::class),
            new InMemoryStaffMappingRepository(),
            $this->azureSettings(),
            $this->createMock(SqlConnection::class),
        );

        $this->expectException(StaffMigrationExecutionException::class);
        $this->expectExceptionMessage('LEGACY_MIGRATION_STORE must be azure-sql');
        $service->execute($this->actor(), [
            'scope' => StaffMigrationScope::STAFF_ONLY,
            'sourceFingerprint' => 'fp',
            'expectedCandidateCount' => 617,
            'approved' => true,
        ]);
    }

    public function test_execute_service_blocks_stale_fingerprint(): void
    {
        putenv('LEGACY_MIGRATION_STORE=azure-sql');
        $_ENV['LEGACY_MIGRATION_STORE'] = 'azure-sql';

        $audit = new ReadOnlySqlAudit();
        $prep = new StubStaffMigrationPreparer([
            'analysis' => [],
            'candidates' => [],
            'dryRun' => [
                'sourceFingerprint' => 'current-fp',
                'summary' => $this->validSummary(),
            ],
            'legacyAudit' => $audit,
        ]);

        $service = new StaffUserMigrationExecuteService(
            $prep,
            new StaffUserMigrationExecutor(
                new StubStaffIdentityUserCreator(),
                new InMemoryStaffMappingRepository(),
                new StubStaffMigrationWriteUnit(),
            ),
            $this->createMock(LegacyMigrationStore::class),
            new InMemoryStaffMappingRepository(),
            $this->azureSettings(),
            $this->createMock(SqlConnection::class),
        );

        $this->expectException(StaffMigrationExecutionException::class);
        $this->expectExceptionMessage('STALE_MIGRATION_PREVIEW');
        $service->execute($this->actor(), [
            'scope' => StaffMigrationScope::STAFF_ONLY,
            'sourceFingerprint' => 'approved-fp',
            'expectedCandidateCount' => 617,
            'approved' => true,
        ]);
    }

    public function test_execute_service_blocks_count_mismatch(): void
    {
        putenv('LEGACY_MIGRATION_STORE=azure-sql');
        $_ENV['LEGACY_MIGRATION_STORE'] = 'azure-sql';

        $summary = $this->validSummary();
        $summary['safeToCreate'] = 616;

        $audit = new ReadOnlySqlAudit();
        $prep = new StubStaffMigrationPreparer([
            'analysis' => [],
            'candidates' => [],
            'dryRun' => [
                'sourceFingerprint' => 'fp-1',
                'summary' => $summary,
            ],
            'legacyAudit' => $audit,
        ]);

        $service = new StaffUserMigrationExecuteService(
            $prep,
            new StaffUserMigrationExecutor(
                new StubStaffIdentityUserCreator(),
                new InMemoryStaffMappingRepository(),
                new StubStaffMigrationWriteUnit(),
            ),
            $this->createMock(LegacyMigrationStore::class),
            new InMemoryStaffMappingRepository(),
            $this->azureSettings(),
            $this->createMock(SqlConnection::class),
        );

        $this->expectException(StaffMigrationExecutionException::class);
        $this->expectExceptionMessage('safeToCreate');
        $service->execute($this->actor(), [
            'scope' => StaffMigrationScope::STAFF_ONLY,
            'sourceFingerprint' => 'fp-1',
            'expectedCandidateCount' => 617,
            'approved' => true,
        ]);
    }

    /** @return array<string, int> */
    private function validSummary(): array
    {
        return [
            'safeToCreate' => StaffMigrationBatch1Policy::EXPECTED_SAFE_COUNT,
            'administratorAssignmentsProposed' => StaffMigrationBatch1Policy::EXPECTED_ADMIN_ASSIGNMENTS,
            'employeeAssignmentsProposed' => StaffMigrationBatch1Policy::EXPECTED_EMPLOYEE_ASSIGNMENTS,
            'existingTriCabUsers' => StaffMigrationBatch1Policy::EXPECTED_EXISTING_USERS,
            'missingEmailStaff' => StaffMigrationBatch1Policy::EXPECTED_MISSING_EMAIL,
            'wouldBlockRoleConflict' => StaffMigrationBatch1Policy::EXPECTED_BLOCKED_ROLE,
        ];
    }

    private function actor(): ActorContext
    {
        return new ActorContext(
            userId: 1,
            publicId: '00000000-0000-4000-8000-000000000001',
            emailAddress: 'admin@tricab.com',
        );
    }

    private function azureSettings(): Settings
    {
        putenv('USER_REPOSITORY=azure-sql');
        $_ENV['USER_REPOSITORY'] = 'azure-sql';

        return Environment::settings(dirname(__DIR__, 2));
    }

    private function safeCandidate(int $wpId, string $email): StaffUserMigrationCandidate
    {
        return new StaffUserMigrationCandidate(
            legacyWordPressUserId: $wpId,
            legacyLogin: 'user' . $wpId,
            displayName: 'User ' . $wpId,
            emailAddress: $email,
            normalizedEmailAddress: strtolower($email),
            legacyRoles: ['staff'],
            staffVerification: \TriCab\Core\LegacyMigration\StaffMigration\StaffVerification::WORDPRESS_ONLY,
            entraStatus: \TriCab\Core\LegacyMigration\StaffMigration\StaffEntraStatus::LINK_REQUIRED,
            eligibility: StaffMigrationEligibility::SAFE_NEW_STAFF_USER,
            proposedAction: StaffMigrationProposedAction::CREATE_NEW_USER,
            proposedRoleCodes: ['EMPLOYEE'],
            proposedRoleLabel: 'Employee',
            roleMappingConfidence: 'HIGH',
            roleMappingResolved: true,
            isAdministratorCandidate: false,
            issues: [],
        );
    }
}

final class StubStaffMigrationPreparer implements StaffMigrationPreparer
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload)
    {
    }

    public function prepare(): array
    {
        return $this->payload;
    }
}

final class InMemoryStaffMappingRepository implements LegacyStaffUserMappingRepository
{
    /** @param array<int, array<string, mixed>> $byWpId */
    public function __construct(private array $byWpId = [])
    {
    }

    public function schemaReady(): bool
    {
        return true;
    }

    public function findByLegacyWordPressUserId(int $legacyWordPressUserId): ?array
    {
        return $this->byWpId[$legacyWordPressUserId] ?? null;
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?array
    {
        foreach ($this->byWpId as $row) {
            if (($row['normalizedEmailAddress'] ?? '') === strtolower(trim($normalizedEmail))) {
                return $row;
            }
        }

        return null;
    }

    public function insertMapping(array $data): void
    {
        $this->byWpId[(int) $data['legacyWordPressUserId']] = $data;
    }

    public function countMappings(?string $batchPublicId = null): int
    {
        return count($this->byWpId);
    }

    public function listByBatchPublicId(string $batchPublicId): array
    {
        return array_values($this->byWpId);
    }
}

final class StubStaffIdentityUserCreator implements StaffIdentityUserCreator
{
    /** @var list<string> */
    public array $existingEmails = [];

    public function createStaffUser(
        \TriCab\Core\Identity\Domain\ValueObject\EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
    ): array {
        return [
            'userId' => 100,
            'publicId' => '00000000-0000-4000-8000-000000000100',
            'emailAddress' => $emailAddress->toString(),
            'displayName' => $displayName,
            'roleCodes' => $roleCodes,
        ];
    }

    public function emailExists(\TriCab\Core\Identity\Domain\ValueObject\EmailAddress $emailAddress): bool
    {
        return in_array(strtolower($emailAddress->toString()), $this->existingEmails, true);
    }
}

final class StubStaffMigrationWriteUnit implements StaffMigrationWriteUnit
{
    public function createStaffUserWithLegacyMapping(
        \TriCab\Core\Identity\Domain\ValueObject\EmailAddress $emailAddress,
        string $displayName,
        array $roleCodes,
        UserId $actorUserId,
        array $mappingData,
    ): array {
        return [
            'userId' => 101,
            'publicId' => '00000000-0000-4000-8000-000000000101',
            'emailAddress' => $emailAddress->toString(),
            'displayName' => $displayName,
            'roleCodes' => $roleCodes,
        ];
    }
}
