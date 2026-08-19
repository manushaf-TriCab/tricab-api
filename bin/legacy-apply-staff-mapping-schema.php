<?php

declare(strict_types=1);

/**
 * Applies migration.LegacyStaffUserMappings to NEW Azure SQL.
 *
 * Usage:
 *   LEGACY_MIGRATION_STORE=azure-sql php bin/legacy-apply-staff-mapping-schema.php
 */

require __DIR__ . '/../vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$settings = Environment::settings(dirname(__DIR__));
$container = ContainerFactory::create($settings);
/** @var SqlConnection $connection */
$connection = $container->get(SqlConnection::class);

$sql = file_get_contents(dirname(__DIR__) . '/database/scripts/development/create-legacy-staff-user-mappings.sql');
if ($sql === false) {
    fwrite(STDERR, "Schema script not found.\n");
    exit(1);
}

$statements = preg_split('/\s*GO\s*/i', $sql) ?: [];
foreach ($statements as $statement) {
    $trimmed = trim($statement);
    if ($trimmed === '') {
        continue;
    }
    $connection->execute($trimmed);
}

echo "LegacyStaffUserMappings schema applied.\n";
