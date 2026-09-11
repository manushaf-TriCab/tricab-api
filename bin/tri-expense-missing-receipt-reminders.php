<?php
declare(strict_types=1);
/**
 * Missing-receipt reminders — MANUAL, not scheduled.
 *
 * In legacy TriExpense these chasers were only ever sent when a Finance user
 * clicked send while working a reconciliation: they name individual employees
 * over individual card transactions, and Finance chose the moment deliberately.
 * The monthly manager digest was the automatic one (cron, day 1); this was not.
 *
 * Phase 5 keeps that distinction rather than quietly promoting this to a cron.
 * The command therefore exists — so the pathway is scriptable and testable — but
 * refuses to do anything unless an operator passes --force, and it requires the
 * Finance user id it is acting as, because the reminder log records who sent it.
 *
 * Prefer the Finance UI / endpoint:
 *   POST /api/v1/tri-expense/finance/reminders/missing-receipts
 *
 * Usage:
 *   php bin/tri-expense-missing-receipt-reminders.php
 *   php bin/tri-expense-missing-receipt-reminders.php --force --as-user-id=123
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Service\PrepareMissingReceiptRemindersService;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$force = in_array('--force', $argvList, true);

$asUserId = null;
foreach ($argvList as $argument) {
    if (str_starts_with((string) $argument, '--as-user-id=')) {
        $asUserId = (int) substr((string) $argument, strlen('--as-user-id='));
    }
}

if (!$force) {
    echo "MANUAL_ACTION_NOT_RUN\n";
    echo "Missing-receipt reminders were a manual Finance action in legacy TriExpense\n";
    echo "and remain one: they chase named employees about specific card transactions.\n";
    echo "Only the monthly manager digest is automatic (bin/tri-expense-monthly-manager-digest.php).\n";
    echo "\n";
    echo "Use the Finance endpoint POST /api/v1/tri-expense/finance/reminders/missing-receipts,\n";
    echo "or re-run with: --force --as-user-id=<finance user id>\n";
    exit(0);
}

if ($asUserId === null || $asUserId < 1) {
    fwrite(STDERR, "REFUSING: --force requires --as-user-id=<finance user id>\n");
    exit(4);
}

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

/** @var ExpenseProfileRepository $profiles */
$profiles = $container->get(ExpenseProfileRepository::class);
$profile = $profiles->findByUserId(UserId::fromInt($asUserId));
if ($profile === null || !$profile->isActive()) {
    fwrite(STDERR, "NO_ACTIVE_EXPENSE_PROFILE for user {$asUserId}\n");
    exit(5);
}
if (!$profile->isFinance()) {
    fwrite(STDERR, "NOT_FINANCE: user {$asUserId} does not carry ExpenseProfile.IsFinance\n");
    exit(5);
}

$actor = new ExpenseActor(
    $profile->userId(),
    'cli-' . $profile->userId()->toInt(),
    $profile->legacyEmail(),
    [Module::PERMISSION_ACCESS],
    ['employee'],
);

/** @var PrepareMissingReceiptRemindersService $service */
$service = $container->get(PrepareMissingReceiptRemindersService::class);

try {
    $prepared = $service->execute($actor);
} catch (Throwable $e) {
    fwrite(STDERR, 'MISSING_RECEIPT_REMINDERS_FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "APP_ENV={$settings->appEnv}\n";
echo 'EMAIL_SENDING=' . ($settings->isTriexpenseEmailSendingEnabled() ? 'ENABLED' : 'DISABLED_RECORD_ONLY') . "\n";
echo 'TEST_RECIPIENT_OVERRIDE=' . ($settings->triexpenseEmailTestRecipientOrNull() !== null ? 'YES' : 'NO') . "\n";
echo "ACTED_AS_USER_ID={$asUserId}\n";
echo "PREPARED={$prepared}\n";
echo "MISSING_RECEIPT_REMINDERS_OK\n";
