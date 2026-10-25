<?php

declare(strict_types=1);

/**
 * NON-PRODUCTION ONLY — apply TriExpense role assignments from legacy mapping.
 *
 * Idempotent + additive. Skips IDENTITY_UNMATCHED. Does not remove roles.
 * Blocks production. ZERO legacy MySQL writes.
 *
 * Usage:
 *   php bin/tri-expense-apply-role-assignments.php --dry-run
 *   php bin/tri-expense-apply-role-assignments.php --apply
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply || in_array('--dry-run', $argv, true);
if ($apply) {
    $dryRun = false;
}

/**
 * @return array<string, string>
 */
function te_assign_env_file(string $path): array
{
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

$env = te_assign_env_file($basePath . '/.env');
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$safety = $db->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName');
$server = (string) $safety[0]['serverName'];
$dbName = (string) $safety[0]['dbName'];
echo "APP_ENV={$settings->appEnv}\n";
echo "AZURE_SERVER={$server}\n";
echo "AZURE_DB={$dbName}\n";
echo 'MODE=' . ($dryRun ? 'DRY_RUN' : 'APPLY') . "\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($dbName) !== 'tc') {
    fwrite(STDERR, "STOP: unexpected target\n");
    exit(4);
}

$guard = new LegacyReadOnlySqlGuard();
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['LEGACY_TC_HOST'],
    $env['LEGACY_TC_PORT'] ?? '3306',
    $env['LEGACY_TC_DATABASE'],
);
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (defined('Pdo\\Mysql::ATTR_SSL_CA')) {
    $opt[\Pdo\Mysql::ATTR_SSL_CA] = '';
    if (defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
        $opt[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
}
$my = new PDO($dsn, $env['LEGACY_TC_USER'], $env['LEGACY_TC_PASSWORD'], $opt);
$q = static function (string $sql) use ($my, $guard): array {
    $guard->assertReadOnly($sql);

    return $my->query($sql)->fetchAll(PDO::FETCH_ASSOC);
};

$teUsers = $q('SELECT id, LOWER(TRIM(user_login)) login_norm, LOWER(TRIM(user_email)) email_norm, is_active, is_admin, is_finance, LOWER(TRIM(COALESCE(manager_login,\'\'))) manager_login FROM tc.triexpense_users ORDER BY id');
$v2 = $db->fetchAll('SELECT u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS publicId, LOWER(LTRIM(RTRIM(u.NormalizedEmailAddress))) AS email_norm, s.StatusCode FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.UserStatusId = u.UserStatusId');
$byEmail = [];
foreach ($v2 as $row) {
    $e = (string) $row['email_norm'];
    if ($e !== '') {
        $byEmail[$e][] = $row;
    }
}

$profiles = $db->fetchAll('SELECT UserId, IsActive, IsFinance, IsExpenseAdmin, ManagerUserId FROM [expense].[ExpenseProfiles]');
$profileByUser = [];
foreach ($profiles as $p) {
    $profileByUser[(int) $p['UserId']] = $p;
}

$userRoles = $db->fetchAll(
    'SELECT ur.UserId, r.RoleCode FROM [identity].[UserRoles] ur INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId WHERE ur.IsActive = 1 AND r.IsActive = 1',
);
$rolesByUser = [];
foreach ($userRoles as $ur) {
    $rolesByUser[(int) $ur['UserId']][] = (string) $ur['RoleCode'];
}

$managerIds = [];
foreach ($profiles as $p) {
    if ($p['ManagerUserId'] !== null && (int) $p['IsActive'] === 1) {
        $managerIds[(int) $p['ManagerUserId']] = true;
    }
}
$legacyMgrLogins = [];
foreach ($teUsers as $u) {
    if ((int) $u['is_active'] === 1 && $u['manager_login'] !== '') {
        $legacyMgrLogins[$u['manager_login']] = true;
    }
}

$plans = [];
$skippedUnmatched = 0;
foreach ($teUsers as $u) {
    $candidates = $byEmail[$u['email_norm']] ?? [];
    if (count($candidates) !== 1) {
        if (count($candidates) === 0) {
            ++$skippedUnmatched;
        }
        continue;
    }
    $userId = (int) $candidates[0]['UserId'];
    $publicId = (string) $candidates[0]['publicId'];
    $prof = $profileByUser[$userId] ?? null;

    $legacyActive = (int) $u['is_active'] === 1;
    $wantAccess = $legacyActive || ($prof !== null && (int) $prof['IsActive'] === 1);
    if (!$wantAccess) {
        continue;
    }

    $legacyFinance = (int) $u['is_finance'] === 1 || ($prof !== null && (int) $prof['IsFinance'] === 1);
    $legacyAdmin = (int) $u['is_admin'] === 1 || ($prof !== null && (int) $prof['IsExpenseAdmin'] === 1);
    $isManager = isset($managerIds[$userId]) || isset($legacyMgrLogins[$u['login_norm']]);

    $target = [Module::ROLE_USER];
    if ($isManager) {
        $target[] = Module::ROLE_APPROVER;
    }
    if ($legacyFinance) {
        $target[] = Module::ROLE_FINANCE;
    }
    if ($legacyAdmin) {
        $target[] = Module::ROLE_ADMINISTRATOR;
    }
    $target = array_values(array_unique($target));

    $current = $rolesByUser[$userId] ?? [];
    $teCurrent = array_values(array_filter($current, static fn (string $r): bool => str_starts_with($r, 'TRIEXPENSE_')));
    $toAdd = array_values(array_diff($target, $teCurrent));
    if ($toAdd === []) {
        continue;
    }

    $plans[] = [
        'userId' => $userId,
        'publicId' => $publicId,
        'toAdd' => $toAdd,
    ];
}

echo 'PLAN_ASSIGNMENTS=' . count($plans) . "\n";
echo 'SKIPPED_UNMATCHED=' . $skippedUnmatched . "\n";

if ($dryRun) {
    echo "DRY_RUN_COMPLETE — pass --apply to write\n";
    exit(0);
}

/** @var AssignRoleToUserService $assign */
$assign = $container->get(AssignRoleToUserService::class);

// Operator actor = first ADMINISTRATOR with a public id (Thilan in this env).
$actorRow = $db->fetchAll(
    "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS publicId, u.EmailAddress
     FROM [identity].[Users] u
     INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
     INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId AND UPPER(r.RoleCode) = N'ADMINISTRATOR' AND r.IsActive = 1
     ORDER BY u.UserId",
);
if ($actorRow === []) {
    fwrite(STDERR, "NO_ADMIN_ACTOR\n");
    exit(5);
}
$actor = new ActorContext(
    userId: (int) $actorRow[0]['UserId'],
    publicId: (string) $actorRow[0]['publicId'],
    emailAddress: (string) $actorRow[0]['EmailAddress'],
);

$assigned = 0;
$errors = 0;
foreach ($plans as $plan) {
    foreach ($plan['toAdd'] as $roleCode) {
        try {
            $assign->execute($actor, $plan['publicId'], $roleCode, null);
            ++$assigned;
            echo "ASSIGNED userId={$plan['userId']} role={$roleCode}\n";
        } catch (Throwable $e) {
            ++$errors;
            echo "ERROR userId={$plan['userId']} role={$roleCode} msg=" . $e->getMessage() . "\n";
        }
    }
}

echo "ASSIGNED_COUNT={$assigned}\n";
echo "ERROR_COUNT={$errors}\n";
echo "DONE\n";
exit($errors > 0 ? 2 : 0);
