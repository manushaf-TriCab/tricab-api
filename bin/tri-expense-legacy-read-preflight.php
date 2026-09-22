<?php

declare(strict_types=1);

/**
 * TriExpense legacy-read production preflight (READ-ONLY).
 *
 * Verifies MySQL connectivity / SSL / SELECT / repository constructibility /
 * receipt counts / identity mapping / ownership isolation WITHOUT enabling
 * TRIEXPENSE_LEGACY_READ_ENABLED.
 *
 * Never prints passwords, DSNs, emails, logins, or receipt payloads.
 *
 * Usage (on App Service or any host with LEGACY_TC_* in env):
 *   php bin/tri-expense-legacy-read-preflight.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Expense\Legacy\MysqlLegacyExpenseReadRepository;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

/**
 * @param array<string, mixed> $report
 */
function te_preflight_out(array $report, int $exit): never
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($exit);
}

$env = [];
foreach ($_ENV as $k => $v) {
    if (is_string($k) && (is_string($v) || is_numeric($v) || $v === null)) {
        $env[$k] = $v === null ? null : (string) $v;
    }
}
foreach ($_SERVER as $k => $v) {
    if (is_string($k) && is_string($v) && !array_key_exists($k, $env)) {
        $env[$k] = $v;
    }
}
// getenv fallback for App Service injected settings
foreach ([
    'LEGACY_TC_HOST', 'LEGACY_TC_PORT', 'LEGACY_TC_DATABASE', 'LEGACY_TC_USER', 'LEGACY_TC_PASSWORD',
    'LEGACY_TC_SSL_MODE', 'LEGACY_TC_READ_ONLY', 'LEGACY_TC_DRIVER', 'LEGACY_TC_DSN',
    'LEGACY_TC_ALLOW_WRITE_CAPABLE_READER', 'TRIEXPENSE_LEGACY_READ_ENABLED',
] as $key) {
    $g = getenv($key);
    if ($g !== false && !array_key_exists($key, $env)) {
        $env[$key] = $g;
    }
}

$flagRaw = strtolower(trim((string) ($env['TRIEXPENSE_LEGACY_READ_ENABLED'] ?? '')));
$flagEnabled = in_array($flagRaw, ['1', 'true', 'yes', 'on'], true);

$report = [
    'check' => 'tri-expense-legacy-read-preflight',
    'readOnly' => true,
    'featureFlag' => [
        'TRIEXPENSE_LEGACY_READ_ENABLED' => $flagEnabled ? 'true' : 'unset_or_false',
        'mustRemainOff' => true,
        'pass' => !$flagEnabled,
    ],
    'envPresence' => [
        'LEGACY_TC_HOST' => trim((string) ($env['LEGACY_TC_HOST'] ?? '')) !== '',
        'LEGACY_TC_PORT' => trim((string) ($env['LEGACY_TC_PORT'] ?? '')) !== '',
        'LEGACY_TC_DATABASE' => trim((string) ($env['LEGACY_TC_DATABASE'] ?? '')) !== '',
        'LEGACY_TC_USER' => trim((string) ($env['LEGACY_TC_USER'] ?? '')) !== '',
        'LEGACY_TC_PASSWORD' => trim((string) ($env['LEGACY_TC_PASSWORD'] ?? '')) !== '',
        'LEGACY_TC_SSL_MODE' => trim((string) ($env['LEGACY_TC_SSL_MODE'] ?? '')) !== '',
        'LEGACY_TC_READ_ONLY' => trim((string) ($env['LEGACY_TC_READ_ONLY'] ?? '')) !== '',
        'LEGACY_TC_DSN' => trim((string) ($env['LEGACY_TC_DSN'] ?? '')) !== '',
    ],
    'config' => [
        'userIsTriexpenseRo' => strtolower(trim((string) ($env['LEGACY_TC_USER'] ?? ''))) === 'triexpense_ro',
        'sslMode' => strtolower(trim((string) ($env['LEGACY_TC_SSL_MODE'] ?? ''))),
        'readOnly' => strtolower(trim((string) ($env['LEGACY_TC_READ_ONLY'] ?? ''))),
        'database' => trim((string) ($env['LEGACY_TC_DATABASE'] ?? '')),
        'hostSuffix' => (($h = trim((string) ($env['LEGACY_TC_HOST'] ?? ''))) !== '' && str_contains($h, '.'))
            ? substr($h, strpos($h, '.') + 1)
            : '',
        'port' => trim((string) ($env['LEGACY_TC_PORT'] ?? '')),
        'driver' => trim((string) ($env['LEGACY_TC_DRIVER'] ?? '')),
    ],
    'mysql' => [
        'connected' => false,
        'sslCipherPresent' => false,
        'currentUserIsTriexpenseRo' => false,
        'database' => null,
        'selectReceiptsOk' => false,
    ],
    'counts' => [
        'receiptsTotal' => null,
        'receiptsActive' => null,
        'receiptsDeleted' => null,
        'distinctCreatedBy' => null,
    ],
    'repository' => [
        'constructible' => false,
        'class' => null,
        'listForOwnerKeysCallable' => false,
    ],
    'identity' => [
        'source' => null,
        'profilesWithLegacyKeys' => null,
        'profilesMatchedToReceipts' => null,
        'profilesUnmatched' => null,
        'pass' => null,
    ],
    'ownershipIsolation' => [
        'sampledOwners' => 0,
        'crossOwnerLeakCount' => null,
        'pass' => null,
    ],
    'secretsPrinted' => 0,
    'overallPass' => false,
];

