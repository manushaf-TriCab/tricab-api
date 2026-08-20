<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

/**
 * Computes dry-run summary and per-user outcomes. Never writes to any database.
 */
final class StaffUserMigrationDryRun
{
    /**
     * @param list<StaffUserMigrationCandidate> $candidates
     * @param array<string, mixed> $analysisResult
     * @param array<string, mixed> $databaseSafety
     * @return array<string, mixed>
     */
    public function run(
        array $candidates,
        array $analysisResult,
        array $databaseSafety,
        string $sourceFingerprint,
    ): array {
        $summary = $this->buildSummary($candidates, $analysisResult);
        $unexpectedRoles = $this->unexpectedRoleReport($candidates);
        $existingUsers = array_values(array_filter(
            array_map(static fn (StaffUserMigrationCandidate $c) => $c->existingUserDetail !== null ? $c->toArray() : null, $candidates),
        ));
        $administratorCandidates = array_values(array_filter(
            array_map(
                static fn (StaffUserMigrationCandidate $c) => $c->isAdministratorCandidate ? $c->toArray() : null,
                $candidates,
            ),
        ));

        return [
            'mode' => 'DRY_RUN_ONLY',
            'scope' => StaffMigrationScope::STAFF_ONLY,
            'warning' => 'Legacy databases: READ ONLY. New database: READ ONLY during preview. No users will be created.',
            'persistence' => 'MEMORY',
            'sourceFingerprint' => $sourceFingerprint,
            'approvalRequired' => true,
            'executeEnabled' => false,
            'summary' => $summary,
            'wouldCreate' => [
                'identityUsers' => $summary['wouldCreateIdentityUsers'],
                'userRoles' => $summary['wouldCreateUserRoles'],
                'legacyMappings' => $summary['wouldCreateLegacyMappings'],
                'externalIdentities' => 0,
            ],
            'proposedRoleMapping' => (new LegacyStaffRoleMappingConfig())->directMappings(),
            'unexpectedRoleReport' => $unexpectedRoles,
            'administratorCandidates' => $administratorCandidates,
            'existingTriCabUsers' => $existingUsers,
            'items' => array_map(static fn (StaffUserMigrationCandidate $c) => $c->toArray(), $candidates),
            'databaseSafety' => $databaseSafety,
            'tcEmployeeEnrichment' => $analysisResult['tcEmployeeEnrichment'] ?? 'UNAVAILABLE',
            'identityLookup' => $analysisResult['identityLookup'] ?? 'UNKNOWN',
            'developmentOverride' => $analysisResult['developmentOverride'] ?? null,
            'transactionDesign' => [
                'scope' => 'NEW_AZURE_SQL_ONLY',
                'unit' => 'identity.User + UserRoles + legacy mapping in single transaction',
                'legacyRollback' => 'Never touches WordPress or TC',
            ],
            'staleDataProtection' => [
                'fingerprint' => $sourceFingerprint,
                'rule' => 'Execution blocked when approved candidate set != current source fingerprint',
                'staleCode' => 'STALE_MIGRATION_PREVIEW',
            ],
        ];
    }

