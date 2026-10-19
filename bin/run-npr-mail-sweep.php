<?php

declare(strict_types=1);

/**
 * NPR workflow email retry sweep (plan N2.4): sends outbox rows an earlier attempt could not.
 *
 *   php bin/run-npr-mail-sweep.php               # one pass, then exit
 *   php bin/run-npr-mail-sweep.php --if-woken    # only when something failed, or the idle check is due
 *   php bin/run-npr-mail-sweep.php --idle-check=1440   # minutes between looks with no signal (default a day)
 *   php bin/run-npr-mail-sweep.php --limit=50
 *
 * The `npr-mail` WebJob runs it every minute with `--if-woken`. A failed in-request send writes a wake file
 * (NPR_MAIL_WAKE_PATH); with no wake file, no unfinished pass and no idle check due, the sweep exits at once
 * WITHOUT touching the database, so the serverless `tc` can stay paused. See {@see FileNprMailWake}.
 *
 * Rows are re-sent with a backoff of 1, 5, 15 minutes, 1 and 4 hours, five attempts in all; after that an
 * administrator retries them from GET/POST /api/v1/npr/admin/notifications. A retry sends the stored snapshot;
 * it never repeats a workflow step. Prints one JSON line per pass that reached the database.
 *
 * Exit codes:
 *    0  ran, or had nothing to do
 *    2  NPR_MAIL_MODE is test/live but the Graph registration or NPR_MAIL_SENDER is missing
 *    3  paused by design: NPR_MAIL_MODE=off (the database is not touched)
 *   75  the database is resuming (the next pass retries)
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Npr\Mail\FileNprMailWake;
use TriCab\Core\Npr\Mail\NprMailDispatcher;

$options = getopt('', ['if-woken', 'idle-check::', 'limit::']);
$ifWoken = isset($options['if-woken']);
$idleCheck = max(1, (int) ($options['idle-check'] ?? 1440));
$limit = max(1, min(200, (int) ($options['limit'] ?? 50)));

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));

if ($settings->nprMailMode === 'off') {
    fwrite(STDERR, "NPR mail sweep: NPR_MAIL_MODE=off; nothing to do.\n");
    exit(3);
}
if ($settings->graphTenantId === '' || $settings->graphClientId === '' || $settings->graphClientSecret === '' || $settings->nprMailSender === '') {
    fwrite(STDERR, "NPR mail sweep: GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET and NPR_MAIL_SENDER are required.\n");
    exit(2);
}

$wake = FileNprMailWake::fromSettings($settings);
if ($ifWoken && !$wake->beginPass($idleCheck)) {
    exit(0);
}

$dispatcher = ContainerFactory::create($settings)->get(NprMailDispatcher::class);
if (!$dispatcher instanceof NprMailDispatcher) {
    fwrite(STDERR, "NPR mail sweep: the container has no dispatcher.\n");
    exit(1);
}

try {
    $summary = $dispatcher->sweep($limit);
    $wake->endPass($summary['outstanding']);
    fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $summary, JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (DatabaseTemporarilyUnavailable) {
    // Usually the serverless database resuming; the pending flag stays, so the next pass tries again.
    fwrite(STDERR, "NPR mail sweep: the database is not available yet; the next pass retries.\n");
    exit(75);
}
