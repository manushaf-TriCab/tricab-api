<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\UserClassification;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class UserEntraValidator implements Validator
{
    public function validate(array $records): array
    {
        $findings = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord || str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            $required = !empty($record->canonical['entraLinkRequired']);
            $tags = is_array($record->canonical['classificationTags'] ?? null) ? $record->canonical['classificationTags'] : [];
            $primary = (string) ($record->canonical['classification'] ?? '');
            $staff = $required
                || $primary === UserClassification::STAFF_MICROSOFT
                || $primary === UserClassification::STAFF_OTHER
                || in_array(UserClassification::STAFF_MICROSOFT, $tags, true);
            if (!$staff) {
                continue;
            }
            $findings[] = new ValidationFinding(
                severity: 'warning',
                errorCode: 'ENTRA_LINK_REQUIRED',
                description: 'Staff Microsoft Object ID is not stored in WordPress (EntraObjectId = UNKNOWN). Graph is not called in this phase.',
                sourceIdentifier: $record->sourceIdentifier,
                recommendation: 'Enrich Entra Object ID in a later phase. Do not change Microsoft login now.',
            );
        }

        return $findings;
    }
}
