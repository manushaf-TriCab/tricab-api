<?php
declare(strict_types=1);
/**
 * Purge receipts that have sat in the recycle bin longer than the retention window.
 *
 * Dry-run is the DEFAULT. Deleting requires --apply, and in production it
 * additionally requires --i-understand-this-deletes-data, because this is the one
 * TriExpense job that destroys rows and blobs rather than writing new ones.
 *
 * WordPress-owned attachments are reported and skipped: legacy still serves those
 * files, so removing the row would orphan a file nobody can find again.
 * One failing receipt is reported and the batch continues.
 *
 * Usage:
 *   php bin/tri-expense-purge-expired-receipts.php            # dry run
 *   php bin/tri-expense-purge-expired-receipts.php --apply
 *   php bin/tri-expense-purge-expired-receipts.php --apply --json
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$apply = in_array('--apply', $argvList, true);
$asJson = in_array('--json', $argvList, true);
$acknowledged = in_array('--i-understand-this-deletes-data', $argvList, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($apply && $settings->isProduction() && !$acknowledged) {
    fwrite(STDERR, "REFUSING: --apply in production also requires --i-understand-this-deletes-data\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var PurgeExpenseReceiptService $service */
$service = $container->get(PurgeExpenseReceiptService::class);

try {
    $summary = $service->purgeExpired(!$apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'PURGE_FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'MODE=' . ($summary['dryRun'] ? 'DRY_RUN' : 'APPLY') . "\n";
echo 'RETENTION_DAYS=' . PurgeExpenseReceiptService::recycleDays() . "\n";
echo "CUTOFF_UTC={$summary['cutoffUtc']}\n";
echo "ELIGIBLE={$summary['eligible']} DELETED={$summary['deleted']} BLOCKED={$summary['blocked']}\n";

foreach ($summary['receipts'] as $row) {
    echo sprintf(
        "  %-38s deletedAt=%-26s attachment=%-16s %s%s\n",
        $row['publicId'],
        $row['deletedAtUtc'] ?? '-',
        $row['attachmentClass'],
        $row['action'],
        $row['reason'] !== null ? ' (' . $row['reason'] . ')' : '',
    );
}

if ($summary['dryRun']) {
    echo "NOTHING_DELETED — re-run with --apply to delete the rows listed above.\n";
}
echo "PURGE_OK\n";
