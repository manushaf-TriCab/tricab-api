<?php

declare(strict_types=1);

/**
 * Moves the old Tri-AR app's data into the platform's Tri-AR tables (Phase 5, T5.15).
 *
 *   php bin/migrate-finance-ar.php --source=ar.sqlite3 --attachments=attachments/ --translate-ids            # a plan; writes nothing
 *   php bin/migrate-finance-ar.php --source=ar.sqlite3 --attachments=attachments/ --translate-ids --apply     # writes
 *   php bin/migrate-finance-ar.php --source=... --attachments=... --without-message-ids --apply               # blanks every message id
 *
 * `--source` is the old app's SQLite file and `--attachments` its attachments folder; both are only read. The
 * target is the database the platform's own settings name (`DB_*`, `USER_REPOSITORY=azure-sql`), and the file store
 * is the finance blob container (`BLOB_AUTH_MODE=connection-string`). Assignees and "who" columns are resolved
 * through `finance.portal_user_map` (`bin/map-finance-portal-users.php`); a name that map cannot resolve stays
 * as text and the user id is null.
 *
 * It only plans unless `--apply` is given. A plan reads the source and the target, writes nothing anywhere, and
 * prints counts and source row ids only (never a subject, name, address, body, file name or message id): rows per
 * source table, what would be inserted, what is there already, what would be skipped and why, every problem and
 * conflict, and what would make `--apply` refuse. `--apply` writes, and is refused when:
 *
 *   - the database host is not this machine and `--approved-by NAME` is not given (the name is only printed);
 *   - neither `--translate-ids` nor `--without-message-ids` is given (or both are);
 *   - the file store would lose the files (in-memory blob store);
 *   - `outbound_messages` or `credit_note_requests` has rows (this tool does not migrate them);
 *   - the target holds a row this tool did not migrate that carries the same message id as a source record, or an
 *     option list that differs from the source's. Nothing resolves these; the owner decides.
 *
 * Safe to run again: a row the target already holds under the same id is left alone. Exits 0 when everything it
 * was asked to do is done (a plan always, once it has run), 1 when it refused or any row failed or was left out
 * for a problem, 2 for a usage error. Written for T5.15 and rehearsed on invented data only.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Access\AzureSqlFinancePortalUserMap;
use TriCab\Api\Finance\Ar\Migration\AzureSqlArMigrationTarget;
use TriCab\Api\Finance\Ar\Migration\GraphArMessageIdTranslator;
use TriCab\Api\Finance\Ar\Migration\SqliteArMigrationSource;
use TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Core\Finance\Ar\Migration\ArAttachmentFolder;
use TriCab\Core\Finance\Ar\Migration\ArMessageIdTranslationFailed;
use TriCab\Core\Finance\Ar\Migration\ArMigrationGuard;
use TriCab\Core\Finance\Ar\Migration\ArMigrationOptions;
use TriCab\Core\Finance\Ar\Migration\ArMigrationReport;
use TriCab\Core\Finance\Ar\Migration\ArMigrationRefused;
use TriCab\Core\Finance\Ar\Migration\ArMigrationUsers;
use TriCab\Core\Finance\Ar\Migration\ArMigrator;
use TriCab\Core\Product\Contract\ProductBlobStore;

ini_set('memory_limit', '512M');

$usage = "Usage: php bin/migrate-finance-ar.php --source=FILE.sqlite3 --attachments=DIR [--translate-ids | --without-message-ids] [--mailbox=ADDRESS] [--apply [--approved-by=NAME]]\n";
$options = getopt('', ['source:', 'attachments:', 'translate-ids', 'without-message-ids', 'mailbox:', 'apply', 'approved-by:']);
$sourceFile = is_string($options['source'] ?? null) ? $options['source'] : '';
$attachmentsDir = is_string($options['attachments'] ?? null) ? $options['attachments'] : '';
if ($sourceFile === '' || $attachmentsDir === '') {
    fwrite(STDERR, $usage);
    exit(2);
}
$apply = array_key_exists('apply', $options);
$translate = array_key_exists('translate-ids', $options);
$without = array_key_exists('without-message-ids', $options);
$approvedBy = is_string($options['approved-by'] ?? null) ? trim($options['approved-by']) : '';
$mailbox = is_string($options['mailbox'] ?? null) && trim($options['mailbox']) !== '' ? strtolower(trim($options['mailbox'])) : \TriCab\Core\Finance\Ar\Migration\ArRowMapper::DEFAULT_MAILBOX;
if ($translate && $without) {
    fwrite(STDERR, "--translate-ids and --without-message-ids contradict one another; give one.\n" . $usage);
    exit(2);
}

try {
    $source = new SqliteArMigrationSource($sourceFile);
    $folder = new ArAttachmentFolder($attachmentsDir);
} catch (InvalidArgumentException $invalid) {
    fwrite(STDERR, $invalid->getMessage() . "\n");
    exit(2);
}

// Everything that can refuse a run is decided before the database is touched.
Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Refused: the target is the SQL database, so this needs USER_REPOSITORY=azure-sql and the DB_* settings.\n");
    exit(1);
}
// `--translate-ids` asks Microsoft Graph (translateExchangeIds) about the mailbox, in a plan as well as an apply. It
// reads nothing from the mailbox and changes nothing there, but it is a live call: it needs the finance registration.
if ($translate && ($settings->financeGraphTenantId === '' || $settings->financeGraphClientId === '' || $settings->financeGraphClientSecret === '')) {
    fwrite(STDERR, "Refused: --translate-ids asks Microsoft Graph, so it needs FINANCE_GRAPH_TENANT_ID, FINANCE_GRAPH_CLIENT_ID and FINANCE_GRAPH_CLIENT_SECRET.\n");
    exit(1);
}
$local = ArMigrationGuard::isLocal($settings->dbHost);
if ($apply) {
    $refusal = null;
    if (!$translate && !$without) {
        $refusal = "Give --translate-ids or --without-message-ids: the records' message ids must be decided before anything is written.";
    }
    $refusal ??= ArMigrationGuard::hostRefusal($settings->dbHost, $approvedBy)
        ?? ArMigrationGuard::blobRefusal($settings->blobAuthMode === Settings::BLOB_AUTH_MODE_CONNECTION_STRING);
    if ($refusal !== null) {
        fwrite(STDERR, 'Refused: ' . $refusal . "\n");
        exit(1);
    }
}

try {
    $container = ContainerFactory::create($settings);
    $connection = $container->get(SqlConnection::class);
    $blobs = $container->get(ProductBlobStore::class);
    if (!$connection instanceof SqlConnection || !$blobs instanceof ProductBlobStore) {
        fwrite(STDERR, "The container has no database connection or blob store.\n");
        exit(1);
    }
    $migrator = new ArMigrator(
        $source,
        new AzureSqlArMigrationTarget($connection),
        $folder,
        new AzureSqlFinanceAttachmentStore($connection, $blobs, $settings->blobContainerFinance),
        new ArMigrationUsers((new AzureSqlFinancePortalUserMap($connection))->all()),
        $translate
            ? new GraphArMessageIdTranslator(new StreamJsonHttpClient(20.0, 5.0), $settings->financeGraphTenantId, $settings->financeGraphClientId, $settings->financeGraphClientSecret)
            : null,
    );
    $run = new ArMigrationOptions($apply, $translate, $without, $mailbox);
    $plan = $migrator->prepare($run);
    $report = $plan->report;
    $refused = null;
    if ($apply) {
        try {
            $migrator->apply($plan);
        } catch (ArMigrationRefused $refusal) {
            $refused = $refusal;
        }
    }
} catch (ArMessageIdTranslationFailed $failed) {
    // The translator's own messages name a status or a cause and never an id, a mailbox's content or a secret.
    fwrite(STDERR, "The message id translation service could not be used; nothing was written.\n" . $failed->getMessage() . "\n");
    exit(1);
} catch (Throwable $failure) {
    // The class only: a database or driver message can carry a value from a row.
    fwrite(STDERR, 'The tool stopped (' . (new ReflectionClass($failure))->getShortName() . "). If it was writing, rows may be partly in; run the plan to see.\n");
    exit(1);
}

print_report($report, $apply ? 'apply' : 'plan', $local, $approvedBy, $refused !== null);
exit($refused !== null || ($apply && $report->problemCount() > 0) ? 1 : 0);

/** One JSON line of header, one of counts, then one line each for what needs a person. Counts and source row ids only. */
function print_report(ArMigrationReport $report, string $mode, bool $local, string $approvedBy, bool $refused): void
{
    fwrite(STDOUT, json_encode([
        'at' => gmdate('c'),
        'mode' => $mode,
        'database' => $local ? 'local' : 'not local' . ($approvedBy !== '' ? ', approved by ' . $approvedBy : ''),
        'refused' => $refused,
    ], JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDOUT, json_encode($report->toArray(), JSON_THROW_ON_ERROR) . "\n");
    foreach ($report->blockers() as $blocker) {
        fwrite(STDOUT, ($mode === 'apply' ? 'REFUSED    ' : 'WOULD REFUSE --apply  ') . $blocker . "\n");
    }
    foreach ($report->conflicts() as $row) {
        fwrite(STDOUT, sprintf("CONFLICT   %s  %s\n", $row['table'], $row['id']));
    }
    foreach ($report->problems() as $row) {
        fwrite(STDOUT, sprintf("PROBLEM    %s  %s  %s\n", $row['table'], $row['id'], $row['kind']));
    }
}
