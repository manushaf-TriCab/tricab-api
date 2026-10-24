<?php

declare(strict_types=1);

/**
 * TriExpense permission-migration DRY-RUN ONLY.
 *
 * Proposes TRIEXPENSE.* grants from ExpenseProfiles (+ optional legacy
 * tc.triexpense_users) and compares against current identity effective
 * permissions. ZERO writes. Blocks production.
 *
 * Mapping:
 *   active profile / legacy is_active     → ACCESS
 *   is_finance (profile or legacy)        → FINANCE (+ AUDIT via finance role)
 *   is_admin / IsExpenseAdmin             → ADMIN (NOT finance)
 *   appears as ManagerUserId / manager_login target on active peers → APPROVE
 *   platform ADMINISTRATOR does NOT imply FINANCE
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Module;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

/**
 * @return array<string, string>
 */
function te_perm_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\"'");
    }

    return $out;
}

function te_perm_mask_login(string $login): string
{
    $login = strtolower(trim($login));
    if ($login === '') {
        return '***';
    }
    if (strlen($login) <= 2) {
        return $login[0] . '***';
    }

    return substr($login, 0, 2) . '***';
}

function te_perm_mask_email(string $email): string
{
    $email = strtolower(trim($email));
    $at = strpos($email, '@');
    if ($at === false) {
        return te_perm_mask_login($email);
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($local === '') {
        return '***@' . $domain;
    }
    if (strlen($local) === 1) {
        return $local . '***@' . $domain;
    }

    return $local[0] . '***' . $local[strlen($local) - 1] . '@' . $domain;
}

/**
 * @param list<string> $codes
 * @return list<string>
 */
function te_perm_normalize(array $codes): array
{
    $out = [];
    foreach ($codes as $code) {
        $normalized = strtoupper(trim($code));
        if ($normalized !== '') {
            $out[$normalized] = $normalized;
        }
    }
    $list = array_values($out);
    sort($list);

    return $list;
}

/**
 * @param array{is_active: bool, is_finance: bool, is_admin: bool} $flags
 * @return list<string>
 */
function te_perm_desired(array $flags, bool $isApprover): array
{
    $desired = [];
    if ($flags['is_active']) {
        $desired[Module::PERMISSION_ACCESS] = true;
    }
    if ($isApprover) {
        $desired[Module::PERMISSION_ACCESS] = true;
        $desired[Module::PERMISSION_APPROVE] = true;
    }
    if ($flags['is_finance']) {
        $desired[Module::PERMISSION_ACCESS] = true;
        $desired[Module::PERMISSION_APPROVE] = true;
        $desired[Module::PERMISSION_FINANCE] = true;
        $desired[Module::PERMISSION_AUDIT] = true;
    }
    if ($flags['is_admin']) {
        $desired[Module::PERMISSION_ACCESS] = true;
        $desired[Module::PERMISSION_ADMIN] = true;
        // ADMIN is NOT finance
    }

    return te_perm_normalize(array_keys($desired));
}

$env = array_merge(te_perm_env_file($basePath . '/.env'), $_ENV, $_SERVER);
$container = ContainerFactory::create($settings);

/** @var SqlConnection $azure */
$azure = $container->get(SqlConnection::class);

$safety = $azure->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName');
$serverName = (string) ($safety[0]['serverName'] ?? '');
$dbName = (string) ($safety[0]['dbName'] ?? '');
$userName = (string) ($safety[0]['userName'] ?? '');

echo "APP_ENV={$settings->appEnv}\n";
echo "AZURE_SERVER={$serverName}\n";
echo "AZURE_DB={$dbName}\n";
echo "AZURE_USER={$userName}\n";
echo "MODE=DRY_RUN\n";
echo "WRITES=ZERO\n";

$legacyHost = trim((string) ($env['LEGACY_TC_HOST'] ?? ''));
$legacyDb = trim((string) ($env['LEGACY_TC_DATABASE'] ?? ''));
$legacyUser = trim((string) ($env['LEGACY_TC_USER'] ?? ''));
$legacyPassword = (string) ($env['LEGACY_TC_PASSWORD'] ?? '');
$legacyPort = trim((string) ($env['LEGACY_TC_PORT'] ?? '3306'));
$legacyConfigured = $legacyHost !== '' && $legacyDb !== '' && $legacyUser !== '';

echo 'LEGACY_SOURCE=' . ($legacyConfigured ? 'enabled' : 'skipped') . "\n";
if ($legacyConfigured) {
    echo "LEGACY_HOST={$legacyHost}\n";
    echo "LEGACY_DB={$legacyDb}\n";
    echo "LEGACY_USER={$legacyUser}\n";
}

$profiles = $azure->fetchAll(
    <<<'SQL'
SELECT
    p.[UserId],
    LOWER(LTRIM(RTRIM(p.[LegacyLogin]))) AS legacy_login,
    LOWER(LTRIM(RTRIM(p.[LegacyEmail]))) AS legacy_email,
    CAST(p.[IsActive] AS INT) AS is_active,
    CAST(p.[IsFinance] AS INT) AS is_finance,
    CAST(p.[IsExpenseAdmin] AS INT) AS is_admin,
    p.[ManagerUserId] AS manager_user_id,
    LOWER(LTRIM(RTRIM(COALESCE(p.[LegacyManagerLogin], '')))) AS manager_login
FROM [expense].[ExpenseProfiles] p
ORDER BY p.[UserId]
SQL
);

$identityUsers = $azure->fetchAll(
    <<<'SQL'
SELECT
    u.[UserId],
    LOWER(LTRIM(RTRIM(u.[NormalizedEmailAddress]))) AS email_norm,
    LOWER(LTRIM(RTRIM(u.[EmailAddress]))) AS email,
    s.[StatusCode] AS status_code
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
SQL
);

$byUserId = [];
$byEmail = [];
foreach ($identityUsers as $row) {
    $userId = (int) $row['UserId'];
    $byUserId[$userId] = $row;
    $email = (string) $row['email_norm'];
    if ($email !== '') {
        $byEmail[$email][] = $row;
    }
}

$effectivePerms = $azure->fetchAll(
    <<<'SQL'
SELECT
    ur.[UserId],
    UPPER(p.[PermissionCode]) AS permission_code
FROM [identity].[UserRoles] ur
INNER JOIN [identity].[Roles] r
    ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
INNER JOIN [identity].[RolePermissions] rp
    ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
INNER JOIN [identity].[Permissions] p
    ON p.[PermissionId] = rp.[PermissionId] AND p.[IsActive] = 1
WHERE ur.[IsActive] = 1
  AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
  AND UPPER(p.[PermissionCode]) LIKE N'TRIEXPENSE.%'
SQL
);

/** @var array<int, array<string, true>> $permsByUser */
$permsByUser = [];
foreach ($effectivePerms as $row) {
    $uid = (int) $row['UserId'];
    $code = (string) $row['permission_code'];
    $permsByUser[$uid][$code] = true;
}

$teRoles = $azure->fetchAll(
    <<<'SQL'
SELECT
    ur.[UserId],
    UPPER(r.[RoleCode]) AS role_code
FROM [identity].[UserRoles] ur
INNER JOIN [identity].[Roles] r
    ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
WHERE ur.[IsActive] = 1
  AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
  AND UPPER(r.[RoleCode]) LIKE N'TRIEXPENSE_%'
SQL
);

/** @var array<int, array<string, true>> $teRolesByUser */
$teRolesByUser = [];
foreach ($teRoles as $row) {
    $uid = (int) $row['UserId'];
    $teRolesByUser[$uid][(string) $row['role_code']] = true;
}

/** @var array<int, true> $managerTargetsFromProfiles */
$managerTargetsFromProfiles = [];
foreach ($profiles as $profile) {
    if ((int) $profile['is_active'] !== 1) {
        continue;
    }
    $mgrId = $profile['manager_user_id'];
    if ($mgrId !== null && $mgrId !== '') {
        $managerTargetsFromProfiles[(int) $mgrId] = true;
    }
}

/** @var array<string, array<string, mixed>> $legacyByLogin */
$legacyByLogin = [];
/** @var array<string, array<string, mixed>> $legacyByEmail */
$legacyByEmail = [];
/** @var array<int|string, true> $managerTargetsFromLegacy */
$managerTargetsFromLegacy = [];
$legacyUserCount = 0;

if ($legacyConfigured) {
    $guard = new LegacyReadOnlySqlGuard();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $legacyHost,
        $legacyPort !== '' ? $legacyPort : '3306',
        $legacyDb,
    );
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ];
    if (defined('Pdo\\Mysql::ATTR_SSL_CA')) {
        $opt[\Pdo\Mysql::ATTR_SSL_CA] = '';
        if (defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
            $opt[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }
    $my = new PDO($dsn, $legacyUser, $legacyPassword, $opt);
    $q = static function (string $sql) use ($my, $guard): array {
        $guard->assertReadOnly($sql);

        return $my->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    };

    try {
        $guard->assertReadOnly('UPDATE tc.triexpense_users SET is_active=1');
        fwrite(STDERR, "GUARD_FAILED\n");
        exit(5);
    } catch (LegacyDatabaseWriteAttemptException) {
        echo "LEGACY_WRITE_GUARD=ok\n";
    }

    $legacyUsers = $q(
        'SELECT id, LOWER(TRIM(user_login)) login_norm, LOWER(TRIM(user_email)) email_norm, '
        . 'user_login, user_email, is_active, is_admin, is_finance, '
        . 'LOWER(TRIM(COALESCE(manager_login,\'\'))) manager_login '
        . 'FROM tc.triexpense_users ORDER BY id'
    );
    $legacyUserCount = count($legacyUsers);

    $loginToEmail = [];
    foreach ($legacyUsers as $u) {
        $loginToEmail[(string) $u['login_norm']] = (string) $u['email_norm'];
        $legacyByLogin[(string) $u['login_norm']] = $u;
        $email = (string) $u['email_norm'];
        if ($email !== '') {
            $legacyByEmail[$email] = $u;
        }
    }

    foreach ($legacyUsers as $u) {
        if ((int) $u['is_active'] !== 1) {
            continue;
        }
        $mgrLogin = (string) $u['manager_login'];
        if ($mgrLogin === '') {
            continue;
        }
        $mgrEmail = $loginToEmail[$mgrLogin]
            ?? (str_contains($mgrLogin, '@') ? $mgrLogin : $mgrLogin . '@tricab.com');
        $mgrEmail = strtolower($mgrEmail);
        $candidates = $byEmail[$mgrEmail] ?? [];
        if (count($candidates) === 1) {
            $managerTargetsFromLegacy[(int) $candidates[0]['UserId']] = true;
        }
        $managerTargetsFromLegacy['login:' . $mgrLogin] = true;
    }
}

/**
 * @var list<array{
 *   source: string,
 *   user_id: ?int,
 *   login: string,
 *   email: string,
 *   is_active: bool,
 *   is_finance: bool,
 *   is_admin: bool,
 *   match: string,
 *   profile_is_active: bool,
 *   profile_is_finance: bool,
 *   profile_is_admin: bool
 * }> $subjects
 */
$subjects = [];
$seenUserIds = [];

foreach ($profiles as $profile) {
    $userId = (int) $profile['UserId'];
    $seenUserIds[$userId] = true;
    $email = (string) $profile['legacy_email'];
    $login = (string) $profile['legacy_login'];
    $legacy = $legacyByEmail[$email] ?? $legacyByLogin[$login] ?? null;

    $subjects[] = [
        'source' => 'profile',
        'user_id' => $userId,
        'login' => $login,
        'email' => $email,
        'is_active' => ((int) $profile['is_active'] === 1)
            || ($legacy !== null && (int) $legacy['is_active'] === 1),
        'is_finance' => ((int) $profile['is_finance'] === 1)
            || ($legacy !== null && (int) $legacy['is_finance'] === 1),
        'is_admin' => ((int) $profile['is_admin'] === 1)
            || ($legacy !== null && (int) $legacy['is_admin'] === 1),
        'match' => isset($byUserId[$userId]) ? 'matched' : 'identity_missing',
        'profile_is_active' => (int) $profile['is_active'] === 1,
        'profile_is_finance' => (int) $profile['is_finance'] === 1,
        'profile_is_admin' => (int) $profile['is_admin'] === 1,
    ];
}

if ($legacyConfigured) {
    foreach ($legacyByLogin as $login => $legacy) {
        $email = (string) $legacy['email_norm'];
        $candidates = $email !== '' ? ($byEmail[$email] ?? []) : [];
        if (count($candidates) === 1) {
            $userId = (int) $candidates[0]['UserId'];
            if (isset($seenUserIds[$userId])) {
                continue;
            }
            $seenUserIds[$userId] = true;
            $subjects[] = [
                'source' => 'legacy',
                'user_id' => $userId,
                'login' => $login,
                'email' => $email,
                'is_active' => (int) $legacy['is_active'] === 1,
                'is_finance' => (int) $legacy['is_finance'] === 1,
                'is_admin' => (int) $legacy['is_admin'] === 1,
                'match' => 'matched',
                'profile_is_active' => false,
                'profile_is_finance' => false,
                'profile_is_admin' => false,
            ];
            continue;
        }

        $subjects[] = [
            'source' => 'legacy',
            'user_id' => null,
            'login' => $login,
            'email' => $email,
            'is_active' => (int) $legacy['is_active'] === 1,
            'is_finance' => (int) $legacy['is_finance'] === 1,
            'is_admin' => (int) $legacy['is_admin'] === 1,
            'match' => count($candidates) > 1 ? 'ambiguous' : 'unmatched',
            'profile_is_active' => false,
            'profile_is_finance' => false,
            'profile_is_admin' => false,
        ];
    }
}

$counts = [
    'TOTAL_USERS' => 0,
    'ACCESS_TO_GRANT' => 0,
    'APPROVE_TO_GRANT' => 0,
    'FINANCE_TO_GRANT' => 0,
    'ADMIN_TO_GRANT' => 0,
    'AUDIT_TO_GRANT' => 0,
    'ALREADY_CORRECT' => 0,
    'IDENTITY_UNMATCHED' => 0,
    'IDENTITY_AMBIGUOUS' => 0,
    'ROLE_CONFLICTS' => 0,
    'PERMISSION_CONFLICTS' => 0,
    'MISMATCH_COUNT' => 0,
    'INTENTIONAL_MISMATCH_COUNT' => 0,
    'UNEXPLAINED_MISMATCH_COUNT' => 0,
];

$mismatches = [];
$intentionalMismatches = [];
$unexplainedMismatches = [];
$grantPreview = [];
$roleConflicts = [];
$permissionConflicts = [];

foreach ($subjects as $subject) {
    $counts['TOTAL_USERS']++;

    if ($subject['match'] === 'unmatched' || $subject['match'] === 'identity_missing') {
        $counts['IDENTITY_UNMATCHED']++;
        continue;
    }
    if ($subject['match'] === 'ambiguous') {
        $counts['IDENTITY_AMBIGUOUS']++;
        continue;
    }

    $userId = (int) $subject['user_id'];
    $isApprover = isset($managerTargetsFromProfiles[$userId])
        || isset($managerTargetsFromLegacy[$userId])
        || isset($managerTargetsFromLegacy['login:' . $subject['login']]);

    $desiredList = te_perm_desired([
        'is_active' => $subject['is_active'],
        'is_finance' => $subject['is_finance'],
        'is_admin' => $subject['is_admin'],
    ], $isApprover);

    $currentMap = $permsByUser[$userId] ?? [];
    $currentList = te_perm_normalize(array_keys($currentMap));
    $missing = array_values(array_diff($desiredList, $currentList));
    $extraCapability = array_values(array_intersect(
        array_diff($currentList, $desiredList),
        [
            Module::PERMISSION_APPROVE,
            Module::PERMISSION_FINANCE,
            Module::PERMISSION_ADMIN,
            Module::PERMISSION_AUDIT,
        ],
    ));

    foreach ($missing as $code) {
        match ($code) {
            Module::PERMISSION_ACCESS => $counts['ACCESS_TO_GRANT']++,
            Module::PERMISSION_APPROVE => $counts['APPROVE_TO_GRANT']++,
            Module::PERMISSION_FINANCE => $counts['FINANCE_TO_GRANT']++,
            Module::PERMISSION_ADMIN => $counts['ADMIN_TO_GRANT']++,
            Module::PERMISSION_AUDIT => $counts['AUDIT_TO_GRANT']++,
            default => null,
        };
    }

    if ($missing === []) {
        $counts['ALREADY_CORRECT']++;
    } else {
        $grantPreview[] = [
            'user_id' => $userId,
            'email_masked' => te_perm_mask_email($subject['email']),
            'login_masked' => te_perm_mask_login($subject['login']),
            'to_grant' => $missing,
            'desired' => $desiredList,
            'current' => $currentList,
        ];
    }

    $heldRoles = array_keys($teRolesByUser[$userId] ?? []);
    sort($heldRoles);
    if (
        in_array(Module::ROLE_FINANCE, $heldRoles, true)
        && in_array(Module::ROLE_ADMINISTRATOR, $heldRoles, true)
    ) {
        $counts['ROLE_CONFLICTS']++;
        $roleConflicts[] = [
            'user_id' => $userId,
            'email_masked' => te_perm_mask_email($subject['email']),
            'login_masked' => te_perm_mask_login($subject['login']),
            'roles' => $heldRoles,
            'reason' => 'TRIEXPENSE_FINANCE_and_TRIEXPENSE_ADMINISTRATOR',
        ];
    }

    if ($extraCapability !== []) {
        $counts['PERMISSION_CONFLICTS']++;
        $permissionConflicts[] = [
            'user_id' => $userId,
            'email_masked' => te_perm_mask_email($subject['email']),
            'login_masked' => te_perm_mask_login($subject['login']),
            'extra' => $extraCapability,
            'desired' => $desiredList,
            'current' => $currentList,
        ];
    }

    // Hybrid comparison: profile legacy flags vs current TRIEXPENSE.* permissions
    if ($subject['source'] === 'profile') {
        $legacyActive = (bool) $subject['profile_is_active'];
        $legacyFinance = (bool) $subject['profile_is_finance'];
        $legacyAdmin = (bool) $subject['profile_is_admin'];
        $legacyApprove = $isApprover;
        $legacyAudit = $legacyFinance || $legacyAdmin;

        $permAccess = isset($currentMap[Module::PERMISSION_ACCESS]);
        $permFinance = isset($currentMap[Module::PERMISSION_FINANCE]);
        $permAdmin = isset($currentMap[Module::PERMISSION_ADMIN]);
        $permApprove = isset($currentMap[Module::PERMISSION_APPROVE]);
        $permAudit = isset($currentMap[Module::PERMISSION_AUDIT]) || $permFinance;

        $pairs = [
            'access' => [$legacyActive, $permAccess],
            'finance' => [$legacyFinance, $permFinance],
            'admin' => [$legacyAdmin, $permAdmin],
            'approve' => [$legacyApprove, $permApprove],
            'audit' => [$legacyAudit, $permAudit],
        ];
        $holdsFinanceRole = in_array(Module::ROLE_FINANCE, $heldRoles, true);
        foreach ($pairs as $capability => [$legacyFlag, $permissionFlag]) {
            if ($legacyFlag !== $permissionFlag) {
                $counts['MISMATCH_COUNT']++;
                $row = [
                    'user_id' => $userId,
                    'email_masked' => te_perm_mask_email($subject['email']),
                    'login_masked' => te_perm_mask_login($subject['login']),
                    'capability' => $capability,
                    'legacy' => $legacyFlag,
                    'permission' => $permissionFlag,
                ];
                // TRIEXPENSE_FINANCE role composition includes APPROVE (+ AUDIT).
                // Legacy Finance users were not necessarily managers — treat as intentional.
                $intentionalFinanceComposition = $holdsFinanceRole
                    && $capability === 'approve'
                    && $legacyFlag === false
                    && $permissionFlag === true;
                if ($intentionalFinanceComposition) {
                    $row['class'] = 'INTENTIONAL_ACCESS_CHANGE';
                    $row['reason'] = 'TRIEXPENSE_FINANCE_INCLUDES_APPROVE';
                    $counts['INTENTIONAL_MISMATCH_COUNT']++;
                    $intentionalMismatches[] = $row;
                } else {
                    $row['class'] = 'UNEXPLAINED';
                    $counts['UNEXPLAINED_MISMATCH_COUNT']++;
                    $unexplainedMismatches[] = $row;
                }
                $mismatches[] = $row;
            }
        }
    }
}

echo 'SUMMARY=' . json_encode($counts, JSON_UNESCAPED_SLASHES) . "\n";
echo 'PROFILE_COUNT=' . count($profiles) . "\n";
echo 'LEGACY_USER_COUNT=' . $legacyUserCount . "\n";
echo 'SUBJECT_COUNT=' . count($subjects) . "\n";
echo 'MISMATCHES=' . json_encode($mismatches, JSON_UNESCAPED_SLASHES) . "\n";
echo 'INTENTIONAL_MISMATCHES=' . json_encode($intentionalMismatches, JSON_UNESCAPED_SLASHES) . "\n";
echo 'UNEXPLAINED_MISMATCHES=' . json_encode($unexplainedMismatches, JSON_UNESCAPED_SLASHES) . "\n";
echo 'GRANTS_PREVIEW=' . json_encode(array_slice($grantPreview, 0, 200), JSON_UNESCAPED_SLASHES) . "\n";
echo 'ROLE_CONFLICT_DETAIL=' . json_encode($roleConflicts, JSON_UNESCAPED_SLASHES) . "\n";
echo 'PERMISSION_CONFLICT_DETAIL=' . json_encode($permissionConflicts, JSON_UNESCAPED_SLASHES) . "\n";
echo "DONE\n";
exit(0);
