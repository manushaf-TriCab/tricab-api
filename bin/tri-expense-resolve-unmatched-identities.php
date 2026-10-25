<?php

declare(strict_types=1);

/**
 * Non-production TriExpense unmatched-identity resolver.
 *
 * Verifies each unmatched legacy TriExpense email against Microsoft Graph (az CLI
 * token or configured Graph app), then optionally creates identity.Users + Entra
 * ExternalIdentity links via CreateUserService / StaffEntraLinkWriteUnit.
 *
 * Usage:
 *   php bin/tri-expense-resolve-unmatched-identities.php --dry-run
 *   php bin/tri-expense-resolve-unmatched-identities.php --apply
 *
 * Rules:
 *   - Exact email/UPN match only (no display-name matching)
 *   - Entra accountEnabled=true → active user + TriExpense roles from legacy flags
 *   - Entra accountEnabled=false → disabled identity, NO TRIEXPENSE.* roles
 *   - Never invent identities without Entra evidence
 *   - Never write legacy MySQL
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);
$dryRun = !$apply || in_array('--dry-run', $argv, true);
if ($apply) {
    $dryRun = false;
}

$settings = Environment::settings($basePath);
if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

function te_env_file(string $path): array
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

function te_mask(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (str_contains($value, '@')) {
        [$local, $domain] = explode('@', $value, 2);

        return substr($local, 0, 3) . '***@' . $domain;
    }

    return substr($value, 0, 3) . '***';
}

/**
 * @return list<array{id:string,displayName:string,mail:string,userPrincipalName:string,accountEnabled:bool}>
 */
function te_graph_lookup(string $email): array
{
    $tokenCmd = 'az account get-access-token --resource https://graph.microsoft.com --query accessToken -o tsv 2>/dev/null';
    $token = trim((string) shell_exec($tokenCmd));
    if ($token === '') {
        throw new RuntimeException('AZ_GRAPH_TOKEN_UNAVAILABLE');
    }
    $filter = rawurlencode(sprintf("mail eq '%s' or userPrincipalName eq '%s'", $email, $email));
    $url = 'https://graph.microsoft.com/v1.0/users?$filter=' . $filter
        . '&$select=id,displayName,mail,userPrincipalName,accountEnabled';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $status < 200 || $status > 299) {
        throw new RuntimeException('GRAPH_LOOKUP_FAILED status=' . $status);
    }
    /** @var array{value?: list<array<string,mixed>>} $decoded */
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $out = [];
    foreach ($decoded['value'] ?? [] as $row) {
        $out[] = [
            'id' => (string) ($row['id'] ?? ''),
            'displayName' => trim((string) ($row['displayName'] ?? '')),
            'mail' => strtolower(trim((string) ($row['mail'] ?? ''))),
            'userPrincipalName' => strtolower(trim((string) ($row['userPrincipalName'] ?? ''))),
            'accountEnabled' => ($row['accountEnabled'] ?? false) === true,
        ];
    }

    return $out;
}

