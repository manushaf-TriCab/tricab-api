<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Plugin;

use TriCab\Core\LegacyMigration\Application\Exception\InvalidLegacyMigrationState;
use TriCab\Core\LegacyMigration\Contract\Extractor;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Live\UserIdentityClassifier;
use TriCab\Core\LegacyMigration\Live\WordPressCapabilityParser;
use TriCab\Core\LegacyMigration\Support\CredentialRedactor;
use TriCab\Core\LegacyMigration\Support\RawRecord;
use TriCab\Core\LegacyMigration\Support\UserClassification;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * SELECT-only WordPress + tc identity extractor. Never selects credential columns.
 */
final class LegacyWordPressUserExtractor implements Extractor
{
    private const REPO = 'LegacyWordPressUserExtractor';
    private const CHUNK = 500;

    /** @var array<string, string> */
    private array $tcAccessWarnings = [];

    /**
     * @param array<string, mixed>|null $precomputedHealth
     */
    public function __construct(
        private readonly LegacyReadOnlyConnection $wordpress,
        private readonly LegacyReadOnlyConnection $tc,
        private readonly LegacyIdentitySourceConfig $config,
        private readonly LegacyIdentitySourceHealth $health,
        private readonly WordPressCapabilityParser $capabilities = new WordPressCapabilityParser(),
        private readonly UserIdentityClassifier $classifier = new UserIdentityClassifier(),
        private readonly ?array $precomputedHealth = null,
    ) {
    }

    public function supportsIncremental(): bool
    {
        return false;
    }

