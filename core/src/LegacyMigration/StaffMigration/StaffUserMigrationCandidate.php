<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

/**
 * Candidate prepared for staff migration dry-run or future execution.
 *
 * @phpstan-type ExistingUserDetail array{
 *   userId: int,
 *   publicId: string,
 *   emailAddress: string,
 *   normalizedEmailAddress: string,
 *   displayName: string|null,
 *   statusCode: string,
 *   roleCodes: list<string>,
 *   externalProviders: list<string>
 * }
 */
final class StaffUserMigrationCandidate
{
    /**
     * @param list<string> $legacyRoles
     * @param list<string> $proposedRoleCodes
     * @param list<string> $issues
     * @param ExistingUserDetail|null $existingUserDetail
     */
    public function __construct(
        public readonly int $legacyWordPressUserId,
        public readonly string $legacyLogin,
        public readonly string $displayName,
        public readonly string $emailAddress,
        public readonly string $normalizedEmailAddress,
        public readonly array $legacyRoles,
        public readonly string $staffVerification,
        public readonly string $entraStatus,
        public readonly string $eligibility,
        public readonly string $proposedAction,
        public readonly array $proposedRoleCodes,
        public readonly string $proposedRoleLabel,
        public readonly string $roleMappingConfidence,
        public readonly bool $roleMappingResolved,
        public readonly bool $isAdministratorCandidate,
        public readonly array $issues,
        public readonly ?array $existingUserDetail = null,
        public readonly ?string $existingUserRecommendedAction = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'legacyWordPressUserId' => $this->legacyWordPressUserId,
            'legacyLogin' => $this->legacyLogin,
            'displayName' => $this->displayName,
            'emailAddress' => $this->emailAddress,
            'normalizedEmailAddress' => $this->normalizedEmailAddress,
            'legacyRoles' => $this->legacyRoles,
            'staffVerification' => $this->staffVerification,
            'entraStatus' => $this->entraStatus,
            'eligibility' => $this->eligibility,
            'proposedAction' => $this->proposedAction,
            'proposedRoleCodes' => $this->proposedRoleCodes,
            'proposedRoleLabel' => $this->proposedRoleLabel,
            'roleMappingConfidence' => $this->roleMappingConfidence,
            'roleMappingResolved' => $this->roleMappingResolved,
            'isAdministratorCandidate' => $this->isAdministratorCandidate,
            'issues' => $this->issues,
            'existingUserDetail' => $this->existingUserDetail,
            'existingUserRecommendedAction' => $this->existingUserRecommendedAction,
            'reason' => $this->reason,
        ];
    }
}
