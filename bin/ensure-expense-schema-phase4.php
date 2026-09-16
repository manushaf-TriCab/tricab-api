<?php
declare(strict_types=1);
/**
 * Apply additive Phase 4 Expense finance schema to verified non-production Azure SQL.
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

$inspect = static function (SqlConnection $db): array {
    $cols = $db->fetchAll("
        SELECT TABLE_NAME, COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = N'expense'
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");
    return ['columns' => $cols];
};

$script = $basePath . '/database/scripts/development/alter-expense-schema-phase4.sql';
passthru('php ' . escapeshellarg($basePath . '/bin/run-sql-script.php') . ' ' . escapeshellarg($script), $code);
if ($code !== 0) {
    fwrite(STDERR, "EXPENSE_PHASE4_SCHEMA_FAILED\n");
    exit($code);
}

$after = $inspect($db);
$required = [
    'ExpenseBankFeedEntries' => ['PublicId', 'CardholderUserId', 'FirstName', 'LastName', 'OffsetBankFeedEntryId', 'Resolution', 'RowVersion'],
    'ExpensePeriods' => ['PublicId', 'TransactionStartDate', 'TransactionEndDate', 'ClosedByUserId', 'RowVersion'],
    'ExpenseReminderLog' => ['RecipientEmail', 'SentByUserId', 'SentAtUtc'],
];
$byTable = [];
foreach ($after['columns'] as $row) {
    $byTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
}
$missing = [];
foreach ($required as $table => $cols) {
    foreach ($cols as $col) {
        if (!in_array($col, $byTable[$table] ?? [], true)) {
            $missing[] = $table . '.' . $col;
        }
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'MISSING=' . json_encode($missing) . "\n");
    exit(5);
}
echo "PHASE4_SCHEMA_APPLIED\n";
echo "LEGACY_WRITES=0\n";
