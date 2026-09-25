<?php
declare(strict_types=1);
/**
 * Onboarding reminder digests (Phase 5.2) — one email per person listing what
 * is still theirs to do: overdue, due within two days, or undated.
 *
 * OFF unless ONBOARDING_REMINDER_EMAILS is exactly "on". Outside production it
 * also needs ONBOARDING_EMAIL_TEST_RECIPIENT (every reminder goes there), or
 * GUEST_EMAIL_PROVIDER=development (nothing is delivered). Switched off, a run
 * reads nothing, claims nothing and sends nothing.
 *
 * Not scheduled. The platform has no scheduler on the App Service, and adding
 * one (a WebJob, a Logic App, a Function) is an Azure change the owner has not
 * approved. Safe to run on a timer once it exists: onboarding.NotificationLog
 * allows one reminder per person, item and day, so a second run the same day
 * only sends what has become due since the first.
 *
 * Output carries counts and user ids, never addresses — it lands in logs.
 *
 * Usage:
 *   php bin/onboarding-send-reminders.php --dry-run    # who would be reminded; sends and records nothing
 *   php bin/onboarding-send-reminders.php
 *   php bin/onboarding-send-reminders.php --json
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Onboarding\Application\Service\SendOnboardingRemindersService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true);
$asJson = in_array('--json', $argvList, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

/** @var SendOnboardingRemindersService $service */
$service = $container->get(SendOnboardingRemindersService::class);

try {
    $summary = $service->run($dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, 'ONBOARDING_REMINDERS_FAILED: ' . (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() . "\n");
    exit(1);
}

if ($asJson) {
    echo json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($summary->failures === [] ? 0 : 2);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'MODE=' . ($summary->dryRun ? 'DRY_RUN' : 'SEND') . "\n";
echo 'REMINDERS=' . ($summary->enabled ? 'ENABLED' : 'DISABLED') . " ({$summary->deliveryReason})\n";
echo 'EMAIL_PROVIDER=' . $settings->guestEmailProvider . "\n";
echo 'TEST_RECIPIENT_OVERRIDE=' . ($summary->testRecipientOverride ? 'YES' : 'NO') . "\n";
echo "LOCAL_DATE={$summary->localDate}\n";
echo "CANDIDATES={$summary->candidates} PEOPLE={$summary->people} EMAILS_SENT={$summary->emailsSent} ITEMS={$summary->itemsIncluded} ALREADY_SENT_TODAY={$summary->alreadySentToday}\n";
foreach ($summary->failures as $failure) {
    echo "  FAILED {$failure}\n";
}
if ($summary->dryRun) {
    echo "NOTHING_SENT — a dry run neither emails nor records anything.\n";
}
echo $summary->failures === [] ? "ONBOARDING_REMINDERS_OK\n" : "ONBOARDING_REMINDERS_PARTIAL\n";
exit($summary->failures === [] ? 0 : 2);
