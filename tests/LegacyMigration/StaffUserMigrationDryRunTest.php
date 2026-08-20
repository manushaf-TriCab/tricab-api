<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\LegacyMigration;

use PHPUnit\Framework\TestCase;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationEligibility;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationProposedAction;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationCandidate;
use TriCab\Core\LegacyMigration\StaffMigration\LegacyStaffRoleMappingConfig;
use TriCab\Core\LegacyMigration\StaffMigration\StaffMigrationCandidateBuilder;
use TriCab\Core\LegacyMigration\StaffMigration\StaffRoleResolver;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationDryRun;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationExecutor;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;

final class StaffUserMigrationDryRunTest extends TestCase
{
    /** @var list<string> */
    private array $availableRoles = ['ADMINISTRATOR', 'EMPLOYEE', 'GUEST', 'CUSTOMER_USER', 'CUSTOMER_ADMIN'];

    public function test_role_mapping_administrator_and_staff(): void
    {
        $resolver = new StaffRoleResolver();
        $admin = $resolver->resolve(['administrator'], $this->availableRoles);
        self::assertSame(['ADMINISTRATOR'], $admin['proposedRoleCodes']);
        self::assertTrue($admin['resolved']);
        self::assertTrue($admin['isAdministratorCandidate']);

        $staff = $resolver->resolve(['staff'], $this->availableRoles);
        self::assertSame(['EMPLOYEE'], $staff['proposedRoleCodes']);
        self::assertTrue($staff['resolved']);

        $sales = $resolver->resolve(['sales'], $this->availableRoles);
        self::assertSame(['EMPLOYEE'], $sales['proposedRoleCodes']);
    }

    public function test_unknown_role_never_maps_to_administrator(): void
    {
        $resolver = new StaffRoleResolver();
        $unknown = $resolver->resolve(['weirdrole'], $this->availableRoles);
        self::assertFalse($unknown['resolved']);
        self::assertSame([], $unknown['proposedRoleCodes']);
        self::assertNotContains('ADMINISTRATOR', $unknown['proposedRoleCodes']);
    }

    public function test_dry_run_eligibility_matrix(): void
    {
        $analysis = [
            'summary' => ['duplicateEmailStaff' => 1],
            'items' => [
                $this->analysisItem(1, 'staff1', 'staff1@tricab.com', ['staff'], 'VALID', 'UNIQUE', 'NEW_USER_CANDIDATE'),
                $this->analysisItem(2, 'nomail', '', ['staff'], 'MISSING_EMAIL', 'UNIQUE', 'NEW_USER_CANDIDATE'),
                $this->analysisItem(3, 'bad', 'not-email', ['staff'], 'INVALID_EMAIL', 'UNIQUE', 'NEW_USER_CANDIDATE'),
                $this->analysisItem(4, 'dup-a', 'dup@tricab.com', ['staff'], 'VALID', 'DUPLICATE_EMAIL', 'NEW_USER_CANDIDATE'),
                $this->analysisItem(5, 'exists', 'exists@tricab.com', ['staff'], 'VALID', 'UNIQUE', 'EXISTING_USER_MATCH'),
                $this->analysisItem(6, 'weird', 'weird@tricab.com', ['weirdrole'], 'VALID', 'UNIQUE', 'NEW_USER_CANDIDATE'),
            ],
            'tcEmployeeEnrichment' => 'UNAVAILABLE',
        ];
        $builder = new StaffMigrationCandidateBuilder();
        $candidates = $builder->build($analysis, $this->availableRoles, [
            'exists@tricab.com' => [
                'userId' => 99,
                'publicId' => '00000000-0000-4000-8000-000000000099',
                'emailAddress' => 'exists@tricab.com',
                'normalizedEmailAddress' => 'exists@tricab.com',
                'displayName' => 'Exists',
                'statusCode' => 'active',
                'roleCodes' => ['EMPLOYEE'],
                'externalProviders' => [],
            ],
        ], 'UNAVAILABLE');

        $byLogin = [];
        foreach ($candidates as $candidate) {
            $byLogin[$candidate->legacyLogin] = $candidate;
        }

        self::assertSame(StaffMigrationEligibility::SAFE_NEW_STAFF_USER, $byLogin['staff1']->eligibility);
        self::assertSame(StaffMigrationProposedAction::CREATE_NEW_USER, $byLogin['staff1']->proposedAction);
        self::assertSame(StaffMigrationEligibility::MISSING_EMAIL, $byLogin['nomail']->eligibility);
        self::assertSame(StaffMigrationEligibility::INVALID_EMAIL, $byLogin['bad']->eligibility);
        self::assertSame(StaffMigrationEligibility::DUPLICATE_EMAIL, $byLogin['dup-a']->eligibility);
        self::assertSame(StaffMigrationEligibility::EXISTING_USER_MATCH, $byLogin['exists']->eligibility);
        self::assertSame(StaffMigrationEligibility::ROLE_REVIEW_REQUIRED, $byLogin['weird']->eligibility);

        $dryRun = new StaffUserMigrationDryRun();
        $audit = new ReadOnlySqlAudit();
        $result = $dryRun->run($candidates, $analysis, $audit->summary(), 'test-fingerprint');
        self::assertSame('DRY_RUN_ONLY', $result['mode']);
        self::assertSame(0, $result['wouldCreate']['externalIdentities']);
        self::assertSame(1, $result['summary']['safeToCreate']);
        self::assertSame(1, $result['summary']['wouldCreateIdentityUsers']);
        self::assertFalse($result['executeEnabled']);
    }

