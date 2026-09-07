<?php

declare(strict_types=1);

/**
 * NON-PRODUCTION: create + backfill product.ProductImageRoleAssignments on tricab-mel / tc.
 *
 * Usage:
 *   php bin/ensure-product-image-role-assignments.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$env = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName], SUSER_SNAME() AS [userName]');
$server = (string) ($env[0]['serverName'] ?? '');
$database = (string) ($env[0]['dbName'] ?? '');
$user = (string) ($env[0]['userName'] ?? '');

echo "APP_ENV={$settings->appEnv}\n";
echo "SERVER={$server}\n";
echo "DB={$database}\n";
echo "USER={$user}\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc, got {$server} / {$database}\n");
    exit(4);
}

$script = $basePath . '/database/scripts/development/create-product-image-role-assignments.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) {
    fwrite(STDERR, "ROLE_ASSIGNMENTS_SCHEMA_FAILED\n");
    exit($code);
}

$check = $db->fetchAll("
SELECT
  CASE WHEN OBJECT_ID(N'[product].[ProductImageRoleAssignments]', N'U') IS NULL THEN 0 ELSE 1 END AS [tableOk]
");
$ok = (int) ($check[0]['tableOk'] ?? 0) === 1;
if (!$ok) {
    fwrite(STDERR, "ROLE_ASSIGNMENTS_SCHEMA_FAILED\n");
    exit(1);
}

$counts = $db->fetchAll("
SELECT a.[RoleCode], COUNT(*) AS [AssignmentCount]
FROM [product].[ProductImageRoleAssignments] a
GROUP BY a.[RoleCode]
ORDER BY a.[RoleCode]
");
echo "BACKFILL_COUNTS:\n";
foreach ($counts as $row) {
    echo '  ' . ($row['RoleCode'] ?? '') . '=' . ($row['AssignmentCount'] ?? 0) . "\n";
}
echo "ROLE_ASSIGNMENTS_SCHEMA_APPLIED\n";
exit(0);
