<?php
declare(strict_types=1);
/**
 * Scheduled monthly manager digest — the AUTOMATIC half of the legacy reminder pair.
 *
 * Legacy TriExpense ran this from cron on the first of the month, Melbourne time,
 * so the service evaluates "day 1" in Australia/Melbourne and exits quietly on
 * every other day. That makes the command safe to schedule daily.
 *
 * Idempotent: each send is keyed manager+month+type in the reminder log, so a
 * retried cron, a second host, or a manual --force run cannot double-mail.
 *
 * Whether mail actually leaves is governed by TRIEXPENSE_EMAIL_ENABLED and
 * TRIEXPENSE_EMAIL_TEST_RECIPIENT, not by this script.
 *
 * Usage: php bin/tri-expense-monthly-manager-digest.php [--force] [--json]
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$force = in_array('--force', $argvList, true);
$asJson = in_array('--json', $argvList, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

/** @var MonthlyManagerDigestService $service */
$service = $container->get(MonthlyManagerDigestService::class);

try {
    $result = $service->execute($force);
} catch (Throwable $e) {
    fwrite(STDERR, 'MONTHLY_DIGEST_FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'EMAIL_SENDING=' . ($settings->isTriexpenseEmailSendingEnabled() ? 'ENABLED' : 'DISABLED_RECORD_ONLY') . "\n";
echo 'TEST_RECIPIENT_OVERRIDE=' . ($settings->triexpenseEmailTestRecipientOrNull() !== null ? 'YES' : 'NO') . "\n";
echo "LOCAL_DATE={$result['localDate']} (" . \TriCab\Core\Expense\Module::SCHEDULER_TIMEZONE . ")\n";
echo "PERIOD={$result['periodKey']}\n";

if (!$result['ran']) {
    echo "SKIPPED reason={$result['skippedReason']}\n";
    echo "Pass --force to run outside the first of the month.\n";
    exit(0);
}

echo "MANAGERS_CONSIDERED={$result['managersConsidered']}\n";
echo "SENT={$result['sent']} ALREADY_SENT={$result['alreadySent']} SKIPPED={$result['skipped']}\n";
foreach ($result['recipients'] as $recipient) {
    echo sprintf("  %-40s pending=%-4d %s\n", $recipient['email'], $recipient['pending'], $recipient['action']);
}
echo "MONTHLY_DIGEST_OK\n";
