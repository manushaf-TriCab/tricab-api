<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\LegacyMigration\Contract\ExistingEntraLinkLookup;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Support\UserClassification;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;

/**
 * Strict read-only staff analysis. Never writes to any database or migration store.
 */
final class LegacyStaffReadOnlyAnalyzer
{
    private const REPO = 'LegacyStaffReadOnlyAnalyzer';
    private const CHUNK = 500;

    /** @var list<string> */
    private const EXPECTED_STAFF_ROLES = ['administrator', 'staff', 'sales'];

    /** @var array<string, string> */
    private const PROPOSED_ROLE_MAPPING = [
        'administrator' => 'Platform.Administrator (proposed)',
        'staff' => 'Platform.Staff (proposed)',
        'sales' => 'Platform.Sales (proposed)',
    ];

    /** @var array<string, string> */
    private array $tcAccessWarnings = [];

    public function __construct(
        private readonly LegacyReadOnlyConnection $wordpress,
        private readonly LegacyReadOnlyConnection $tc,
        private readonly LegacyIdentitySourceConfig $config,
        private readonly LegacyIdentitySourceHealth $health,
        private readonly ExistingNormalizedEmailLookup $existingEmails,
        private readonly ExistingEntraLinkLookup $existingEntra,
        private readonly ReadOnlySqlAudit $audit,
        private readonly LegacySqlStatementLogger $legacySqlLogger,
        private readonly WordPressCapabilityParser $capabilities = new WordPressCapabilityParser(),
        private readonly UserIdentityClassifier $classifier = new UserIdentityClassifier(),
    ) {
    }