    /**
     * @param list<StaffUserMigrationCandidate> $candidates
     * @param array<string, mixed> $analysisResult
     * @return array<string, int|string>
     */
    private function buildSummary(array $candidates, array $analysisResult): array
    {
        $analysisSummary = is_array($analysisResult['summary'] ?? null) ? $analysisResult['summary'] : [];
        $counts = [
            'totalStaffCandidates' => count($candidates),
            'validEmailStaff' => 0,
            'missingEmailStaff' => 0,
            'invalidEmailStaff' => 0,
            'legacyDuplicateEmailConflicts' => 0,
            'existingTriCabUsers' => 0,
            'newUserCandidates' => 0,
            'tcEmployeeVerified' => 0,
            'wordpressOnlyStaff' => 0,
            'roleMappingResolved' => 0,
            'roleReviewRequired' => 0,
            'administratorAssignmentsProposed' => 0,
            'employeeAssignmentsProposed' => 0,
            'otherRoleAssignmentsProposed' => 0,
            'entraAlreadyLinked' => 0,
            'entraLinkRequired' => 0,
            'safeToCreate' => 0,
            'requiresReview' => 0,
            'excluded' => 0,
            'blocked' => 0,
            'wouldCreateIdentityUsers' => 0,
            'wouldCreateUserRoles' => 0,
            'wouldCreateLegacyMappings' => 0,
            'wouldCreateExternalIdentities' => 0,
            'wouldSkipExistingUsers' => 0,
            'wouldExcludeMissingEmail' => 0,
            'wouldExcludeInvalidEmail' => 0,
            'wouldBlockDuplicateEmail' => 0,
            'wouldBlockRoleConflict' => 0,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate->emailAddress !== '' && filter_var($candidate->emailAddress, FILTER_VALIDATE_EMAIL)) {
                ++$counts['validEmailStaff'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::MISSING_EMAIL) {
                ++$counts['missingEmailStaff'];
                ++$counts['excluded'];
                ++$counts['wouldExcludeMissingEmail'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::INVALID_EMAIL) {
                ++$counts['invalidEmailStaff'];
                ++$counts['excluded'];
                ++$counts['wouldExcludeInvalidEmail'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::DUPLICATE_EMAIL) {
                ++$counts['legacyDuplicateEmailConflicts'];
                ++$counts['blocked'];
                ++$counts['wouldBlockDuplicateEmail'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::EXISTING_USER_MATCH) {
                ++$counts['existingTriCabUsers'];
                ++$counts['requiresReview'];
                ++$counts['wouldSkipExistingUsers'];
            }
            if ($candidate->staffVerification === StaffVerification::WORDPRESS_AND_EMPLOYEE) {
                ++$counts['tcEmployeeVerified'];
            } else {
                ++$counts['wordpressOnlyStaff'];
            }
            if ($candidate->roleMappingResolved) {
                ++$counts['roleMappingResolved'];
            } else {
                ++$counts['roleReviewRequired'];
            }
            if ($candidate->isAdministratorCandidate && $candidate->proposedAction === StaffMigrationProposedAction::CREATE_NEW_USER) {
                ++$counts['administratorAssignmentsProposed'];
            }
            if ($candidate->proposedAction === StaffMigrationProposedAction::CREATE_NEW_USER
                && in_array('EMPLOYEE', $candidate->proposedRoleCodes, true)) {
                ++$counts['employeeAssignmentsProposed'];
            }
            if ($candidate->entraStatus === StaffEntraStatus::LINKED) {
                ++$counts['entraAlreadyLinked'];
            } elseif ($candidate->entraStatus === StaffEntraStatus::LINK_REQUIRED) {
                ++$counts['entraLinkRequired'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::SAFE_NEW_STAFF_USER) {
                ++$counts['safeToCreate'];
                ++$counts['newUserCandidates'];
                ++$counts['wouldCreateIdentityUsers'];
                $counts['wouldCreateUserRoles'] += count($candidate->proposedRoleCodes);
                ++$counts['wouldCreateLegacyMappings'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::ROLE_REVIEW_REQUIRED) {
                ++$counts['blocked'];
                ++$counts['wouldBlockRoleConflict'];
            }
            if ($candidate->eligibility === StaffMigrationEligibility::AMBIGUOUS_STAFF
                || $candidate->eligibility === StaffMigrationEligibility::OTHER_BLOCKING_CONFLICT) {
                ++$counts['blocked'];
            }
            if (in_array($candidate->eligibility, [
                StaffMigrationEligibility::EXISTING_USER_MATCH,
                StaffMigrationEligibility::ROLE_REVIEW_REQUIRED,
                StaffMigrationEligibility::AMBIGUOUS_STAFF,
            ], true)) {
                // requiresReview already counted for existing
            }
        }

        $counts['legacyDuplicateEmailConflicts'] = (int) ($analysisSummary['duplicateEmailStaff'] ?? $counts['legacyDuplicateEmailConflicts']);

        return $counts;
    }

    /**
     * @param list<StaffUserMigrationCandidate> $candidates
     * @return list<array<string, mixed>>
     */
    private function unexpectedRoleReport(array $candidates): array
    {
        $mappingConfig = new LegacyStaffRoleMappingConfig();
        $knownMapped = array_keys($mappingConfig->directMappings());
        $report = [];

        /** @var array<string, list<string>> $combinationsByUnknown */
        $combinationsByUnknown = [];
        /** @var array<string, int> $userCounts */
        $userCounts = [];

        foreach ($candidates as $candidate) {
            $unknown = [];
            foreach ($candidate->legacyRoles as $role) {
                if (!in_array($role, $knownMapped, true) && !$mappingConfig->isCustomerOnlyRole($role)) {
                    $unknown[] = $role;
                }
            }
            if ($unknown === []) {
                continue;
            }
            foreach ($unknown as $role) {
                $userCounts[$role] = ($userCounts[$role] ?? 0) + 1;
                $combo = implode(' + ', $candidate->legacyRoles);
                $combinationsByUnknown[$role][$combo] = true;
            }
        }

        foreach ($userCounts as $role => $count) {
            $report[] = [
                'legacyRole' => $role,
                'staffUserCount' => $count,
                'roleCombinations' => array_keys($combinationsByUnknown[$role] ?? []),
                'proposedTriCabRole' => 'UNKNOWN',
                'mappingConfidence' => 'NONE',
                'migrationStatus' => 'ROLE_REVIEW_REQUIRED',
            ];
        }

        usort($report, static fn (array $a, array $b): int => ($b['staffUserCount'] ?? 0) <=> ($a['staffUserCount'] ?? 0));

        return $report;
    }
}
