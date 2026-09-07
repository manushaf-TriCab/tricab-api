<?php
declare(strict_types=1);
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
echo "APP_ENV={$settings->appEnv}\nSERVER={$server}\nDB={$database}\nUSER={$user}\nTARGET_SCHEMA=expense\n";
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc\n");
    exit(4);
}
$script = $basePath . '/database/scripts/development/create-expense-schema.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) { fwrite(STDERR, "EXPENSE_SCHEMA_FAILED\n"); exit($code); }
$check = $db->fetchAll("SELECT t.name AS table_name FROM sys.tables t INNER JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'expense' ORDER BY t.name");
echo 'CREATED_TABLES=' . json_encode(array_column($check, 'table_name')) . "\n";
$idx = $db->fetchAll("SELECT i.name AS index_name, t.name AS table_name FROM sys.indexes i INNER JOIN sys.tables t ON t.object_id=i.object_id INNER JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'expense' AND i.name IS NOT NULL ORDER BY t.name, i.name");
echo 'INDEXES=' . json_encode($idx) . "\n";
$fks = $db->fetchAll("SELECT fk.name AS fk_name, t.name AS table_name FROM sys.foreign_keys fk INNER JOIN sys.tables t ON t.object_id=fk.parent_object_id INNER JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'expense' ORDER BY t.name, fk.name");
echo 'FOREIGN_KEYS=' . json_encode($fks) . "\n";
$rv = $db->fetchAll("SELECT t.name AS table_name FROM sys.columns c INNER JOIN sys.tables t ON t.object_id=c.object_id INNER JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE s.name=N'expense' AND c.name=N'RowVersion' ORDER BY t.name");
echo 'ROWVERSION_TABLES=' . json_encode(array_column($rv, 'table_name')) . "\n";
echo "EXPENSE_SCHEMA_APPLIED\n";
