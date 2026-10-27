<?php

declare(strict_types=1);

/**
 * Tri Expenses scheduler dispatcher (monthly manager digest + recycle-bin purge).
 * NOT ENABLED: nothing runs this until the WebJobs under deploy/app-service/webjobs/tri-expense-*
 * are deployed at cutover. See tc-web/docs/cc-te-migration/TRI-EXPENSES-SCHEDULERS.md.
 *
 *   php bin/run-tri-expense-schedulers.php --job=digest|purge|all [--purge-apply] [--catch-up-days=7]
 *
 * The purge is a DRY RUN unless --purge-apply is passed (and in production that additionally
 * needs --i-understand-this-deletes-data). One JSON log line per job on stdout.
 * Exit: 0 ok, 1 job failed, 4 refused, 5 another instance holds the lock.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Expense\Scheduler\ExpenseSchedulerRunner;
use TriCab\Api\Expense\Scheduler\FileExpenseSchedulerLock;
use TriCab\Api\Expense\Scheduler\FileExpenseSchedulerRunStore;
use TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;
use TriCab\Core\Shared\Time\SystemClock;

CoreAutoload::register();

$opts = getopt('', ['job::', 'purge-apply', 'catch-up-days::', 'i-understand-this-deletes-data']);
$jobArg = (string) ($opts['job'] ?? 'all');
$jobs = match ($jobArg) {
    'all' => [ExpenseSchedulerRunner::JOB_DIGEST, ExpenseSchedulerRunner::JOB_PURGE],
    'digest' => [ExpenseSchedulerRunner::JOB_DIGEST],
    'purge' => [ExpenseSchedulerRunner::JOB_PURGE],
    default => null,
};
if ($jobs === null) {
    fwrite(STDERR, "Unknown --job (digest|purge|all)\n");
    exit(4);
}
$apply = array_key_exists('purge-apply', $opts);
$catchUp = max(1, min(28, (int) ($opts['catch-up-days'] ?? 7)));

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
if ($apply && in_array('purge', $jobs, true) && $settings->isProduction() && !array_key_exists('i-understand-this-deletes-data', $opts)) {
    fwrite(STDERR, "REFUSING: --purge-apply in production also requires --i-understand-this-deletes-data\n");
    exit(4);
}

$container = ContainerFactory::create($settings, hydratePlatformSettings: true);
$stateDir = getenv('TRIEXPENSE_SCHEDULER_STATE_DIR') ?: $basePath . '/var/expense-scheduler';
$runner = new ExpenseSchedulerRunner(
    $container->get(MonthlyManagerDigestService::class),
    $container->get(PurgeExpenseReceiptService::class),
    new FileExpenseSchedulerLock($stateDir),
    new FileExpenseSchedulerRunStore($stateDir),
    new SystemClock(),
    static function (array $line): void {
        fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $line, JSON_UNESCAPED_SLASHES) . "\n");
    },
);

try {
    $out = $runner->run($jobs, $apply, $catchUp);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['event' => 'expense_scheduler_fatal', 'error' => get_class($e)]) . "\n");
    exit(1);
}
exit($out['exitCode']);
