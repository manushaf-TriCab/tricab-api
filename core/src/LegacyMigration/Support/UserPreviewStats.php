<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final class UserPreviewStats
{
    /**
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $validation
     * @param list<array<string, mixed>> $conflicts
     * @param list<array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    public static function aggregate(array $items, array $validation, array $conflicts, array $warnings): array
    {
        $users = self::userItems($items);
        $byId = [];
        foreach ($validation as $row) {
            $id = (string) ($row['sourceIdentifier'] ?? '');
            $byId[$id][] = $row;
        }
        $conflictById = [];
        foreach ($conflicts as $row) {
            $id = (string) ($row['sourceIdentifier'] ?? '');
            $conflictById[$id][] = $row;
        }

        $stats = [
            'totalExtracted' => count($users),
            'staff' => 0,
            'staffWithEmployeeMatch' => 0,
            'customers' => 0,
            'customerContacts' => 0,
            'aliFlexUsers' => 0,
            'inactive' => 0,
            'legacyOnly' => 0,
            'missingEmails' => 0,
            'invalidEmails' => 0,
            'duplicateEmails' => 0,
            'contactConflicts' => 0,
            'unknownRoles' => 0,
            'entraLinkRequired' => 0,
            'ready' => 0,
            'warnings' => count($warnings),
            'requiresReview' => 0,
            'blocked' => 0,
            'uniqueContactMatches' => 0,
            'multipleContactMatches' => 0,
            'noContactMatch' => 0,
            'rolesDiscovered' => [],
            'duplicateNormalizedEmailGroups' => 0,
            'usersAffectedByDuplicateEmails' => 0,
        ];
        $roleCounts = [];
        $duplicateGroups = [];

        foreach ($users as $item) {
            $canonical = self::canonical($item);
            $tags = is_array($canonical['classificationTags'] ?? null) ? $canonical['classificationTags'] : [];
            $primary = (string) ($canonical['classification'] ?? '');
            if (in_array(UserClassification::STAFF_MICROSOFT, $tags, true)
                || in_array(UserClassification::STAFF_OTHER, $tags, true)
                || $primary === UserClassification::STAFF_MICROSOFT
                || $primary === UserClassification::STAFF_OTHER
            ) {
                ++$stats['staff'];
                if (is_array($canonical['employeeMatch'] ?? null)) {
                    ++$stats['staffWithEmployeeMatch'];
                }
            }
            if (in_array(UserClassification::CUSTOMER, $tags, true) || $primary === UserClassification::CUSTOMER) {
                ++$stats['customers'];
            }
            if (in_array(UserClassification::CUSTOMER_CONTACT, $tags, true)) {
                ++$stats['customerContacts'];
            }
            if (!empty($canonical['hasAliFlexRecords']) || in_array(UserClassification::ALI_FLEX_USER, $tags, true)) {
                ++$stats['aliFlexUsers'];
            }
            if (in_array(UserClassification::INACTIVE, $tags, true)) {
                ++$stats['inactive'];
            }
            if (in_array(UserClassification::LEGACY_ONLY, $tags, true) || $primary === UserClassification::LEGACY_ONLY) {
                ++$stats['legacyOnly'];
            }
            if (!empty($canonical['entraLinkRequired'])) {
                ++$stats['entraLinkRequired'];
            }

            $roles = is_array($canonical['legacyRoles'] ?? null) ? $canonical['legacyRoles'] : [];
            foreach ($roles as $role) {
                if (!is_string($role) || $role === '') {
                    continue;
                }
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
            }
            if (is_array($canonical['unknownRoles'] ?? null) && $canonical['unknownRoles'] !== []) {
                ++$stats['unknownRoles'];
            }

            $matches = is_array($canonical['contactMatches'] ?? null) ? $canonical['contactMatches'] : [];
            $real = array_values(array_filter(
                $matches,
                static fn (mixed $m): bool => is_array($m) && ($m['matchType'] ?? '') !== 'NO_CONTACT',
            ));
            if ($real === []) {
                ++$stats['noContactMatch'];
            } elseif (count($real) === 1) {
                ++$stats['uniqueContactMatches'];
            } else {
                ++$stats['multipleContactMatches'];
            }

            $email = strtolower(trim((string) ($canonical['normalizedEmail'] ?? $canonical['email'] ?? '')));
            if ($email !== '') {
                $duplicateGroups[$email][] = (string) ($item['sourceIdentifier'] ?? '');
            }

            $id = (string) ($item['sourceIdentifier'] ?? '');
            $findings = $byId[$id] ?? [];
            $codes = array_map(static fn (array $f): string => (string) ($f['errorCode'] ?? ''), $findings);
            if (in_array('MISSING_EMAIL', $codes, true)) {
                ++$stats['missingEmails'];
            }
            if (in_array('INVALID_EMAIL', $codes, true)) {
                ++$stats['invalidEmails'];
            }
            if (in_array('DUPLICATE_NORMALIZED_EMAIL', $codes, true) || in_array('DUPLICATE_EMAIL', $codes, true)) {
                ++$stats['duplicateEmails'];
            }
            $openConflicts = array_values(array_filter(
                $conflictById[$id] ?? [],
                static fn (array $c): bool => ($c['decisionCode'] ?? null) === null,
            ));
            if ($openConflicts !== []) {
                ++$stats['contactConflicts'];
                ++$stats['blocked'];
            } elseif (in_array(UserClassification::REQUIRES_REVIEW, $tags, true) || self::hasSeverity($findings, 'error')) {
                ++$stats['requiresReview'];
            } elseif (self::hasSeverity($findings, 'warning')) {
                // counted globally via warnings list
            } else {
                ++$stats['ready'];
            }
        }

        $dupGroups = 0;
        $dupUsers = 0;
        foreach ($duplicateGroups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            ++$dupGroups;
            $dupUsers += count($ids);
        }
        $stats['duplicateNormalizedEmailGroups'] = $dupGroups;
        $stats['usersAffectedByDuplicateEmails'] = $dupUsers;
        ksort($roleCounts);
        $stats['rolesDiscovered'] = $roleCounts;

        return $stats;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function userItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $id = (string) ($item['sourceIdentifier'] ?? '');
            if ($id === '' || str_starts_with($id, '__')) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function canonical(array $item): array
    {
        if (is_array($item['canonical'] ?? null)) {
            return $item['canonical'];
        }
        if (is_array($item['rawPayload'] ?? null)) {
            return $item['rawPayload'];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private static function hasSeverity(array $findings, string $severity): bool
    {
        foreach ($findings as $finding) {
            if (($finding['severityCode'] ?? '') === $severity) {
                return true;
            }
        }

        return false;
    }
}
