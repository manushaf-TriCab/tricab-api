<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Contract\Validator;
use TriCab\Core\LegacyMigration\Support\NormalizedRecord;
use TriCab\Core\LegacyMigration\Support\UserClassification;
use TriCab\Core\LegacyMigration\Support\ValidationFinding;

final class UserStaffValidator implements Validator
{
    public function validate(array $records): array
    {
        $findings = [];
        $wpLogins = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord || str_starts_with($record->sourceIdentifier, '__')) {
                continue;
            }
            $login = strtolower(trim((string) ($record->canonical['legacyLogin'] ?? '')));
            if ($login !== '') {
                $wpLogins[$login] = true;
            }
            $tags = is_array($record->canonical['classificationTags'] ?? null) ? $record->canonical['classificationTags'] : [];
            $primary = (string) ($record->canonical['classification'] ?? '');
            $isStaff = $primary === UserClassification::STAFF_MICROSOFT
                || $primary === UserClassification::STAFF_OTHER
                || in_array(UserClassification::STAFF_MICROSOFT, $tags, true)
                || in_array(UserClassification::STAFF_OTHER, $tags, true);
            $employeeMatch = is_array($record->canonical['employeeMatch'] ?? null);
            if ($isStaff && $employeeMatch) {
                $findings[] = new ValidationFinding(
                    severity: 'info',
                    errorCode: 'STAFF_WITH_EMPLOYEE_MATCH',
                    description: 'Staff login matches tc.employee.user_login.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Keep the employee match for later HR migration.',
                );
            }
            if ($isStaff && !$employeeMatch) {
                $findings[] = new ValidationFinding(
                    severity: 'warning',
                    errorCode: 'STAFF_WITHOUT_EMPLOYEE_MATCH',
                    description: 'Staff-like WordPress login has no tc.employee row.',
                    sourceIdentifier: $record->sourceIdentifier,
                    recommendation: 'Review whether this account is staff before linking Entra.',
                );
            }
        }

        $orphans = [];
        foreach ($records as $record) {
            if (!$record instanceof NormalizedRecord) {
                continue;
            }
            $list = $record->canonical['unmatchedEmployeeLogins'] ?? [];
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $login) {
                if (is_string($login) && $login !== '') {
                    $orphans[strtolower($login)] = $login;
                }
            }
        }
        foreach ($orphans as $key => $login) {
            if (isset($wpLogins[$key])) {
                continue;
            }
            $findings[] = new ValidationFinding(
                severity: 'warning',
                errorCode: 'EMPLOYEE_WITHOUT_WP_USER',
                description: sprintf('tc.employee.user_login "%s" has no matching WordPress user.', $login),
                sourceIdentifier: 'employee:' . $login,
                recommendation: 'Do not invent a WordPress user. Review in a later employee module.',
            );
        }

        return $findings;
    }
}
