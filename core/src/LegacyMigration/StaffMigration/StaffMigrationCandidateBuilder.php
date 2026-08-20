<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

/**
 * Builds staff migration candidates from LegacyStaffReadOnlyAnalyzer output.
 */
final class StaffMigrationCandidateBuilder
{
    public function __construct(
        private readonly StaffRoleResolver $roleResolver = new StaffRoleResolver(),
        private readonly LegacyStaffRoleMappingConfig $mappingConfig = new LegacyStaffRoleMappingConfig(),
    ) {
    }

    /**
     * @param array<string, mixed> $analysisResult
     * @param list<string> $availableTriCabRoleCodes
     * @param array<string, array<string, mixed>> $existingUserDetails
     * @return list<StaffUserMigrationCandidate>
     */
    public function build(
        array $analysisResult,
        array $availableTriCabRoleCodes,
        array $existingUserDetails,
        string $tcEmployeeEnrichment,
    ): array {
        $items = is_array($analysisResult['items'] ?? null) ? $analysisResult['items'] : [];
        $candidates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidates[] = $this->buildOne($item, $availableTriCabRoleCodes, $existingUserDetails, $tcEmployeeEnrichment);
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string> $availableTriCabRoleCodes
     * @param array<string, array<string, mixed>> $existingUserDetails
     */
    private function buildOne(
        array $item,
        array $availableTriCabRoleCodes,
        array $existingUserDetails,
        string $tcEmployeeEnrichment,
    ): StaffUserMigrationCandidate {
        $legacyRoles = is_array($item['legacyRoles'] ?? null) ? $item['legacyRoles'] : [];
        $legacyRoles = array_values(array_filter(array_map(
            static fn ($r) => is_string($r) ? strtolower(trim($r)) : '',
            $legacyRoles,
        )));
        $email = trim((string) ($item['email'] ?? ''));
        $normalized = strtolower($email);
        $emailStatus = (string) ($item['emailStatus'] ?? '');
        $duplicateStatus = (string) ($item['duplicateStatus'] ?? '');
        $existingStatus = (string) ($item['existingTriCabUserStatus'] ?? '');
        $entraRaw = (string) ($item['entraStatus'] ?? 'LINK_REQUIRED');

        $staffVerification = $tcEmployeeEnrichment === 'AVAILABLE' && !empty($item['employeeMatch'])
            ? StaffVerification::WORDPRESS_AND_EMPLOYEE
            : StaffVerification::WORDPRESS_ONLY;

        $entraStatus = match ($entraRaw) {
            'LINKED' => StaffEntraStatus::LINKED,
            'NOT_APPLICABLE' => StaffEntraStatus::NOT_LINKED,
            default => StaffEntraStatus::LINK_REQUIRED,
        };

        $roleResolution = $this->roleResolver->resolve($legacyRoles, $availableTriCabRoleCodes);
        $existingDetail = $existingUserDetails[$normalized] ?? null;
        $issues = is_array($item['issues'] ?? null) ? $item['issues'] : [];
        $issues = array_merge($issues, $roleResolution['issues']);

        [$eligibility, $action, $reason, $existingRecommended] = $this->determineOutcome(
            $emailStatus,
            $duplicateStatus,
            $existingStatus,
            $roleResolution,
            $issues,
            $existingDetail !== null,
        );

        return new StaffUserMigrationCandidate(
            legacyWordPressUserId: (int) ($item['legacyWordPressUserId'] ?? 0),
            legacyLogin: (string) ($item['legacyLogin'] ?? ''),
            displayName: (string) ($item['displayName'] ?? ''),
            emailAddress: $email,
            normalizedEmailAddress: $normalized,
            legacyRoles: $legacyRoles,
            staffVerification: $staffVerification,
            entraStatus: $entraStatus,
            eligibility: $eligibility,
            proposedAction: $action,
            proposedRoleCodes: $roleResolution['proposedRoleCodes'],
            proposedRoleLabel: $roleResolution['proposedRoleLabel'],
            roleMappingConfidence: $roleResolution['confidence'],
            roleMappingResolved: $roleResolution['resolved'],
            isAdministratorCandidate: $roleResolution['isAdministratorCandidate'],
            issues: array_values(array_unique($issues)),
            existingUserDetail: $existingDetail,
            existingUserRecommendedAction: $existingRecommended,
            reason: $reason,
        );
    }

    /**
     * @param array{
     *   resolved: bool,
     *   issues: list<string>,
     *   isAdministratorCandidate: bool
     * } $roleResolution
     * @param list<string> $issues
     * @return array{0: string, 1: string, 2: string|null, 3: string|null}
     */
    private function determineOutcome(
        string $emailStatus,
        string $duplicateStatus,
        string $existingStatus,
        array $roleResolution,
        array $issues,
        bool $hasExistingDetail,
    ): array {
        if ($emailStatus === 'MISSING_EMAIL') {
            return [
                StaffMigrationEligibility::MISSING_EMAIL,
                StaffMigrationProposedAction::SKIP_MISSING_EMAIL,
                'Email is required for staff migration.',
                null,
            ];
        }
        if ($emailStatus === 'INVALID_EMAIL') {
            return [
                StaffMigrationEligibility::INVALID_EMAIL,
                StaffMigrationProposedAction::SKIP_INVALID_EMAIL,
                'Email syntax is invalid.',
                null,
            ];
        }
        if ($duplicateStatus === 'DUPLICATE_EMAIL') {
            return [
                StaffMigrationEligibility::DUPLICATE_EMAIL,
                StaffMigrationProposedAction::BLOCK_DUPLICATE_EMAIL,
                'Normalized email is duplicated among staff candidates.',
                null,
            ];
        }
        if ($existingStatus === 'EXISTING_USER_MATCH' || $hasExistingDetail) {
            return [
                StaffMigrationEligibility::EXISTING_USER_MATCH,
                StaffMigrationProposedAction::SKIP_EXISTING_USER,
                'A TriCab identity user already exists for this email.',
                'LINK_EXISTING',
            ];
        }
        if (!$roleResolution['resolved']) {
            return [
                StaffMigrationEligibility::ROLE_REVIEW_REQUIRED,
                StaffMigrationProposedAction::BLOCK_ROLE_REVIEW,
                'Legacy role mapping could not be resolved automatically.',
                null,
            ];
        }
        if (in_array('CUSTOMER_ROLE_NOT_STAFF', $issues, true)) {
            return [
                StaffMigrationEligibility::AMBIGUOUS_STAFF,
                StaffMigrationProposedAction::BLOCK_STAFF_VERIFICATION,
                'Legacy roles indicate a customer account, not staff.',
                null,
            ];
        }
        if ($issues !== []) {
            return [
                StaffMigrationEligibility::OTHER_BLOCKING_CONFLICT,
                StaffMigrationProposedAction::BLOCK_OTHER_CONFLICT,
                implode('; ', $issues),
                null,
            ];
        }

        return [
            StaffMigrationEligibility::SAFE_NEW_STAFF_USER,
            StaffMigrationProposedAction::CREATE_NEW_USER,
            'Eligible for future identity.Users creation after explicit approval.',
            null,
        ];
    }
}