    public function extract(array $filter): iterable
    {
        unset($filter);
        $this->assertSafe();

        $prefix = $this->config->legacy->wordpressTablePrefix;
        $usersTable = $this->quoteTable($prefix . 'users');
        $metaTable = $this->quoteTable($prefix . 'usermeta');
        $optionsTable = $this->quoteTable($prefix . 'options');

        $roleRegistry = $this->loadRoleRegistry($optionsTable);
        yield new RawRecord(
            sourceSystem: 'wordpress',
            sourceTable: $prefix . 'options',
            sourceIdentifier: 'wp_user_roles',
            payload: ['slugs' => $roleRegistry],
            recordType: 'roles',
        );

        $employees = $this->indexEmployees();
        foreach ($employees as $login => $employee) {
            yield new RawRecord(
                sourceSystem: 'business-db',
                sourceTable: 'employee',
                sourceIdentifier: $login,
                payload: $employee,
                recordType: 'employees',
            );
        }

        $contactsByEmail = $this->indexContactsByEmail();
        foreach ($this->flattenContacts($contactsByEmail) as $contact) {
            yield new RawRecord(
                sourceSystem: 'business-db',
                sourceTable: 'contacts',
                sourceIdentifier: (string) ($contact['legacyContactId'] ?? $contact['legacyCustomerId'] ?? $contact['email']),
                payload: $contact,
                recordType: 'contacts',
            );
        }

        $explicitWpIds = $this->indexCustomerContactWpIds();
        $aliFlex = $this->indexAliFlex();
        $duplicateEmails = $this->duplicateEmails($usersTable);
        $metaInventory = $this->metaKeyInventory($metaTable);

        $matchedEmployeeLogins = [];
        $matchedWpIds = [];
        $lastId = 0;
        while (true) {
            $users = $this->wordpress->query(
                <<<SQL
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
SQL,
                ['lastId' => $lastId],
                self::REPO,
                'usersChunk',
            );
            if ($users === []) {
                break;
            }

            $ids = [];
            foreach ($users as $user) {
                $ids[] = self::wordPressUserId($user);
            }
            $metaByUser = $this->loadAllowedMeta($metaTable, $ids);

            foreach ($users as $user) {
                $id = self::wordPressUserId($user);
                if ($id < 1) {
                    continue;
                }
                $lastId = max($lastId, $id);
                $matchedWpIds[$id] = true;
                $login = trim((string) ($user['user_login'] ?? ''));
                $email = trim((string) ($user['user_email'] ?? ''));
                $status = (int) ($user['user_status'] ?? 0);
                $meta = $metaByUser[$id] ?? [];
                $roles = $this->capabilities->rolesFromCapabilities(
                    isset($meta['wp_capabilities']) && is_string($meta['wp_capabilities']) ? $meta['wp_capabilities'] : null,
                );
                $unknownRoles = $this->capabilities->unknownRoles($roles);
                $employee = $employees[strtolower($login)] ?? null;
                if ($employee !== null) {
                    $matchedEmployeeLogins[strtolower($login)] = true;
                }
                $contactMatches = $this->matchContacts($id, $email, $contactsByEmail, $explicitWpIds);
                $realMatches = array_values(array_filter(
                    $contactMatches,
                    static fn (array $m): bool => ($m['matchType'] ?? '') !== 'NO_CONTACT',
                ));
                $ambiguous = count($realMatches) > 1
                    || in_array('MULTIPLE_EMAIL_MATCH', array_column($realMatches, 'matchType'), true);
                $emailKey = strtolower($email);
                $duplicateEmail = $emailKey !== '' && isset($duplicateEmails[$emailKey]);
                $aliCount = (int) ($aliFlex[$id] ?? 0);
                $classification = $this->classifier->classify(
                    login: $login,
                    email: $email,
                    employeeMatch: $employee !== null,
                    contactMatches: $realMatches,
                    roles: $roles,
                    hasAliFlex: $aliCount > 0,
                    legacyStatus: $status,
                    duplicateEmail: $duplicateEmail,
                    ambiguousContacts: $ambiguous,
                );

                $safeMeta = [];
                foreach (UserClassification::ALLOWED_META_KEYS as $key) {
                    if ($key === 'wp_capabilities') {
                        $safeMeta[$key] = $roles;
                        continue;
                    }
                    if (isset($meta[$key]) && !CredentialRedactor::isForbidden($key)) {
                        $safeMeta[$key] = $meta[$key];
                    }
                }

                yield new RawRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: $prefix . 'usermeta',
                    sourceIdentifier: (string) $id . ':meta',
                    payload: CredentialRedactor::strip([
                        'legacyWordPressUserId' => $id,
                        'meta' => $safeMeta,
                    ]),
                    recordType: 'user-meta',
                );

                $payload = CredentialRedactor::strip([
                    'legacyWordPressUserId' => $id,
                    'legacyId' => (string) $id,
                    'legacyLogin' => $login,
                    'email' => $email,
                    'normalizedEmail' => strtolower($email),
                    'nicename' => (string) ($user['user_nicename'] ?? ''),
                    'url' => (string) ($user['user_url'] ?? ''),
                    'registeredAt' => (string) ($user['user_registered'] ?? ''),
                    'legacyStatus' => $status,
                    'displayName' => (string) ($user['display_name'] ?? ''),
                    'firstName' => (string) ($safeMeta['first_name'] ?? ''),
                    'lastName' => (string) ($safeMeta['last_name'] ?? ''),
                    'meta' => $safeMeta,
                    'legacyRoles' => $roles,
                    'unknownRoles' => $unknownRoles,
                    'roleRegistrySlugs' => $roleRegistry,
                    'employeeMatch' => $employee,
                    'contactMatches' => $contactMatches,
                    'hasAliFlexRecords' => $aliCount > 0,
                    'aliFlexRecordCount' => $aliCount,
                    'classification' => $classification['primary'],
                    'classificationTags' => $classification['tags'],
                    'entraObjectId' => 'UNKNOWN',
                    'entraLinkRequired' => $classification['primary'] === UserClassification::STAFF_MICROSOFT,
                ]);

                yield new RawRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: $prefix . 'users',
                    sourceIdentifier: (string) $id,
                    payload: $payload,
                    recordType: 'users',
                );
            }
            if (count($users) < $this->chunkLimit()) {
                break;
            }
        }

        $unmatchedEmployees = [];
        foreach ($employees as $login => $employee) {
            if (!isset($matchedEmployeeLogins[$login])) {
                $unmatchedEmployees[] = $employee['legacyLogin'] ?? $login;
            }
        }
        $unmatchedContactWpIds = [];
        foreach (array_keys($explicitWpIds) as $wpId) {
            if (!isset($matchedWpIds[$wpId])) {
                $unmatchedContactWpIds[] = $wpId;
            }
        }

        yield new RawRecord(
            sourceSystem: 'wordpress',
            sourceTable: $prefix . 'usermeta',
            sourceIdentifier: '__meta_inventory__',
            payload: [
                'keys' => $metaInventory,
                'unmatchedEmployeeLogins' => $unmatchedEmployees,
                'unmatchedCustomerContactWpIds' => $unmatchedContactWpIds,
            ],
            recordType: 'user-meta-inventory',
        );

        yield new RawRecord(
            sourceSystem: 'wordpress',
            sourceTable: $prefix . 'users',
            sourceIdentifier: '__inventory__',
            payload: [
                'unmatchedEmployeeLogins' => $unmatchedEmployees,
                'unmatchedCustomerContactWpIds' => $unmatchedContactWpIds,
                'roleRegistrySlugs' => $roleRegistry,
                'tcAccessWarnings' => $this->tcAccessWarnings,
            ],
            recordType: 'users',
        );
    }

    private function assertSafe(): void
    {
        if (!$this->config->readOnlyFlagsPresent()) {
            throw InvalidLegacyMigrationState::because(
                'extract (LEGACY_WP_READ_ONLY and LEGACY_TC_READ_ONLY must be true)',
                'blocked',
            );
        }
        $wp = $this->precomputedHealth['wordpress'] ?? $this->health->inspect(
            $this->wordpress,
            'wordpress',
            $this->config->expectedWordpressDatabase,
            $this->config->wordpressReadOnly,
            (string) ($this->config->legacy->wordpressSslMode ?? 'required'),
            $this->config->developmentOverride,
        );
        $tc = $this->precomputedHealth['tc'] ?? $this->health->inspect(
            $this->tc,
            'business-db',
            $this->config->expectedTcDatabase,
            $this->config->tcReadOnly,
            (string) ($this->config->legacy->tcSslMode ?? 'required'),
            $this->config->developmentOverride,
        );
        if (empty($wp['extractionAllowed']) || empty($tc['extractionAllowed'])) {
            throw InvalidLegacyMigrationState::because(
                'extract (' . (string) ($wp['message'] ?? $tc['message'] ?? 'legacy source not safe') . ')',
                'blocked',
            );
        }
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
        $rows = $this->wordpress->query(
            "SELECT option_value FROM {$optionsTable} WHERE option_name = 'wp_user_roles' LIMIT 1",
            [],
            self::REPO,
            'roleRegistry',
        );
        $value = isset($rows[0]['option_value']) && is_string($rows[0]['option_value']) ? $rows[0]['option_value'] : null;

        return $this->capabilities->roleSlugsFromWpUserRoles($value);
    }

    /**
     * @return array<string, true>
     */
    private function duplicateEmails(string $usersTable): array
    {
        $rows = $this->wordpress->query(
            "SELECT LOWER(user_email) AS email_key, COUNT(*) AS record_count FROM {$usersTable} WHERE user_email IS NOT NULL AND user_email <> '' GROUP BY LOWER(user_email) HAVING COUNT(*) > 1",
            [],
            self::REPO,
            'duplicateEmails',
        );
        $out = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['email_key'] ?? '')));
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
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
        $rows = $this->wordpress->query($sql, $params, self::REPO, 'allowedMeta');
        $out = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $key = (string) ($row['meta_key'] ?? '');
            if ($userId < 1 || $key === '' || CredentialRedactor::isForbidden($key)) {
                continue;
            }
            $out[$userId][$key] = (string) ($row['meta_value'] ?? '');
        }

        return $out;
    }

    /**
     * @return list<array{metaKey: string, recordCount: int, classification: string}>
     */
    private function metaKeyInventory(string $metaTable): array
    {
        $rows = $this->wordpress->query(
            'SELECT meta_key, COUNT(*) AS record_count FROM ' . $metaTable . ' GROUP BY meta_key',
            [],
            self::REPO,
            'metaInventory',
        );
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            if ($key === '' || CredentialRedactor::isForbidden($key)) {
                continue;
            }
            $known = in_array($key, UserClassification::ALLOWED_META_KEYS, true);
            $out[] = [
                'metaKey' => $key,
                'recordCount' => (int) ($row['record_count'] ?? 0),
                'classification' => $known ? 'allowed' : 'unknown',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexEmployees(): array
    {
        $out = [];
        $lastLogin = '';
        while (true) {
            $rows = $this->tcQuery(
                'SELECT user_login FROM `employee` WHERE user_login > :lastLogin ORDER BY user_login ASC LIMIT ' . self::CHUNK,
                ['lastLogin' => $lastLogin],
                'employees',
                'employee',
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $login = trim((string) ($row['user_login'] ?? ''));
                if ($login === '') {
                    continue;
                }
                $lastLogin = $login;
                $out[strtolower($login)] = [
                    'legacyLogin' => $login,
                    'matched' => true,
                ];
            }
            if (count($rows) < self::CHUNK) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexContactsByEmail(): array
    {
        $out = [];
        $lastId = 0;
        while (true) {
            $rows = $this->tcQuery(
                'SELECT id, contact_id, email FROM `contacts` WHERE id > :lastId ORDER BY id ASC LIMIT ' . self::CHUNK,
                ['lastId' => $lastId],
                'contacts',
                'contacts',
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = max($lastId, (int) ($row['id'] ?? 0));
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                $out[$email][] = [
                    'legacyContactId' => $row['contact_id'] ?? null,
                    'legacyCustomerId' => $row['id'] ?? null,
                    'email' => $email,
                ];
            }
            if (count($rows) < self::CHUNK) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $contactsByEmail
     * @return list<array<string, mixed>>
     */
    private function flattenContacts(array $contactsByEmail): array
    {
        $out = [];
        foreach ($contactsByEmail as $rows) {
            foreach ($rows as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<int, true>
     */
    private function indexCustomerContactWpIds(): array
    {
        $rows = $this->tcQuery(
            'SELECT contact_id FROM `customer_contact`',
            [],
            'customerContact',
            'customer_contact',
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['contact_id'] ?? 0);
            if ($id > 0) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function indexAliFlex(): array
    {
        $rows = $this->tcQuery(
            'SELECT userid, COUNT(*) AS record_count FROM `ali_flex_cert` GROUP BY userid',
            [],
            'aliFlex',
            'ali_flex_cert',
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['userid'] ?? 0);
            if ($id > 0) {
                $out[$id] = (int) ($row['record_count'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $contactsByEmail
     * @param array<int, true> $explicitWpIds
     * @return list<array<string, mixed>>
     */
    private function matchContacts(int $wpId, string $email, array $contactsByEmail, array $explicitWpIds): array
    {
        $matches = [];
        if (isset($explicitWpIds[$wpId])) {
            $matches[] = [
                'legacyWordPressUserId' => $wpId,
                'matchType' => 'EXPLICIT_WP_ID',
                'matchConfidence' => 'high',
            ];
        }
        $emailKey = strtolower(trim($email));
        $emailHits = $emailKey === '' ? [] : ($contactsByEmail[$emailKey] ?? []);
        if (count($emailHits) === 1) {
            $hit = $emailHits[0];
            $matches[] = [
                'legacyWordPressUserId' => $wpId,
                'legacyContactId' => $hit['legacyContactId'] ?? null,
                'legacyCustomerId' => $hit['legacyCustomerId'] ?? null,
                'email' => $hit['email'] ?? $emailKey,
                'matchType' => 'UNIQUE_EMAIL',
                'matchConfidence' => 'medium',
            ];
        } elseif (count($emailHits) > 1) {
            foreach ($emailHits as $hit) {
                $matches[] = [
                    'legacyWordPressUserId' => $wpId,
                    'legacyContactId' => $hit['legacyContactId'] ?? null,
                    'legacyCustomerId' => $hit['legacyCustomerId'] ?? null,
                    'email' => $hit['email'] ?? $emailKey,
                    'matchType' => 'MULTIPLE_EMAIL_MATCH',
                    'matchConfidence' => 'low',
                ];
            }
        }

        if ($matches === []) {
            return [[
                'legacyWordPressUserId' => $wpId,
                'matchType' => 'NO_CONTACT',
                'matchConfidence' => 'none',
            ]];
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function tcQuery(string $sql, array $params, string $method, string $table): array
    {
        try {
            return $this->tc->query($sql, $params, self::REPO, $method);
        } catch (\PDOException $e) {
            if (!$this->isTcSelectDenied($e, $table)) {
                throw $e;
            }
            $this->tcAccessWarnings[$table] = 'SELECT denied on tc.' . $table . '. TC enrichment skipped for this table.';

            return [];
        }
    }

    private function isTcSelectDenied(\PDOException $e, string $table): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 1142) {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'select command denied')
            && str_contains(strtolower($e->getMessage()), strtolower($table));
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
}