if ($flagEnabled) {
    $report['error'] = 'TRIEXPENSE_LEGACY_READ_ENABLED is true — aborting preflight (flag must stay off).';
    te_preflight_out($report, 2);
}

$config = LiveLegacySourceConfig::fromEnvironment($env);
if (!$config->tcConfigured()) {
    $report['error'] = 'LEGACY_TC_* not sufficiently configured (host/db/user/password).';
    te_preflight_out($report, 3);
}

$user = strtolower(trim((string) $config->tcUser));
if ($user !== 'triexpense_ro') {
    $report['error'] = 'Refusing preflight: LEGACY_TC_USER must be triexpense_ro (got non-matching user).';
    te_preflight_out($report, 4);
}

$readOnlyConfigured = in_array(strtolower(trim((string) ($env['LEGACY_TC_READ_ONLY'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
if (!$readOnlyConfigured) {
    $report['error'] = 'LEGACY_TC_READ_ONLY must be true.';
    te_preflight_out($report, 5);
}

$guard = new LegacyReadOnlySqlGuard();
$factory = new ReadOnlyMysqlConnectionFactory($guard, new InMemoryLegacySqlStatementLogger());

try {
    $connection = $factory->create(
        (string) $config->tcDsn,
        (string) $config->tcUser,
        (string) $config->tcPassword,
        'tc-preflight',
        $config->tcSslMode,
    );
} catch (Throwable $e) {
    $report['error'] = 'MySQL connect failed: ' . $e->getMessage();
    te_preflight_out($report, 6);
}

$report['mysql']['connected'] = true;

try {
    $dbRows = $connection->query('SELECT DATABASE() AS db_name', [], 'Preflight', 'database');
    $userRows = $connection->query('SELECT CURRENT_USER() AS session_user', [], 'Preflight', 'currentUser');
    $sslRows = $connection->query("SHOW STATUS LIKE 'Ssl_cipher'", [], 'Preflight', 'ssl');
    $dbName = (string) ($dbRows[0]['db_name'] ?? '');
    $sessionUser = (string) ($userRows[0]['session_user'] ?? '');
    $cipher = '';
    foreach ($sslRows as $row) {
        $name = strtolower((string) ($row['Variable_name'] ?? $row['variable_name'] ?? ''));
        if ($name === 'ssl_cipher') {
            $cipher = (string) ($row['Value'] ?? $row['value'] ?? '');
        }
    }
    $report['mysql']['database'] = $dbName;
    $report['mysql']['sslCipherPresent'] = $cipher !== '';
    $report['mysql']['currentUserIsTriexpenseRo'] = str_starts_with(strtolower($sessionUser), 'triexpense_ro@');

    $countRows = $connection->query(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN deleted_at IS NULL THEN 1 ELSE 0 END) AS active_rows,
            SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_rows,
            COUNT(DISTINCT LOWER(TRIM(created_by))) AS distinct_created_by
         FROM tc.triexpense_receipts',
        [],
        'Preflight',
        'counts',
    );
    $report['mysql']['selectReceiptsOk'] = true;
    $report['counts']['receiptsTotal'] = (int) ($countRows[0]['total_rows'] ?? 0);
    $report['counts']['receiptsActive'] = (int) ($countRows[0]['active_rows'] ?? 0);
    $report['counts']['receiptsDeleted'] = (int) ($countRows[0]['deleted_rows'] ?? 0);
    $report['counts']['distinctCreatedBy'] = (int) ($countRows[0]['distinct_created_by'] ?? 0);
} catch (Throwable $e) {
    $report['error'] = 'MySQL SELECT/SSL probe failed: ' . $e->getMessage();
    te_preflight_out($report, 7);
}

try {
    $repo = new MysqlLegacyExpenseReadRepository($connection, $guard);
    $report['repository']['constructible'] = true;
    $report['repository']['class'] = MysqlLegacyExpenseReadRepository::class;
    // Empty owner keys must return [] (no unscoped dump).
    $empty = $repo->listForOwnerKeys([]);
    $nonsense = $repo->listForOwnerKeys(['__preflight_nonexistent_owner__']);
    $report['repository']['listForOwnerKeysCallable'] = is_array($empty) && $empty === [] && is_array($nonsense) && $nonsense === [];
} catch (Throwable $e) {
    $report['error'] = 'MysqlLegacyExpenseReadRepository init failed: ' . $e->getMessage();
    te_preflight_out($report, 8);
}

// Identity mapping: prefer Azure SQL ExpenseProfiles ↔ MySQL created_by.
$resolver = new LegacyExpenseIdentityResolver();
/** @var list<ExpenseProfile> $profiles */
$profiles = [];
$identitySource = 'synthetic_from_mysql_created_by';
try {
    $basePath = dirname(__DIR__);
    $settings = Environment::settings($basePath);
    $container = ContainerFactory::create($settings);
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $profileRows = $sql->fetchAll(
        'SELECT TOP 200 [UserId], [LegacyLogin], [LegacyEmail], [IsActive], [IsFinance], [IsExpenseAdmin], [ManagerUserId], [LegacyManagerLogin]
         FROM [expense].[ExpenseProfiles]
         WHERE [IsActive] = 1
           AND (
             (LegacyLogin IS NOT NULL AND LTRIM(RTRIM(LegacyLogin)) <> \'\')
             OR (LegacyEmail IS NOT NULL AND LTRIM(RTRIM(LegacyEmail)) <> \'\')
           )',
    );
    foreach ($profileRows as $pr) {
        $login = trim((string) ($pr['LegacyLogin'] ?? ''));
        $email = trim((string) ($pr['LegacyEmail'] ?? ''));
        if ($login === '' && $email === '') {
            continue;
        }
        if ($login === '') {
            $login = explode('@', strtolower($email), 2)[0];
        }
        if ($email === '' || !str_contains($email, '@')) {
            $email = $login . '@tricab.com';
        }
        try {
            $profiles[] = new ExpenseProfile(
                UserId::fromInt((int) $pr['UserId']),
                $login,
                $email,
                (bool) $pr['IsActive'],
                (bool) $pr['IsFinance'],
                (bool) $pr['IsExpenseAdmin'],
                isset($pr['LegacyManagerLogin']) ? (string) $pr['LegacyManagerLogin'] : null,
                isset($pr['ManagerUserId']) && $pr['ManagerUserId'] !== null
                    ? UserId::fromInt((int) $pr['ManagerUserId'])
                    : null,
            );
        } catch (Throwable) {
            // skip invalid profile rows
        }
    }
    if ($profiles !== []) {
        $identitySource = 'azure_sql_expense_profiles';
    }
} catch (Throwable $e) {
    $report['identity']['azureSqlNote'] = 'Azure SQL profile load skipped: ' . $e->getMessage();
}

if ($profiles === []) {
    // Fallback: derive synthetic profiles from top MySQL owners (still validates resolver + isolation).
    try {
        $ownerRows = $connection->query(
            'SELECT LOWER(TRIM(created_by)) AS owner_key, COUNT(*) AS c
             FROM tc.triexpense_receipts
             WHERE deleted_at IS NULL AND created_by IS NOT NULL AND TRIM(created_by) <> \'\'
             GROUP BY LOWER(TRIM(created_by))
             ORDER BY c DESC
             LIMIT 25',
            [],
            'Preflight',
            'topOwners',
        );
    } catch (Throwable $e) {
        $report['error'] = 'Owner aggregation failed: ' . $e->getMessage();
        te_preflight_out($report, 9);
    }
    foreach ($ownerRows as $idx => $row) {
        $ownerKey = strtolower(trim((string) ($row['owner_key'] ?? '')));
        if ($ownerKey === '') {
            continue;
        }
        $login = str_contains($ownerKey, '@') ? explode('@', $ownerKey, 2)[0] : $ownerKey;
        $email = str_contains($ownerKey, '@') ? $ownerKey : ($ownerKey . '@tricab.com');
        try {
            $profiles[] = new ExpenseProfile(
                UserId::fromInt(1000 + $idx),
                $login,
                $email,
                true,
                false,
                false,
                null,
                null,
            );
        } catch (Throwable) {
            continue;
        }
    }
}

$matched = 0;
$unmatched = 0;
$sampled = 0;
$leak = 0;
/** @var list<list<string>> $sampledKeySets */
$sampledKeySets = [];
/** @var list<list<array<string, mixed>>> $sampledRows */
$sampledRows = [];

foreach ($profiles as $profile) {
    $keys = $resolver->ownerKeys($profile);
    if ($keys === []) {
        $unmatched++;
        continue;
    }
    $rows = $repo->listForOwnerKeys($keys, 100);
    if ($rows === []) {
        $unmatched++;
        continue;
    }
    $matched++;
    if ($sampled < 2) {
        $sampledKeySets[] = $keys;
        $sampledRows[] = $rows;
        $sampled++;
        foreach ($rows as $receipt) {
            $createdBy = strtolower(trim((string) ($receipt['created_by'] ?? '')));
            if ($createdBy !== '' && !in_array($createdBy, $keys, true)) {
                $leak++;
            }
        }
    }
}

if (count($sampledKeySets) >= 2) {
    $idsA = [];
    foreach ($sampledRows[0] as $r) {
        $idsA[(int) ($r['id'] ?? 0)] = true;
    }
    foreach ($sampledRows[1] as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0 && isset($idsA[$id])) {
            $leak++;
        }
    }
}

$report['identity']['source'] = $identitySource;
$report['identity']['profilesWithLegacyKeys'] = count($profiles);
$report['identity']['profilesMatchedToReceipts'] = $matched;
$report['identity']['profilesUnmatched'] = $unmatched;
$report['identity']['pass'] = $matched > 0;
$report['ownershipIsolation']['sampledOwners'] = $sampled;
$report['ownershipIsolation']['crossOwnerLeakCount'] = $leak;
$report['ownershipIsolation']['pass'] = $sampled >= 2 && $leak === 0;
$report['overallPass'] = $report['featureFlag']['pass']
    && $report['config']['userIsTriexpenseRo']
    && $report['mysql']['connected']
    && $report['mysql']['sslCipherPresent']
    && $report['mysql']['currentUserIsTriexpenseRo']
    && $report['mysql']['selectReceiptsOk']
    && $report['repository']['constructible']
    && $report['repository']['listForOwnerKeysCallable']
    && $report['identity']['pass'] === true
    && $report['ownershipIsolation']['pass'] === true
    && ($report['counts']['receiptsActive'] ?? 0) > 0;

te_preflight_out($report, $report['overallPass'] ? 0 : 10);
