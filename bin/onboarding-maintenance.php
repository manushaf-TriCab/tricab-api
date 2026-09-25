<?php
declare(strict_types=1);
/**
 * Onboarding maintenance (Phase 5.3). Two jobs, in the order the source ran them:
 *
 *  1. Spent email sign-in challenges older than 7 days are removed from
 *     [identity].[EmailOtpChallenges]. Nothing reads one after it expires.
 *  2. Onboarding retention is reviewed. Every class is RetentionDays = NULL,
 *     so every record is kept. If a period is ever set the job refuses rather
 *     than deletes, because no deletion routine has been designed.
 *
 * Dry run is the DEFAULT, following bin/tri-expense-purge-expired-receipts.php.
 * --apply deletes challenges; in production it also needs
 * --i-understand-this-deletes-data. The retention review never deletes.
 *
 * Not scheduled: there is no scheduler on the App Service (see
 * bin/onboarding-send-reminders.php).
 *
 * Usage:
 *   php bin/onboarding-maintenance.php            # dry run
 *   php bin/onboarding-maintenance.php --apply
 *   php bin/onboarding-maintenance.php --json
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Identity\Application\Service\PurgeExpiredOtpChallengesService;
use TriCab\Core\Onboarding\Application\Service\ReviewOnboardingRetentionService;

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
/** @var PurgeExpiredOtpChallengesService $challenges */
$challenges = $container->get(PurgeExpiredOtpChallengesService::class);
/** @var ReviewOnboardingRetentionService $retention */
$retention = $container->get(ReviewOnboardingRetentionService::class);

try {
    $purge = $challenges->run(!$apply);
    $review = $retention->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'ONBOARDING_MAINTENANCE_FAILED: ' . (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() . "\n");
    exit(1);
}

$exit = $review['decision'] === 'refused' ? 3 : 0;

if ($asJson) {
    echo json_encode(['otpChallenges' => $purge, 'retention' => $review], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($exit);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'MODE=' . ($purge['dryRun'] ? 'DRY_RUN' : 'APPLY') . "\n";
echo "OTP_CHALLENGES retention={$purge['retentionDays']}d cutoff={$purge['cutoffUtc']} eligible={$purge['eligible']} deleted={$purge['deleted']}\n";
if ($purge['dryRun'] && $purge['eligible'] > 0) {
    echo "  NOTHING_DELETED — re-run with --apply to remove them.\n";
}
echo 'RETENTION ' . strtoupper($review['decision']) . " — {$review['reason']}\n";
foreach ($review['classes'] as $class) {
    echo sprintf("  %-3s %-20s days=%-5s %s\n", $class['classCode'], $class['className'], $class['retentionDays'] ?? 'NULL', $class['action']);
}
echo $exit === 0 ? "ONBOARDING_MAINTENANCE_OK\n" : "ONBOARDING_MAINTENANCE_REFUSED\n";
exit($exit);
