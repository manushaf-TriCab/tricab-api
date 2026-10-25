<?php

declare(strict_types=1);

/**
 * NON-PRODUCTION ONLY — link legacy TriExpense approvers → V2 ExpenseProfiles.
 *
 * Source: tc.triexpense_users.manager_login (NOT tc.employee.manager, NOT Graph).
 * Target: expense.ExpenseProfiles.ManagerUserId (+ LegacyManagerLogin).
 *
 * Default: --dry-run
 * Apply:   --apply (only WOULD_LINK rows; refuses when CONFLICT/AMBIGUOUS/MANAGER_NOT_FOUND
 *          among target profiles that would be written — see safety gate).
 *
 * ZERO legacy MySQL writes. Blocks production APP_ENV.
 *
 * Usage:
 *   php bin/tri-expense-link-approvers.php
 *   php bin/tri-expense-link-approvers.php --dry-run
 *   php bin/tri-expense-link-approvers.php --apply
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproverResult;
use TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproversService;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
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

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply;

/**
 * @return array<string, string>
 */
function te_link_env_file(string $path): array
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

$env = te_link_env_file($basePath . '/.env');
$container = ContainerFactory::create($settings);
/** @var SqlConnection $azure */
$azure = $container->get(SqlConnection::class);
/** @var ExpenseProfileRepository $profiles */
$profiles = $container->get(ExpenseProfileRepository::class);

$safety = $azure->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName');
$server = (string) $safety[0]['serverName'];
$dbName = (string) $safety[0]['dbName'];
echo "APP_ENV={$settings->appEnv}\n";
echo "AZURE_SERVER={$server}\n";
echo "AZURE_DB={$dbName}\n";
echo 'MODE=' . ($dryRun ? 'DRY_RUN' : 'APPLY') . "\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($dbName) !== 'tc') {
    fwrite(STDERR, "STOP: unexpected Azure target\n");
    exit(4);
}

$guard = new LegacyReadOnlySqlGuard();
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['LEGACY_TC_HOST'],
    $env['LEGACY_TC_PORT'] ?? '3306',
    $env['LEGACY_TC_DATABASE'],
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
$my = new PDO($dsn, $env['LEGACY_TC_USER'], $env['LEGACY_TC_PASSWORD'], $opt);
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

$teUsers = $q(
    'SELECT LOWER(TRIM(user_login)) login_norm, LOWER(TRIM(user_email)) email_norm, '
    . 'is_active, LOWER(TRIM(COALESCE(manager_login,\'\'))) manager_login '
    . 'FROM tc.triexpense_users ORDER BY id',
);

$v2 = $azure->fetchAll(
    'SELECT u.UserId AS userId, LOWER(LTRIM(RTRIM(u.NormalizedEmailAddress))) AS email_norm '
    . 'FROM [identity].[Users] u',
);
$byEmail = [];
foreach ($v2 as $row) {
    $e = (string) $row['email_norm'];
    if ($e !== '') {
        $byEmail[$e][] = ['userId' => (int) $row['userId'], 'email_norm' => $e];
    }
}

$loginToEmail = [];
foreach ($teUsers as $u) {
    $loginToEmail[(string) $u['login_norm']] = (string) $u['email_norm'];
}

$linker = new LinkLegacyExpenseApproversService($profiles);
$results = $linker->classify($teUsers, $byEmail, $loginToEmail);
$counts = LinkLegacyExpenseApproversService::countByClassification($results);

echo "\n=== CLASSIFICATION COUNTS ===\n";
echo json_encode($counts, JSON_PRETTY_PRINT) . "\n";

$wouldLink = array_values(array_filter(
    $results,
    static fn (LinkLegacyExpenseApproverResult $r): bool => $r->classification === LinkLegacyExpenseApproverResult::WOULD_LINK,
));

// Safety gate for apply: among rows that touch existing V2 profiles, no blockers.
$blocking = $counts[LinkLegacyExpenseApproverResult::CONFLICT]
    + $counts[LinkLegacyExpenseApproverResult::AMBIGUOUS]
    + $counts[LinkLegacyExpenseApproverResult::MANAGER_NOT_FOUND];

echo "\nWOULD_LINK_ROWS=" . count($wouldLink) . "\n";
echo "BLOCKING_FOR_APPLY={$blocking} (conflict+ambiguous+manager_not_found)\n";

if ($dryRun) {
    echo "\nDRY_RUN complete. Re-run with --apply to link WOULD_LINK profiles only.\n";
    exit(0);
}

if ($blocking > 0) {
    fwrite(STDERR, "APPLY_ABORTED: blocking classifications > 0\n");
    exit(6);
}

$linked = 0;
foreach ($wouldLink as $result) {
    $linker->apply($result);
    ++$linked;
}

echo "\nAPPLIED={$linked}\n";
echo "DONE\n";
