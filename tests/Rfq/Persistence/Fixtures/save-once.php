<?php

declare(strict_types=1);

/**
 * One contender in AzureSqlRfqDraftStoreTest's race: waits for the barrier
 * file, then saves the draft once at the given revision and prints the
 * outcome. Run by the test, never by hand.
 *
 *     php save-once.php <draftId> <expectedRevision> <who> <barrier>
 */

use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Tests\Rfq\Persistence\LocalRfqSqlServer;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Shared\Time\SystemClock;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'];
[, $draftId, $expected, $who, $barrier] = $argv;
$pdo = LocalRfqSqlServer::open(
    (string) getenv('RFQ_TEST_SQLSERVER'),
    LocalRfqSqlServer::DATABASE,
    getenv('RFQ_TEST_SQLSERVER_USER') ?: 'sa',
    (string) getenv('RFQ_TEST_SQLSERVER_PASSWORD'),
);
$store = new AzureSqlRfqDraftStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()), new SystemClock());
$draft = $store->load($draftId);
if ($draft === null) {
    echo "missing\n";
    exit(1);
}
$rows = $draft->rows;
$rows[0]['line_note'] = "edited by {$who}";

$deadline = microtime(true) + 10;
while (!file_exists($barrier) && microtime(true) < $deadline) {
    usleep(1_000);
}

try {
    $revision = $store->saveContent($draftId, (int) $expected, ['by' => $who], $rows, 'in_progress', new DraftActor("{$who}@tricab.com", 'sales', null));
    echo "saved {$revision}\n";
} catch (RfqRevisionConflict $conflict) {
    echo "conflict {$conflict->currentRevision}\n";
}
