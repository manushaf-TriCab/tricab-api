<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: allow product-thumbnail ImageRoleCode on ProductImages.
 *
 * Usage (from tricab-api):
 *   php bin/apply-product-image-role-product-thumbnail.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

printf("APP_ENV=%s\n", $settings->appEnv);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);

if ($settings->isProduction()) {
    fwrite(STDERR, "Refusing to run on production.\n");
    exit(2);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql — nothing to apply.\n");
    exit(1);
}

$sqlPath = $basePath . '/database/scripts/development/alter-product-image-role-product-thumbnail.sql';
if (!is_readable($sqlPath)) {
    fwrite(STDERR, "Missing migration script: {$sqlPath}\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$batches = array_values(array_filter(array_map('trim', preg_split('/\bGO\b/i', file_get_contents($sqlPath) ?: ''))));
foreach ($batches as $index => $batch) {
    if ($batch === '') {
        continue;
    }
    printf("Applying batch %d...\n", $index + 1);
    $db->execute($batch);
}

$rows = $db->fetchAll(
    <<<'SQL'
SELECT cc.[name], cc.[definition]
FROM sys.check_constraints cc
WHERE cc.[parent_object_id] = OBJECT_ID(N'[product].[ProductImages]')
  AND cc.[name] = N'CK_product_ProductImages_Role'
SQL,
);

if ($rows === []) {
    fwrite(STDERR, "Constraint CK_product_ProductImages_Role not found after apply.\n");
    exit(1);
}

$definition = (string) ($rows[0]['definition'] ?? '');
if (!str_contains(strtolower($definition), 'product-thumbnail')) {
    fwrite(STDERR, "Constraint updated but product-thumbnail not present: {$definition}\n");
    exit(1);
}

echo "OK — CK_product_ProductImages_Role now allows product-thumbnail.\n";