$env = te_env_file($basePath . '/.env');
$container = ContainerFactory::create($settings);
/** @var SqlConnection $azure */
$azure = $container->get(SqlConnection::class);
$safety = $azure->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName');
echo "APP_ENV={$settings->appEnv}\n";
echo "AZURE_SERVER={$safety[0]['serverName']}\n";
echo "AZURE_DB={$safety[0]['dbName']}\n";
echo "AZURE_USER={$safety[0]['userName']}\n";
echo 'MODE=' . ($dryRun ? 'DRY_RUN' : 'APPLY') . "\n";
if (!str_contains(strtolower((string) $safety[0]['serverName']), 'tricab-mel')
    || strtolower((string) $safety[0]['dbName']) !== 'tc') {
    fwrite(STDERR, "STOP_BAD_TARGET\n");
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

$v2 = $azure->fetchAll(
    'SELECT u.UserId, LOWER(LTRIM(RTRIM(u.NormalizedEmailAddress))) AS email_norm '
    . 'FROM [identity].[Users] u',
);
$byEmail = [];
foreach ($v2 as $row) {
    $e = (string) $row['email_norm'];
    if ($e !== '') {
        $byEmail[$e][] = $row;
    }
}

$teUsers = $q(
    'SELECT id, user_login, user_email, is_active, is_admin, is_finance, manager_login '
    . 'FROM tc.triexpense_users WHERE is_active = 1 ORDER BY id',
);

$unmatched = [];
foreach ($teUsers as $u) {
    $email = strtolower(trim((string) $u['user_email']));
    $candidates = $byEmail[$email] ?? [];
    if ($candidates === []) {
        $unmatched[] = $u;
    }
}

$employeeSet = [];
try {
    foreach ($q('SELECT LOWER(TRIM(user_login)) AS login FROM employee') as $row) {
        $employeeSet[(string) $row['login']] = true;
    }
    echo "EMPLOYEE_TABLE=ok\n";
} catch (Throwable $e) {
    echo 'EMPLOYEE_TABLE=unavailable ' . substr($e->getMessage(), 0, 80) . "\n";
}

$tenant = (string) ($env['ENTRA_TENANT_ID'] ?? $env['AZURE_AD_TENANT_ID'] ?? $env['MICROSOFT_ENTRA_TENANT_ID'] ?? '');
foreach (['entraTenantId', 'microsoftEntraTenantId', 'azureAdTenantId'] as $prop) {
    if ($tenant === '' && property_exists($settings, $prop) && is_string($settings->{$prop}) && $settings->{$prop} !== '') {
        $tenant = $settings->{$prop};
    }
}

$actorRow = $azure->fetchAll(
    "SELECT TOP 1 u.UserId, u.PublicId, u.EmailAddress FROM [identity].[Users] u "
    . "WHERE LOWER(u.NormalizedEmailAddress) = 'thilanh@tricab.com'",
)[0] ?? null;
if ($actorRow === null) {
    fwrite(STDERR, "ACTOR_NOT_FOUND\n");
    exit(6);
}
$actor = new ActorContext(
    (int) $actorRow['UserId'],
    (string) $actorRow['PublicId'],
    (string) $actorRow['EmailAddress'],
    'te-resolve-unmatched-identities',
);

/** @var CreateUserService $create */
$create = $container->get(CreateUserService::class);
/** @var AssignRoleToUserService $assign */
$assign = $container->get(AssignRoleToUserService::class);
/** @var StaffEntraLinkWriteUnit $linkUnit */
$linkUnit = $container->get(StaffEntraLinkWriteUnit::class);

echo 'UNMATCHED_COUNT=' . count($unmatched) . "\n";

foreach ($unmatched as $u) {
    $login = strtolower(trim((string) $u['user_login']));
    $email = strtolower(trim((string) $u['user_email']));
    $owned = (int) ($q(
        'SELECT COUNT(*) AS c FROM tc.triexpense_receipts WHERE LOWER(TRIM(created_by)) IN ('
        . var_export($login, true) . ',' . var_export($email, true) . ')',
    )[0]['c'] ?? 0);
    $submitted = (int) ($q(
        'SELECT COUNT(*) AS c FROM tc.triexpense_receipts WHERE LOWER(TRIM(submitted_by)) IN ('
        . var_export($login, true) . ',' . var_export($email, true) . ')'
        . " AND LOWER(TRIM(status)) NOT IN ('draft','deleted')",
    )[0]['c'] ?? 0);
    $mgrRefs = (int) ($q(
        'SELECT COUNT(*) AS c FROM tc.triexpense_users WHERE LOWER(TRIM(manager_login)) = '
        . var_export($login, true),
    )[0]['c'] ?? 0);

    $entra = [];
    $entraError = null;
    try {
        $entra = te_graph_lookup($email);
    } catch (Throwable $e) {
        $entraError = $e->getMessage();
    }

    $hit = count($entra) === 1 ? $entra[0] : null;
    $action = 'OPERATOR_REVIEW_REQUIRED';
    $roles = [];
    $status = UserStatus::PENDING;
    if ($hit === null && $entraError !== null) {
        $action = 'OPERATOR_REVIEW_REQUIRED';
    } elseif ($hit === null) {
        $action = $owned > 0 ? 'HISTORICAL_ONLY' : 'OPERATOR_REVIEW_REQUIRED';
    } elseif (!$hit['accountEnabled']) {
        $action = 'DISABLE_ACCESS';
        $status = UserStatus::DISABLED;
        $roles = [];
    } else {
        $action = 'CREATE_FROM_VERIFIED_ENTRA';
        $status = UserStatus::ACTIVE;
        $roles = ['TRIEXPENSE_USER'];
        if ((int) $u['is_finance'] === 1) {
            $roles[] = 'TRIEXPENSE_FINANCE';
        }
        if ((int) $u['is_admin'] === 1) {
            $roles[] = 'TRIEXPENSE_ADMINISTRATOR';
        }
    }

    $report = [
        'legacy_id' => (int) $u['id'],
        'login_masked' => te_mask($login),
        'email_masked' => te_mask($email),
        'is_active' => (int) $u['is_active'],
        'is_finance' => (int) $u['is_finance'],
        'is_admin' => (int) $u['is_admin'],
        'owned_receipts' => $owned,
        'submitted_receipts' => $submitted,
        'manager_refs' => $mgrRefs,
        'employee_candidate' => isset($employeeSet[$login]) ? 'YES' : 'NO',
        'entra_exists' => $hit !== null ? 'YES' : 'NO',
        'entra_enabled' => $hit === null ? 'N/A' : ($hit['accountEnabled'] ? 'YES' : 'NO'),
        'entra_error' => $entraError,
        'identity_users' => 'NO',
        'recommended_action' => $action,
        'target_status' => $status,
        'target_roles' => $roles,
    ];
    echo 'PLAN=' . json_encode($report) . "\n";

    if ($dryRun) {
        continue;
    }
    if (!in_array($action, ['CREATE_FROM_VERIFIED_ENTRA', 'DISABLE_ACCESS'], true) || $hit === null) {
        echo "SKIP_APPLY action={$action}\n";
        continue;
    }
    if ($tenant === '') {
        fwrite(STDERR, "TENANT_REQUIRED_FOR_APPLY\n");
        exit(7);
    }

    $details = $create->execute(
        $actor,
        $email,
        $hit['displayName'] !== '' ? $hit['displayName'] : $email,
        $status,
        $roles,
    );
    $userId = (int) ($azure->fetchAll(
        'SELECT TOP 1 UserId FROM [identity].[Users] WHERE PublicId = :p',
        [':p' => $details->publicId],
    )[0]['UserId'] ?? 0);

    $linkResult = $linkUnit->linkEntraIdentity([
        'userId' => $userId,
        'tenantIdentifier' => $tenant,
        'subjectIdentifier' => $hit['id'],
        'emailClaim' => $email,
        'normalizedEmailClaim' => $email,
        'activateIfPending' => false,
    ]);

    echo 'APPLIED=' . json_encode([
        'email_masked' => te_mask($email),
        'userId' => $userId,
        'publicId' => $details->publicId,
        'status' => $status,
        'roles' => $roles,
        'link' => $linkResult,
    ]) . "\n";
}

echo "COMPLETE\n";