    public function test_legacy_and_azure_write_guards(): void
    {
        $audit = new ReadOnlySqlAudit();
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $audit->recordLegacy('INSERT INTO wp_users VALUES (1)');
    }

    public function test_executor_rejects_out_of_scope_candidate(): void
    {
        $candidate = new StaffUserMigrationCandidate(
            legacyWordPressUserId: 1,
            legacyLogin: 'blocked',
            displayName: 'Blocked',
            emailAddress: 'blocked@tricab.com',
            normalizedEmailAddress: 'blocked@tricab.com',
            legacyRoles: ['weirdrole'],
            staffVerification: \TriCab\Core\LegacyMigration\StaffMigration\StaffVerification::WORDPRESS_ONLY,
            entraStatus: \TriCab\Core\LegacyMigration\StaffMigration\StaffEntraStatus::LINK_REQUIRED,
            eligibility: StaffMigrationEligibility::ROLE_REVIEW_REQUIRED,
            proposedAction: StaffMigrationProposedAction::BLOCK_ROLE_REVIEW,
            proposedRoleCodes: [],
            proposedRoleLabel: '',
            roleMappingConfidence: 'NONE',
            roleMappingResolved: false,
            isAdministratorCandidate: false,
            issues: [],
        );
        $executor = new StaffUserMigrationExecutor(
            new \TriCab\Api\Tests\LegacyMigration\StubStaffIdentityUserCreator(),
            new \TriCab\Api\Tests\LegacyMigration\InMemoryStaffMappingRepository(),
            new \TriCab\Api\Tests\LegacyMigration\StubStaffMigrationWriteUnit(),
        );

        $result = $executor->execute([$candidate], 'batch-1', 'fp-1', \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        self::assertSame(0, $result['succeeded']);
        self::assertSame(0, $result['failed']);
    }

    public function test_mapping_config_uses_actual_tricab_role_codes(): void
    {
        $config = new LegacyStaffRoleMappingConfig();
        $mappings = $config->directMappings();
        self::assertSame('ADMINISTRATOR', $mappings['administrator']['roleCode']);
        self::assertSame('EMPLOYEE', $mappings['staff']['roleCode']);
        self::assertSame('EMPLOYEE', $mappings['sales']['roleCode']);
    }

    /**
     * @param list<string> $roles
     * @return array<string, mixed>
     */
    private function analysisItem(
        int $id,
        string $login,
        string $email,
        array $roles,
        string $emailStatus,
        string $duplicateStatus,
        string $existingStatus,
    ): array {
        return [
            'legacyWordPressUserId' => $id,
            'legacyLogin' => $login,
            'displayName' => $login,
            'email' => $email,
            'normalizedEmail' => strtolower($email),
            'legacyRoles' => $roles,
            'legacyRole' => $roles[0] ?? '',
            'emailStatus' => $emailStatus,
            'duplicateStatus' => $duplicateStatus,
            'existingTriCabUserStatus' => $existingStatus,
            'entraStatus' => 'LINK_REQUIRED',
            'issues' => [],
        ];
    }
}
