<?php

declare(strict_types=1);

/**
 * Applies the Log Notes schema to the configured NON-PRODUCTION Azure SQL database.
 *
 * Aborts unless APP_ENV is non-production and the live connection proves
 * tricab-mel / tc. Never writes to legacy MySQL, 4JS, or production.
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
echo "IS_PRODUCTION=NO\n";
echo "TARGET_SCHEMA=customer\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc non-production target\n");
    exit(4);
}

$script = $basePath . '/database/scripts/development/create-log-notes-schema.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) {
    fwrite(STDERR, "LOG_NOTES_SCHEMA_FAILED\n");
    exit($code);
}

$tables = $db->fetchAll(
    "SELECT t.name AS table_name
     FROM sys.tables t
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE s.name = N'customer' AND t.name IN (N'LogNotes', N'LogNoteAuditEvents')
     ORDER BY t.name",
);
echo 'CREATED_TABLES=' . json_encode(array_column($tables, 'table_name')) . "\n";

$columns = $db->fetchAll(
    "SELECT t.name AS table_name, c.name AS column_name
     FROM sys.columns c
     INNER JOIN sys.tables t ON t.object_id = c.object_id
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE s.name = N'customer' AND t.name = N'LogNotes'
     ORDER BY c.column_id",
);
echo 'LOGNOTES_COLUMNS=' . json_encode(array_column($columns, 'column_name')) . "\n";

$indexes = $db->fetchAll(
    "SELECT i.name AS index_name, t.name AS table_name
     FROM sys.indexes i
     INNER JOIN sys.tables t ON t.object_id = i.object_id
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE s.name = N'customer' AND t.name IN (N'LogNotes', N'LogNoteAuditEvents') AND i.name IS NOT NULL
     ORDER BY t.name, i.name",
);
echo 'INDEXES=' . json_encode($indexes) . "\n";

$fks = $db->fetchAll(
    "SELECT fk.name AS fk_name, t.name AS table_name
     FROM sys.foreign_keys fk
     INNER JOIN sys.tables t ON t.object_id = fk.parent_object_id
     INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
     WHERE s.name = N'customer' AND t.name IN (N'LogNotes', N'LogNoteAuditEvents')
     ORDER BY t.name, fk.name",
);
echo 'FOREIGN_KEYS=' . json_encode($fks) . "\n";

echo "4JS_WRITES=0\n";
echo "LEGACY_WRITES=0\n";
echo "LEGACY_DATA_MIGRATION=NOT_RUN\n";
echo "PRODUCTION_DEPLOYMENT=0\n";
echo "LOG_NOTES_SCHEMA_APPLIED\n";
