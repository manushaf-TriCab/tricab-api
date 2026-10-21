<?php

declare(strict_types=1);

/**
 * Finance job runner: one pass over the jobs that are due.
 *
 *   php bin/run-finance-jobs.php             # one pass, then exit
 *   php bin/run-finance-jobs.php --loop=60   # keep going, a pass every 60 s
 *
 * Run by the `finance-jobs` continuous WebJob (`deploy/app-service/webjobs/finance-jobs`),
 * which loops it every minute. Does nothing, and exits 0, while `FINANCE_MODULE_ENABLED`
 * is not true: nothing under it is switched on then. Safe to run twice at once: a due
 * slot of a job is claimed in `finance.job_runs`, so it runs once (a slot swap runs two
 * instances for a while). Prints one JSON line per pass, with counts and job names only.
 * Exits 0 even when a job failed (the failure is in `finance.audit_events`); it exits 1
 * only if the runner itself cannot be built.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;

$options = getopt('', ['loop::']);
$loop = isset($options['loop']) ? max(5, (int) $options['loop']) : 0;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
if (!$settings->financeModuleEnabled) {
    fwrite(STDOUT, json_encode(['at' => gmdate('c'), 'enabled' => false], JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

do {
    // Resolve persisted settings for each pass. A long-lived WebJob must see
    // administrative changes before claiming its next due job.
    $runner = ContainerFactory::create($settings, hydratePlatformSettings: true)->get(FinanceJobRunner::class);
    if (!$runner instanceof FinanceJobRunner) {
        fwrite(STDERR, "Finance jobs: the container has no runner.\n");
        exit(1);
    }
    $outcomes = $runner->runDue();
    $count = static fn (string $status): int => count(array_filter($outcomes, static fn (FinanceJobOutcome $o): bool => $o->status === $status));
    fwrite(STDOUT, json_encode([
        'at' => gmdate('c'),
        'enabled' => true,
        'jobs' => count($outcomes),
        'ran' => $count(FinanceJobOutcome::RAN),
        'failed' => $count(FinanceJobOutcome::FAILED),
        'notDue' => $count(FinanceJobOutcome::NOT_DUE),
        'alreadyClaimed' => $count(FinanceJobOutcome::ALREADY_CLAIMED),
        'claimErrors' => $count(FinanceJobOutcome::CLAIM_ERROR),
        'failedJobs' => array_values(array_map(
            static fn (FinanceJobOutcome $o): string => $o->job,
            array_filter($outcomes, static fn (FinanceJobOutcome $o): bool => $o->status === FinanceJobOutcome::FAILED || $o->status === FinanceJobOutcome::CLAIM_ERROR),
        )),
    ], JSON_THROW_ON_ERROR) . "\n");
    if ($loop > 0) {
        sleep($loop);
    }
} while ($loop > 0);
