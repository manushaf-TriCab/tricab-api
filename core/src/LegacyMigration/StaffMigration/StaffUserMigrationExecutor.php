<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffMigration;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationWriteUnit;

/**
 * Writes approved staff users to NEW Azure SQL only. Never touches legacy databases.
 */
final class StaffUserMigrationExecutor
{
    public function __construct(
        private readonly StaffIdentityUserCreator $userCreator,
        private readonly LegacyStaffUserMappingRepository $mappings,
        private readonly StaffMigrationWriteUnit $writeUnit,
    ) {
    }

    /**
     * @param list<StaffUserMigrationCandidate> $candidates
     * @return array<string, mixed>
     */
    public function execute(
        array $candidates,
        string $batchPublicId,
        string $sourceFingerprint,
        UserId $actorUserId,
    ): array {
        $results = [
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
            'items' => [],
        ];

        foreach ($candidates as $candidate) {
            $itemResult = $this->executeOne($candidate, $batchPublicId, $sourceFingerprint, $actorUserId);
            $results['items'][] = $itemResult;
            $status = (string) ($itemResult['status'] ?? 'failed');
            if ($status === 'succeeded') {
                ++$results['succeeded'];
                ++$results['identityUsersCreated'];
                $results['userRolesCreated'] += count($candidate->proposedRoleCodes);
                ++$results['legacyMappingsCreated'];
            } elseif ($status === 'skipped') {
                ++$results['skipped'];
            } elseif ($status === 'failed') {
                ++$results['failed'];
                throw StaffMigrationExecutionException::concurrentEmailConflict(
                    $candidate->normalizedEmailAddress !== ''
                        ? $candidate->normalizedEmailAddress
                        : (string) $candidate->legacyWordPressUserId,
                );
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function executeOne(
        StaffUserMigrationCandidate $candidate,
        string $batchPublicId,
        string $sourceFingerprint,
        UserId $actorUserId,
    ): array {
        if ($candidate->eligibility !== StaffMigrationEligibility::SAFE_NEW_STAFF_USER
            || $candidate->proposedAction !== StaffMigrationProposedAction::CREATE_NEW_USER) {
            return [
                'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
                'status' => 'rejected',
                'reason' => 'Candidate is outside approved execution scope.',
            ];
        }

        $existingMapping = $this->mappings->findByLegacyWordPressUserId($candidate->legacyWordPressUserId);
        if ($existingMapping !== null) {
            return [
                'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
                'status' => 'skipped',
                'reason' => 'ALREADY_MIGRATED',
                'userId' => $existingMapping['userId'] ?? null,
            ];
        }

        $emailMapping = $this->mappings->findByNormalizedEmail($candidate->normalizedEmailAddress);
        if ($emailMapping !== null) {
            return [
                'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
                'status' => 'skipped',
                'reason' => 'ALREADY_MIGRATED',
                'userId' => $emailMapping['userId'] ?? null,
            ];
        }

        $email = EmailAddress::fromString($candidate->emailAddress);
        if ($this->userCreator->emailExists($email)) {
            return [
                'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
                'status' => 'failed',
                'reason' => 'CONCURRENT_EXISTING_USER_CONFLICT',
            ];
        }

        $created = $this->writeUnit->createStaffUserWithLegacyMapping(
            $email,
            $candidate->displayName !== '' ? $candidate->displayName : $candidate->legacyLogin,
            $candidate->proposedRoleCodes,
            $actorUserId,
            [
                'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
                'legacyLogin' => $candidate->legacyLogin,
                'legacyEmailAddress' => $candidate->emailAddress,
                'normalizedEmailAddress' => $candidate->normalizedEmailAddress,
                'batchPublicId' => $batchPublicId,
                'sourceFingerprint' => $sourceFingerprint,
                'migrationStatusCode' => 'created-user',
            ],
        );

        return [
            'legacyWordPressUserId' => $candidate->legacyWordPressUserId,
            'status' => 'succeeded',
            'userId' => $created['userId'],
            'publicId' => $created['publicId'],
            'emailAddress' => $created['emailAddress'],
            'roleCodes' => $candidate->proposedRoleCodes,
            'entraStatus' => StaffEntraStatus::LINK_REQUIRED,
        ];
    }
}
