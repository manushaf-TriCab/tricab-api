<?php
declare(strict_types=1);
/**
 * Apply the activity analytics retention policy: sessions 30 days, hourly buckets 14 days,
 * daily aggregates 400 days.
 *
 * Dry-run is the DEFAULT. Deleting requires --apply, and in production it additionally
 * requires --i-understand-this-deletes-data.
 *
 * Usage:
 *   php bin/purge-user-activity.php            # dry run
 *   php bin/purge-user-activity.php --apply
 *   php bin/purge-user-activity.php --apply --json
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Analytics\Application\Service\PurgeUserActivityService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$apply = in_array('--apply', $argvList, true);
$asJson = in_array('--json', $argvList, true);
$acknowledged = in_array('--i-understand-this-deletes-data', $argvList, true);

$settings = Environment::settings(dirname(__DIR__));

if ($apply && $settings->isProduction() && !$acknowledged) {
    fwrite(STDERR, "REFUSING: --apply in production also requires --i-understand-this-deletes-data\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var PurgeUserActivityService $service */
$service = $container->get(PurgeUserActivityService::class);

try {
    $summary = $service->purge(!$apply);
} catch (Throwable $e) {
    fwrite(STDERR, 'PURGE_FAILED: ' . $e::class . "\n");
    exit(1);
}

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'MODE=' . ($summary['dryRun'] ? 'DRY_RUN' : 'APPLY') . "\n";
echo "SESSIONS_BEFORE_UTC={$summary['sessionsBeforeUtc']}\n";
echo "HOURLY_BEFORE_UTC={$summary['hourlyBeforeUtc']}\n";
echo "DAILY_BEFORE_DATE={$summary['dailyBeforeDate']}\n";
foreach ($summary['removed'] as $table => $count) {
    echo sprintf("  %-12s %d\n", $table, $count);
}
if ($summary['dryRun']) {
    echo "NOTHING_DELETED — re-run with --apply to delete the rows counted above.\n";
}
echo "PURGE_OK\n";
