<?php
declare(strict_types=1);
/**
 * Apply additive Phase 5 Expense schema to verified non-production Azure SQL.
 * Adds reminder-log classification + scheduled-digest idempotency key.
 * Zero legacy writes.
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
echo "APP_ENV={$settings->appEnv}\nSERVER={$server}\nDB={$database}\nUSER={$env[0]['userName']}\n";
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc\n");
    exit(4);
}

$script = $basePath . '/database/scripts/development/alter-expense-schema-phase5.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) {
    fwrite(STDERR, "EXPENSE_PHASE5_SCHEMA_FAILED\n");
    exit($code);
}

$columns = $db->fetchAll("
    SELECT COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = N'expense' AND TABLE_NAME = N'ExpenseReminderLog'
");
$present = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $columns);
$missing = array_values(array_diff(['ReminderType', 'IdempotencyKey'], $present));
if ($missing !== []) {
    fwrite(STDERR, 'MISSING=' . json_encode($missing) . "\n");
    exit(5);
}

$indexes = $db->fetchAll("
    SELECT name
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[expense].[ExpenseReminderLog]')
      AND name = N'UX_expense_ExpenseReminderLog_IdempotencyKey'
");
if ($indexes === []) {
    fwrite(STDERR, "MISSING_INDEX=UX_expense_ExpenseReminderLog_IdempotencyKey\n");
    exit(5);
}

echo "PHASE5_SCHEMA_APPLIED\n";
echo "LEGACY_WRITES=0\n";
