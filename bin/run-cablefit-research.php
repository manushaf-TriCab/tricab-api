<?php

declare(strict_types=1);

/**
 * CableFit research worker (C3): one pass over the queued research runs.
 *
 *   php bin/run-cablefit-research.php               # up to 3 runs, then exit
 *   php bin/run-cablefit-research.php --runs=1      # at most one run
 *   php bin/run-cablefit-research.php --loop=60     # keep going, a pass every 60 s
 *   php bin/run-cablefit-research.php --if-woken    # only when there may be work
 *
 * The `cablefit-research` WebJob runs it every minute with `--if-woken`: a pass then goes to the database
 * only when a request has signalled, runs are still outstanding, or the idle check
 * (`CABLEFIT_RESEARCH_IDLE_CHECK_MINUTES`, or `--idle-check`) is due, so idle passes leave the serverless
 * database paused. See {@see FileCableFitResearchWake}. Without the flag every pass reads the queue.
 *
 * A pass claims one queued run at a time (a claim returns only the run that call claimed, so a second
 * worker is harmless; `pass.lock` stops two passes of this worker overlapping anyway) and calls the model
 * for each. Prints one JSON summary line per pass that reached the database, counts only.
 *
 * Exit codes (docs/runbooks/cablefit-research.md):
 *    0  a pass ran, or had nothing to do
 *    1  unexpected failure
 *    2  switched on but not configured (Foundry, or the run store is not the database)
 *    3  switched off by design: CABLEFIT_RESEARCH_ENABLED is not true
 *    5  another pass holds the lock
 *   75  the database is resuming (the work stays pending; the next pass retries)
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\CableFit\Research\FileCableFitResearchWake;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Mail\Intake\FileMailReaderLock;
use TriCab\Core\CableFit\Application\ResearchRunner;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Infrastructure\InMemoryResearchRunRepository;

const EXIT_OK = 0;
const EXIT_FAILED = 1;
const EXIT_NOT_CONFIGURED = 2;
const EXIT_OFF = 3;
const EXIT_LOCKED = 5;
const EXIT_DATABASE_RESUMING = 75;

$options = getopt('', ['runs::', 'loop::', 'if-woken', 'idle-check::', 'allow-memory']);
$runLimit = max(1, (int) ($options['runs'] ?? 3));
$loop = isset($options['loop']) ? max(5, (int) $options['loop']) : 0;
$ifWoken = isset($options['if-woken']);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
$idleCheck = max(1, (int) ($options['idle-check'] ?? $settings->cablefitResearchIdleCheckMinutes));

if (!$settings->cablefitResearchEnabled) {
    exit(EXIT_OFF);
}
try {
    $wake = FileCableFitResearchWake::fromSettings($settings);
    $lock = FileMailReaderLock::acquire($wake->directory());
    if ($lock === null) {
        fwrite(STDERR, "CableFit research: another pass holds the lock.\n");
        exit(EXIT_LOCKED);
    }

    $status = EXIT_OK;
    do {
        if (!$ifWoken || $wake->beginPass($idleCheck)) {
            try {
                $container = ContainerFactory::create($settings, hydratePlatformSettings: true);
                $passSettings = $container->get(Settings::class);
                if (!$passSettings->isCableFitResearchConfigured()) {
                    fwrite(STDERR, "CableFit research: switched on but not configured: needs FOUNDRY_ENABLED, FOUNDRY_ENDPOINT, CABLEFIT_RESEARCH_MODEL and, with FOUNDRY_AUTH_MODE=api-key, FOUNDRY_API_KEY.\n");
                    exit(EXIT_NOT_CONFIGURED);
                }
                $runs = $container->get(ResearchRunRepository::class);
                $runner = $container->get(ResearchRunner::class);
                if (!$runs instanceof ResearchRunRepository || !$runner instanceof ResearchRunner) {
                    fwrite(STDERR, "CableFit research: the container has no research runner.\n");
                    exit(EXIT_FAILED);
                }
                if ($runs instanceof InMemoryResearchRunRepository && !isset($options['allow-memory'])) {
                    fwrite(STDERR, "CableFit research: the run store is in memory (USER_REPOSITORY is not azure-sql), so runs would be lost. Refusing.\n");
                    exit(EXIT_NOT_CONFIGURED);
                }
                $processed = 0;
                $succeeded = 0;
                $failed = 0;
                while ($processed < $runLimit && ($run = $runner->runNext()) !== null) {
                    $processed++;
                    if ($run->status === ResearchRun::SUCCEEDED) {
                        $succeeded++;
                    } else {
                        $failed++;
                    }
                }
                $outstanding = $runs->outstanding();
                $wake->endPass($outstanding);
                fwrite(STDOUT, json_encode([
                    'at' => gmdate('c'),
                    'processed' => $processed,
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                    'outstanding' => $outstanding,
                ], JSON_THROW_ON_ERROR) . "\n");
                $status = EXIT_OK;
            } catch (DatabaseTemporarilyUnavailable) {
                // Usually the serverless database resuming. The work stays pending, so the next pass tries again.
                fwrite(STDERR, "CableFit research: the database is not available yet; the next pass retries.\n");
                $status = EXIT_DATABASE_RESUMING;
            }
        }
        if ($loop > 0) {
            sleep($loop);
        }
    } while ($loop > 0);

    $lock->release();
    exit($status);
} catch (Throwable $failure) {
    // Class only: a message could carry connection detail.
    fwrite(STDERR, 'CableFit research: unexpected failure (' . $failure::class . ").\n");
    exit(EXIT_FAILED);
}
