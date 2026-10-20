<?php

declare(strict_types=1);

/**
 * Keep the platform's copy of the old app's RFQ store current until cutover
 * (RFQ plan 7.2-7.4). See {@see \TriCab\Api\Rfq\Legacy\LegacyRfqStoreSync}
 * for the rule: tc-web's changes come across, the platform's work stays, and
 * where both changed an RFQ tc-web wins and the run says so.
 *
 *   php bin/sync-rfq-legacy-store.php                       # dry run: what a refresh would do
 *   php bin/sync-rfq-legacy-store.php --apply               # refresh
 *   php bin/sync-rfq-legacy-store.php --adopt=REPORT.json   # once: start the ledger from the first import
 *   php bin/sync-rfq-legacy-store.php --adopt=REPORT.json --apply
 *
 * Options: --env=FILE reads settings (DB_*, LEGACY_TC_*) from that dotenv file
 * instead of the repo's .env; --if-enabled does nothing (exit 3) unless
 * RFQ_LEGACY_SYNC_ENABLED=true, for the `rfq-legacy-sync` WebJob.
 *
 * The target is the API's own database connection (managed identity on App
 * Service, the Azure CLI locally); the source is LEGACY_TC_*, read-only. It
 * never calls the parser, pricing, mail or 4js.
 *
 * Prints one JSON line. Exit codes: 0 done (or nothing to do), 1 failure,
 * 2 refused (no ledger, changed tables, rows no longer the import), 3
 * switched off, 5 another refresh holds the lock, 75 the database is resuming.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Rfq\Legacy\LegacyRfqStoreSync;
use TriCab\Api\Rfq\Legacy\LegacyRfqSyncBusy;
use TriCab\Api\Rfq\Legacy\LegacyRfqSyncRefused;
use TriCab\Api\Rfq\Legacy\MysqlLegacyRfqSource;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

CoreAutoload::register();

$options = getopt('', ['apply', 'adopt:', 'env:', 'if-enabled']);
$apply = isset($options['apply']);

$say = static function (array $line): void {
    fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
};

if (isset($options['env'])) {
    $file = (string) $options['env'];
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read --env file {$file}.\n");
        exit(1);
    }
    $env = array_merge($_SERVER, $_ENV, Dotenv::parse((string) file_get_contents($file)));
} else {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $env = array_merge($_ENV, $_SERVER);
}

if (isset($options['if-enabled']) && strtolower(trim((string) ($env['RFQ_LEGACY_SYNC_ENABLED'] ?? ''))) !== 'true') {
    exit(3);
}

$legacy = LiveLegacySourceConfig::fromEnvironment($env);
if (!isset($options['adopt']) && !$legacy->tcConfigured()) {
    fwrite(STDERR, "LEGACY_TC_* is not configured.\n");
    exit(1);
}

$source = null;
try {
    $settings = Settings::fromEnvironment($env);
    $factory = ContainerFactory::create($settings)->get(AzureSqlPdoFactory::class);
    if (!$factory instanceof AzureSqlPdoFactory) {
        throw new RuntimeException('The container has no Azure SQL connection.');
    }
    $pdo = $factory->create();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8);
    $identity = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'[identity].[Users]', N'U') IS NULL THEN 0 ELSE 1 END");
    if ($identity === false || (int) $identity->fetchColumn() !== 1) {
        throw new LegacyRfqSyncRefused('[identity].[Users] is not on this database. Wrong server?');
    }
    $sync = new LegacyRfqStoreSync($pdo);

    if (isset($options['adopt'])) {
        $report = json_decode((string) @file_get_contents((string) $options['adopt']), true);
        if (!is_array($report)) {
            throw new LegacyRfqSyncRefused('Cannot read the import report ' . $options['adopt'] . '.');
        }
        $say($sync->adopt($report, $apply));
        exit(0);
    }

    $source = MysqlLegacyRfqSource::open($legacy);
    $result = $sync->refresh($source, $apply);
    $say($result);
    exit(0);
} catch (LegacyRfqSyncRefused $e) {
    $say(['refused' => $e->getMessage()]);
    exit(2);
} catch (LegacyRfqSyncBusy $e) {
    $say(['busy' => $e->getMessage()]);
    exit(5);
} catch (DatabaseTemporarilyUnavailable) {
    $say(['resuming' => 'The database is resuming; the next pass retries.']);
    exit(75);
} catch (Throwable $e) {
    // Never the DSN: it can carry a token.
    $say(['failed' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
} finally {
    $source?->close();
}
