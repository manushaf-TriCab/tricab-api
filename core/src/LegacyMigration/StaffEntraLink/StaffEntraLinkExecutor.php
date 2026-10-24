<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;

final class StaffEntraLinkExecutor
{
    public function __construct(
        private readonly StaffEntraLinkWriteUnit $writeUnit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $safeItems
     * @return array<string, int|string>
     */
    public function execute(
        array $safeItems,
        string $batchPublicId,
        string $sourceFingerprint,
        UserId $actorUserId,
        string $providerCode,
        string $tenantIdentifier,
    ): array {
        unset($batchPublicId, $sourceFingerprint, $actorUserId);

        $succeeded = 0;
        $skipped = 0;
        $failed = 0;
        $externalIdentitiesCreated = 0;
        $usersActivated = 0;

        foreach ($safeItems as $item) {
            $action = (string) ($item['proposedAction'] ?? '');
            if (!in_array($action, [
                StaffEntraLinkProposedAction::SAFE_LINK_TO_MAPPED_USER,
                StaffEntraLinkProposedAction::SAFE_LINK_TO_EXISTING_USER,
            ], true)) {
                ++$skipped;
                continue;
            }

            $canonicalUserId = (int) ($item['canonicalUserId'] ?? 0);
            $oid = trim((string) ($item['entraObjectId'] ?? ''));
            if ($canonicalUserId <= 0 || $oid === '') {
                ++$failed;
                continue;
            }

            try {
                $result = $this->writeUnit->linkEntraIdentity([
                    'userId' => $canonicalUserId,
                    'providerCode' => ExternalProvider::MICROSOFT_ENTRA,
                    'tenantIdentifier' => $tenantIdentifier,
                    'subjectIdentifier' => $oid,
                    'emailClaim' => (string) ($item['entraUpn'] ?? $item['entraMail'] ?? $item['mappedEmail'] ?? ''),
                    'normalizedEmailClaim' => strtolower((string) ($item['entraUpn'] ?? $item['entraMail'] ?? $item['mappedEmail'] ?? '')),
                    'activateIfPending' => ($item['wouldActivate'] ?? false) === true,
                ]);
                if (($result['externalIdentityCreated'] ?? false) === true) {
                    ++$externalIdentitiesCreated;
                }
                if ($result['userActivated'] === true) {
                    ++$usersActivated;
                }
                ++$succeeded;
            } catch (\RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'ENTRA_OID_CONFLICT')) {
                    ++$skipped;
                    continue;
                }
                ++$failed;
            }
        }

        return [
            'succeeded' => $succeeded,
            'skipped' => $skipped,
            'failed' => $failed,
            'externalIdentitiesCreated' => $externalIdentitiesCreated,
            'usersActivated' => $usersActivated,
            'identityUsersCreated' => 0,
            'userRolesCreated' => 0,
            'userRolesModified' => 0,
        ];
    }
}