    /** @return array<string, mixed> */
    public function analyze(): array
    {
        $this->assertAnalysisAllowed();

        $prefix = $this->config->legacy->wordpressTablePrefix;
        $usersTable = $this->quoteTable($prefix . 'users');
        $metaTable = $this->quoteTable($prefix . 'usermeta');
        $optionsTable = $this->quoteTable($prefix . 'options');

        $roleRegistry = $this->loadRoleRegistry($optionsTable);
        $employees = $this->indexEmployees();
        $tcStatus = $this->tcEmployeeEnrichmentStatus();

        $staffCandidates = [];
        $lastId = 0;
        while (true) {
            $sql = <<<SQL
SELECT
    ID,
    user_login,
    user_nicename,
    user_email,
    user_url,
    user_registered,
    user_status,
    display_name
FROM {$usersTable}
WHERE ID > :lastId
ORDER BY ID ASC
LIMIT {$this->chunkLimit()}
SQL;
            $this->audit->recordLegacy($sql);
            $users = $this->wordpress->query($sql, ['lastId' => $lastId], self::REPO, 'usersChunk');
            if ($users === []) {
                break;
            }

            $ids = [];
            foreach ($users as $user) {
                $id = self::wordPressUserId($user);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $metaByUser = $this->loadAllowedMeta($metaTable, $ids);

            foreach ($users as $user) {
                $id = self::wordPressUserId($user);
                if ($id < 1) {
                    continue;
                }
                $lastId = max($lastId, $id);
                $login = trim((string) ($user['user_login'] ?? $user['user_login'] ?? ''));
                $email = trim((string) ($user['user_email'] ?? ''));
                $meta = $metaByUser[$id] ?? [];
                $roles = $this->capabilities->rolesFromCapabilities(
                    isset($meta['wp_capabilities']) && is_string($meta['wp_capabilities']) ? $meta['wp_capabilities'] : null,
                );
                $employee = $employees[strtolower($login)] ?? null;
                $classification = $this->classifier->classify(
                    login: $login,
                    email: $email,
                    employeeMatch: $employee !== null,
                    contactMatches: [],
                    roles: $roles,
                    hasAliFlex: false,
                    legacyStatus: (int) ($user['user_status'] ?? 0),
                    duplicateEmail: false,
                    ambiguousContacts: false,
                );
                if (!$this->isStaffCandidate($classification)) {
                    continue;
                }

                $staffCandidates[] = [
                    'legacyWordPressUserId' => $id,
                    'legacyLogin' => $login,
                    'displayName' => (string) ($user['display_name'] ?? ''),
                    'email' => $email,
                    'normalizedEmail' => strtolower($email),
                    'legacyRoles' => $roles,
                    'legacyRole' => $roles[0] ?? '',
                    'classificationPrimary' => $classification['primary'],
                    'classificationTags' => $classification['tags'],
                    'employeeMatch' => $employee !== null,
                ];
            }

            if (count($users) < $this->chunkLimit()) {
                break;
            }
        }

        return $this->finalizeReport($staffCandidates, $roleRegistry, $tcStatus);
    }

    private function assertAnalysisAllowed(): void
    {
        if (!$this->config->readOnlyFlagsPresent()) {
            throw new \RuntimeException('LEGACY_WP_READ_ONLY and LEGACY_TC_READ_ONLY must be true for staff analysis.');
        }
        $wp = $this->health->inspect(
            $this->wordpress,
            'wordpress',
            $this->config->expectedWordpressDatabase,
            $this->config->wordpressReadOnly,
            (string) ($this->config->legacy->wordpressSslMode ?? 'required'),
            $this->config->developmentOverride,
        );
        $tc = $this->health->inspect(
            $this->tc,
            'business-db',
            $this->config->expectedTcDatabase,
            $this->config->tcReadOnly,
            (string) ($this->config->legacy->tcSslMode ?? 'required'),
            $this->config->developmentOverride,
        );
        if (empty($wp['extractionAllowed']) || empty($tc['extractionAllowed'])) {
            throw new \RuntimeException('Legacy sources are not available for read-only staff analysis.');
        }
    }

    /**
     * @param list<array<string, mixed>> $staffCandidates
     * @param list<string> $roleRegistry
     * @return array<string, mixed>
     */
    private function finalizeReport(array $staffCandidates, array $roleRegistry, string $tcStatus): array
    {
        $duplicateMap = [];
        foreach ($staffCandidates as $candidate) {
            $email = (string) ($candidate['normalizedEmail'] ?? '');
            if ($email === '') {
                continue;
            }
            $duplicateMap[$email][] = (int) $candidate['legacyWordPressUserId'];
        }
        $duplicateEmails = array_filter($duplicateMap, static fn (array $ids): bool => count($ids) > 1);

        $validEmails = [];
        foreach ($staffCandidates as $candidate) {
            $email = trim((string) ($candidate['email'] ?? ''));
            $normalized = strtolower($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $validEmails[] = $normalized;
            }
        }
        $existingPlatform = [];
        $entraLinked = [];
        $identityLookupStatus = 'AVAILABLE';
        try {
            $existingPlatform = $this->existingEmails->existingNormalizedEmails(array_values(array_unique($validEmails)));
            $entraLinked = $this->existingEntra->linkedNormalizedEmails(array_values(array_unique($validEmails)));
        } catch (\Throwable) {
            $identityLookupStatus = 'UNAVAILABLE';
        }
        $existingSet = array_fill_keys($existingPlatform, true);
        $entraSet = array_fill_keys($entraLinked, true);

        $summary = [
            'totalStaffCandidates' => count($staffCandidates),
            'staffWithValidEmail' => 0,
            'staffMissingEmail' => 0,
            'staffInvalidEmail' => 0,
            'duplicateEmailStaff' => 0,
            'existingTriCabUserMatches' => 0,
            'newUserCandidates' => 0,
            'otherConflicts' => 0,
            'administratorRole' => 0,
            'staffRole' => 0,
            'salesRole' => 0,
            'unexpectedStaffRoles' => 0,
            'entraAlreadyLinked' => 0,
            'entraLinkRequired' => 0,
            'eligibleForFutureMigration' => 0,
            'requiresReview' => 0,
            'excluded' => 0,
        ];
        $roleCounts = [];
        $unexpectedRoleCounts = [];
        $items = [];

        foreach ($staffCandidates as $candidate) {
            $email = trim((string) ($candidate['email'] ?? ''));
            $normalized = strtolower($email);
            $roles = is_array($candidate['legacyRoles'] ?? null) ? $candidate['legacyRoles'] : [];

            foreach ($roles as $role) {
                if (!is_string($role) || $role === '') {
                    continue;
                }
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;
                if (in_array($role, self::EXPECTED_STAFF_ROLES, true)) {
                    if ($role === 'administrator') {
                        ++$summary['administratorRole'];
                    } elseif ($role === 'staff') {
                        ++$summary['staffRole'];
                    } elseif ($role === 'sales') {
                        ++$summary['salesRole'];
                    }
                } else {
                    ++$summary['unexpectedStaffRoles'];
                    $unexpectedRoleCounts[$role] = ($unexpectedRoleCounts[$role] ?? 0) + 1;
                }
            }

            $emailStatus = 'VALID';
            $duplicateStatus = 'UNIQUE';
            $issues = [];

            if ($email === '') {
                $emailStatus = 'MISSING_EMAIL';
                ++$summary['staffMissingEmail'];
                ++$summary['excluded'];
                $issues[] = 'MISSING_EMAIL';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $emailStatus = 'INVALID_EMAIL';
                ++$summary['staffInvalidEmail'];
                ++$summary['excluded'];
                $issues[] = 'INVALID_EMAIL';
            } else {
                ++$summary['staffWithValidEmail'];
            }

            if ($normalized !== '' && isset($duplicateEmails[$normalized])) {
                $duplicateStatus = 'DUPLICATE_EMAIL';
                ++$summary['duplicateEmailStaff'];
                ++$summary['requiresReview'];
                $issues[] = 'DUPLICATE_EMAIL';
            }

            $existingStatus = 'NEW_USER_CANDIDATE';
            if ($normalized !== '' && isset($existingSet[$normalized])) {
                $existingStatus = 'EXISTING_USER_MATCH';
                ++$summary['existingTriCabUserMatches'];
                ++$summary['requiresReview'];
                $issues[] = 'EMAIL_ALREADY_EXISTS_IN_NEW_PLATFORM';
            } elseif ($emailStatus === 'VALID' && $duplicateStatus === 'UNIQUE') {
                ++$summary['newUserCandidates'];
            }

            $isCorporateEmail = str_ends_with($normalized, '@tricab.com');
            if (!$isCorporateEmail) {
                $entraStatus = 'NOT_APPLICABLE';
            } elseif (isset($entraSet[$normalized])) {
                $entraStatus = 'LINKED';
                ++$summary['entraAlreadyLinked'];
            } else {
                $entraStatus = 'LINK_REQUIRED';
                ++$summary['entraLinkRequired'];
            }

            $confidence = $this->staffConfidence($candidate, $tcStatus);
            $eligible = $emailStatus === 'VALID'
                && $duplicateStatus === 'UNIQUE'
                && $existingStatus === 'NEW_USER_CANDIDATE'
                && $issues === [];

            if ($eligible) {
                ++$summary['eligibleForFutureMigration'];
            } elseif ($emailStatus === 'MISSING_EMAIL' || $emailStatus === 'INVALID_EMAIL') {
                // already counted as excluded
            } elseif ($duplicateStatus === 'DUPLICATE_EMAIL' || $existingStatus === 'EXISTING_USER_MATCH') {
                if ($duplicateStatus === 'DUPLICATE_EMAIL') {
                    ++$summary['otherConflicts'];
                }
            }

            $items[] = [
                'legacyWordPressUserId' => $candidate['legacyWordPressUserId'],
                'legacyLogin' => $candidate['legacyLogin'],
                'displayName' => $candidate['displayName'],
                'email' => $email,
                'normalizedEmail' => $normalized,
                'legacyRole' => $candidate['legacyRole'],
                'legacyRoles' => $roles,
                'emailStatus' => $emailStatus,
                'duplicateStatus' => $duplicateStatus,
                'existingTriCabUserStatus' => $existingStatus,
                'entraStatus' => $entraStatus,
                'staffClassificationConfidence' => $confidence,
                'eligibility' => $eligible ? 'ELIGIBLE_FOR_FUTURE_MIGRATION' : ($emailStatus === 'MISSING_EMAIL' || $emailStatus === 'INVALID_EMAIL' ? 'EXCLUDED' : ($duplicateStatus === 'DUPLICATE_EMAIL' ? 'REQUIRES_REVIEW' : 'REQUIRES_REVIEW')),
                'issues' => $issues,
            ];
        }

        foreach ($this->legacySqlLogger->entries() as $entry) {
            $this->audit->recordLegacy($entry->sql);
        }

        return [
            'mode' => 'STRICT_READ_ONLY',
            'persistence' => 'MEMORY',
            'warning' => 'No database records will be created, edited, or deleted.',
            'tcEmployeeEnrichment' => $tcStatus,
            'identityLookup' => $identityLookupStatus,
            'tcAccessWarnings' => $this->tcAccessWarnings,
            'summary' => $summary,
            'roleCounts' => $roleCounts,
            'unexpectedRoleCounts' => $unexpectedRoleCounts,
            'proposedRoleMapping' => self::PROPOSED_ROLE_MAPPING,
            'roleRegistrySlugs' => $roleRegistry,
            'items' => $items,
            'databaseSafety' => $this->audit->summary(),
            'developmentOverride' => $this->config->developmentOverride->toPublicArray(),
        ];
    }

    /** @param array{primary: string, tags: list<string>} $classification */
    private function isStaffCandidate(array $classification): bool
    {
        $primary = $classification['primary'];
        $tags = $classification['tags'];

        return $primary === UserClassification::STAFF_MICROSOFT
            || $primary === UserClassification::STAFF_OTHER
            || in_array(UserClassification::STAFF_MICROSOFT, $tags, true)
            || in_array(UserClassification::STAFF_OTHER, $tags, true);
    }

    /** @param array<string, mixed> $candidate */
    private function staffConfidence(array $candidate, string $tcStatus): string
    {
        if ($candidate['classificationPrimary'] === UserClassification::STAFF_MICROSOFT) {
            if ($tcStatus === 'AVAILABLE' && !empty($candidate['employeeMatch'])) {
                return 'HIGH';
            }

            return 'MEDIUM';
        }

        return 'LOW';
    }

    private function tcEmployeeEnrichmentStatus(): string
    {
        if ($this->tcAccessWarnings === []) {
            return 'AVAILABLE';
        }
        if (isset($this->tcAccessWarnings['employee'])) {
            return 'UNAVAILABLE';
        }

        return 'PARTIAL';
    }

    private function chunkLimit(): int
    {
        return self::CHUNK;
    }

    private function quoteTable(string $name): string
    {
        return '`' . str_replace('`', '', $name) . '`';
    }

    /**
     * @return list<string>
     */
    private function loadRoleRegistry(string $optionsTable): array
    {
        $sql = "SELECT option_value FROM {$optionsTable} WHERE option_name = 'wp_user_roles' LIMIT 1";
        $this->audit->recordLegacy($sql);
        $rows = $this->wordpress->query($sql, [], self::REPO, 'roleRegistry');
        $value = isset($rows[0]['option_value']) && is_string($rows[0]['option_value']) ? $rows[0]['option_value'] : null;

        return $this->capabilities->roleSlugsFromWpUserRoles($value);
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array<string, string>>
     */
    private function loadAllowedMeta(string $metaTable, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $allowed = [];
        $params = [];
        foreach (UserClassification::ALLOWED_META_KEYS as $i => $key) {
            $placeholder = 'k' . $i;
            $allowed[] = ':' . $placeholder;
            $params[$placeholder] = $key;
        }
        $ids = [];
        foreach ($userIds as $i => $id) {
            $placeholder = 'u' . $i;
            $ids[] = ':' . $placeholder;
            $params[$placeholder] = $id;
        }
        $sql = 'SELECT user_id, meta_key, meta_value FROM ' . $metaTable
            . ' WHERE user_id IN (' . implode(',', $ids) . ')'
            . ' AND meta_key IN (' . implode(',', $allowed) . ')';
        $this->audit->recordLegacy($sql);
        $rows = $this->wordpress->query($sql, $params, self::REPO, 'allowedMeta');
        $out = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? $row['user_ID'] ?? 0);
            $key = (string) ($row['meta_key'] ?? '');
            if ($userId < 1 || $key === '') {
                continue;
            }
            if (in_array(strtolower($key), ['user_pass', 'user_activation_key', 'session_tokens'], true)) {
                continue;
            }
            $out[$userId][$key] = (string) ($row['meta_value'] ?? '');
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function indexEmployees(): array
    {
        $out = [];
        $lastLogin = '';
        while (true) {
            $sql = 'SELECT user_login FROM `employee` WHERE user_login > :lastLogin ORDER BY user_login ASC LIMIT ' . self::CHUNK;
            try {
                $this->audit->recordLegacy($sql);
                $rows = $this->tc->query($sql, ['lastLogin' => $lastLogin], self::REPO, 'employees');
            } catch (\PDOException $e) {
                if ($this->isTcSelectDenied($e, 'employee')) {
                    $this->tcAccessWarnings['employee'] = 'SELECT denied on tc.employee';
                    break;
                }
                throw $e;
            }
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $login = trim((string) ($row['user_login'] ?? ''));
                if ($login === '') {
                    continue;
                }
                $lastLogin = $login;
                $out[strtolower($login)] = ['legacyLogin' => $login, 'matched' => true];
            }
            if (count($rows) < self::CHUNK) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private static function wordPressUserId(array $row): int
    {
        if (isset($row['ID'])) {
            return (int) $row['ID'];
        }
        if (isset($row['id'])) {
            return (int) $row['id'];
        }

        return 0;
    }

    private function isTcSelectDenied(\PDOException $e, string $table): bool
    {
        if ((int) ($e->errorInfo[1] ?? 0) === 1142) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'select command denied')
            && str_contains(strtolower($e->getMessage()), strtolower($table));
    }
}
