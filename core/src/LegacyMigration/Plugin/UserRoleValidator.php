<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class UserRoleValidator implements Validator
{
    public function validate(array $records): array
    {
        $findings = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord || str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            $unknown = is_array($record->canonical['unknownRoles'] ?? null) ? $record->canonical['unknownRoles'] : [];
            if ($unknown === []) {
                continue;
            }
            $findings[] = new ValidationFinding(
                severity: 'warning',
                errorCode: 'UNKNOWN_ROLE',
                description: 'Unexpected WordPress role slug(s): ' . implode(', ', array_map('strval', $unknown)),
                sourceIdentifier: $record->sourceIdentifier,
                recommendation: 'Inventory the live role before mapping to identity.UserRoles. Do not assign production roles yet.',
            );
        }

        return $findings;
    }
}
