<?php

declare(strict_types=1);

/**
 * RFQ reader worker (plan 3.5): one pass over the queued background reads.
 *
 *   php bin/run-rfq-reader-jobs.php               # one draft's reads, then exit
 *   php bin/run-rfq-reader-jobs.php --drafts=5    # up to five drafts
 *   php bin/run-rfq-reader-jobs.php --loop=60     # keep going, a pass every 60 s
 *
 * The source ran this as a WebJob every minute (`rfq-portal-worker-webjob`,
 * one read per tick). Nothing schedules this one yet: that is a deployment
 * decision (plan 6.5). Safe to run twice at once: a claim returns only the
 * jobs that call claimed, and a stale claim (30 minutes) is recovered by the
 * next pass. Prints one JSON summary line per pass, with counts only.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;

$options = getopt('', ['drafts::', 'loop::']);
$drafts = max(1, (int) ($options['drafts'] ?? 1));
$loop = isset($options['loop']) ? max(5, (int) $options['loop']) : 0;

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

do {
    $summary = $reader->runWorker($drafts);
    fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $summary, JSON_THROW_ON_ERROR) . "\n");
    if ($loop > 0) {
        sleep($loop);
    }
} while ($loop > 0);
