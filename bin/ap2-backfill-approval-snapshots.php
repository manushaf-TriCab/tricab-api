<?php

declare(strict_types=1);

/**
 * Tri-AP2: gives migrated Approved approvals the snapshot they would have today, so they read as current.
 *
 *   php bin/ap2-backfill-approval-snapshots.php             # dry run: counts only, writes nothing
 *   php bin/ap2-backfill-approval-snapshots.php --apply     # stores the snapshots, one ap2_history row each
 *
 * Touches only approvals listed in finance.ap2_migration_map (source_table 'invoice_approval') whose status is
 * Approved and whose snapshot is NULL. Refuses a DB_HOST that is not this machine unless --allow-shared is given.
 * Prints one JSON line of counts. Exit codes: 0 done, 1 failure or refused, 2 usage.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap2\Migration\Ap2SnapshotBackfill;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Service\Ap2Snapshots;
use TriCab\Core\Finance\Ar\Migration\ArMigrationGuard;

$options = getopt('', ['apply', 'allow-shared']);
$apply = isset($options['apply']);

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Refused: needs USER_REPOSITORY=azure-sql and the DB_* settings.\n");
    exit(1);
}
if (!ArMigrationGuard::isLocal($settings->dbHost) && !isset($options['allow-shared'])) {
    fwrite(STDERR, "Refused: DB_HOST is not this machine. Add --allow-shared to run against a shared database.\n");
    exit(1);
}

try {
    $container = ContainerFactory::create($settings);
    $connection = $container->get(SqlConnection::class);
    $approvals = $container->get(Ap2ApprovalStore::class);
    $invoices = $container->get(Ap2InvoiceStore::class);
    $snapshots = $container->get(Ap2Snapshots::class);
    $history = $container->get(Ap2HistoryStore::class);
    if (!$connection instanceof SqlConnection || !$approvals instanceof Ap2ApprovalStore || !$invoices instanceof Ap2InvoiceStore
        || !$snapshots instanceof Ap2Snapshots || !$history instanceof Ap2HistoryStore) {
        throw new RuntimeException('The container has no Tri-AP2 database stores.');
    }
    $rows = $connection->fetchAll(
        "SELECT m.[target_id] FROM [finance].[ap2_migration_map] m
           JOIN [finance].[ap2_approvals] a ON a.[id] = m.[target_id]
          WHERE m.[source_table] = N'invoice_approval' AND a.[status] = N'Approved' AND a.[snapshot] IS NULL",
    );
    $ids = array_values(array_map(static fn (array $r): string => strtolower(trim((string) $r['target_id'])), $rows));
    $counts = (new Ap2SnapshotBackfill($approvals, $invoices, $snapshots, $history))->run($ids, $apply, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    fwrite(STDOUT, json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'host' => $settings->dbHost] + $counts, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
