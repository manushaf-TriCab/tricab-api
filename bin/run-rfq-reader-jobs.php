<?php

declare(strict_types=1);

/**
 * RFQ reader worker (plan 3.5): one pass over the queued background reads.
 *
 *   php bin/run-rfq-reader-jobs.php               # one draft's reads, then exit
 *   php bin/run-rfq-reader-jobs.php --drafts=5    # up to five drafts
 *   php bin/run-rfq-reader-jobs.php --loop=60     # keep going, a pass every 60 s
 *   php bin/run-rfq-reader-jobs.php --if-woken    # only when there may be work
 *
 * The `rfq-reader` WebJob runs it every minute with `--if-woken`: a pass then
 * goes to the database only when an upload has signalled, reads are still
 * outstanding, or the idle check (`--idle-check`, minutes, default a day) is
 * due, so idle passes leave the serverless database paused. See
 * {@see FileRfqReaderWake}. Without the flag every pass reads the queue.
 *
 * Safe to run twice at once: a claim returns only the jobs that call claimed,
 * and a stale claim (30 minutes) is recovered by the next pass. Prints one JSON
 * summary line per pass that reached the database, with counts only; a skipped
 * pass prints nothing.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Rfq\Reader\FileRfqReaderWake;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;

$options = getopt('', ['drafts::', 'loop::', 'if-woken', 'idle-check::']);
$drafts = max(1, (int) ($options['drafts'] ?? 1));
$loop = isset($options['loop']) ? max(5, (int) $options['loop']) : 0;
$ifWoken = isset($options['if-woken']);
$idleCheck = max(1, (int) ($options['idle-check'] ?? 1440));

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
if (!$settings->isRfqParserEnabled()) {
    fwrite(STDERR, "RFQ reader: RFQ_PARSER_ENABLED (with a token) must be on.\n");
    exit(2);
}
$reader = ContainerFactory::create($settings)->get(RfqReaderService::class);
if (!$reader instanceof RfqReaderService) {
    fwrite(STDERR, "RFQ reader: the container has no reader service.\n");
    exit(1);
}

$wake = FileRfqReaderWake::fromSettings($settings);
$status = 0;

do {
    if (!$ifWoken || $wake->beginPass($idleCheck)) {
        try {
            $summary = $reader->runWorker($drafts);
            $wake->endPass($summary['outstanding']);
            fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $summary, JSON_THROW_ON_ERROR) . "\n");
            $status = 0;
        } catch (DatabaseTemporarilyUnavailable) {
            // Usually the serverless database resuming. The work stays pending,
            // so the next pass tries again.
            fwrite(STDERR, "RFQ reader: the database is not available yet; the next pass retries.\n");
            $status = 75;
        }
    }
    if ($loop > 0) {
        sleep($loop);
    }
} while ($loop > 0);

exit($status);
