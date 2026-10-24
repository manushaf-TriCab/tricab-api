<?php

declare(strict_types=1);

/**
 * Copy the old app's RFQ store (tc-mysql-live / tc) into the [rfq] tables on
 * Azure SQL, row for row (plan 7.1-7.4, first load).
 *
 * The [rfq] tables are the MySQL tables in SQL Server types
 * (create-rfq-schema.sql, create-rfq-accessory-tables.sql), so nothing is
 * translated: every value is carried as MySQL returns it, ids included.
 *
 *   php bin/import-rfq-legacy-store.php                 # dry run: counts and checks, no writes
 *   php bin/import-rfq-legacy-store.php --apply         # load, verify, commit
 *   php bin/import-rfq-legacy-store.php --apply --env=../local-db/env.live --report=out.json
 *
 * Options: --env=FILE reads LEGACY_TC_* from that dotenv file instead of the
 * repo's .env; --server / --database pick the target (default tricab-mel / tc);
 * --report=FILE writes counts and per-table digests as JSON.
 *
 * Safety:
 *   - The source is read in one READ ONLY transaction WITH CONSISTENT SNAPSHOT,
 *     so all 17 tables are the same instant. Every SELECT passes the legacy
 *     read-only guard.
 *   - It refuses unless every target table exists, is EMPTY, and has the
 *     source's columns. It never deletes or overwrites.
 *   - One target transaction. Before COMMIT every row is read back and its
 *     SHA-256 compared with the source row's; any difference rolls it all back.
 *   - It calls nothing else: no parser, pricing, mail or 4js. Imported jobs,
 *     notifications and sync attempts keep their states.
 *
 * Exit codes: 1 usage or connection, 2 target refused, 3 load or verify failed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Rfq\Legacy\LegacyRfqRowHash;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

CoreAutoload::register();

$options = getopt('', ['apply', 'env:', 'server:', 'database:', 'report:']);
$apply = isset($options['apply']);
$server = (string) ($options['server'] ?? 'tricab-mel.database.windows.net');
$database = (string) ($options['database'] ?? 'tc');
$reportPath = isset($options['report']) ? (string) $options['report'] : null;

// Parents before children, for the four foreign keys.
const RFQ_TABLES = [
    'rfq_roles' => ['role'],
    'rfq_user_roles' => ['email'],
    'rfq_identities' => ['email'],
    'rfq_draft_quotes' => ['id'],
    'rfq_draft_rows' => ['id'],
    'rfq_draft_activity' => ['id'],
    'rfq_draft_row_edits' => ['id'],
    'rfq_notification_outbox' => ['id'],
    'rfq_reader_jobs' => ['id'],
    'rfq_sync_attempts' => ['id'],
    'rfq_sessions' => ['id'],
    'rfq_entries' => ['id'],
    'rfq_correction_memory' => ['normalized_text', 'product_code'],
    'rfq_mail_audit' => ['internet_message_id'],
    'rfq_activity_log' => ['id'],
    'rfq_quote_relationships' => ['id'],
    'rfq_user_accessory_preferences' => ['user_email'],
];
const PAGE_ROWS = 500;

$fail = static function (int $code, string $message): never {
    fwrite(STDERR, $message . "\n");
    exit($code);
};

// ---- Source: MySQL, one read-only consistent snapshot ---------------------

if (isset($options['env'])) {
    $envFile = (string) $options['env'];
    if (!is_readable($envFile)) {
        $fail(1, "Cannot read --env file {$envFile}.");
    }
    $env = Dotenv::parse((string) file_get_contents($envFile));
} else {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $env = array_merge($_ENV, $_SERVER);
}
$config = LiveLegacySourceConfig::fromEnvironment($env);
if (!$config->tcConfigured()) {
    $fail(1, 'LEGACY_TC_* is not configured.');
}

try {
    // As ReadOnlyMysqlConnectionFactory, which cannot open a snapshot: its guard
    // refuses START. The two session statements below are the only unguarded ones.
    $mysql = new PDO((string) $config->tcDsn, (string) $config->tcUser, (string) $config->tcPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
        \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
        \Pdo\Mysql::ATTR_SSL_CA => '',
        \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
    $mysql->exec("SET NAMES utf8mb4");
    $mysql->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $mysql->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
} catch (Throwable $exception) {
    $fail(1, 'Source connection failed: ' . $exception->getMessage());
}
$snapshotAt = gmdate('Y-m-d\TH:i:s\Z');
$guard = new LegacyReadOnlySqlGuard();

/**
 * @param array<int|string, scalar|null> $params
 * @return list<array<string, mixed>>
 */
$sourceQuery = static function (string $sql, array $params = []) use ($mysql, $guard): array {
    $guard->assertReadOnly($sql);
    $statement = $mysql->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
};

// ---- Target: Azure SQL, as bin/run-azure-sql-script.php connects ----------

$tokenJson = shell_exec('az account get-access-token --resource https://database.windows.net/ --output json 2>/dev/null');
$token = is_string($tokenJson) ? json_decode($tokenJson, true) : null;
if (!is_array($token) || !is_string($token['accessToken'] ?? null)) {
    $fail(1, 'Could not get an Azure SQL access token. Is `az login` current?');
}
try {
    $sql = new PDO(sprintf(
        'sqlsrv:server = %s; Database = %s; AccessToken = %s; Encrypt = yes; TrustServerCertificate = no; LoginTimeout = 60',
        $server,
        $database,
        $token['accessToken'],
    ), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
    ]);
} catch (Throwable $exception) {
    // Never print the DSN: it carries the token.
    $fail(1, 'Target connection failed: ' . $exception->getMessage());
}
if ((int) $sql->query("SELECT CASE WHEN OBJECT_ID(N'[identity].[Users]', N'U') IS NULL THEN 0 ELSE 1 END")->fetchColumn() !== 1) {
    $fail(2, "REFUSING: [identity].[Users] is not on {$server}/{$database}. Wrong server?");
}

// ---- Shape and emptiness checks ---------------------------------------------

/** @var array<string, list<array{name: string, kind: string}>> $columns */
$columns = [];
$problems = [];
$sourceCounts = [];
foreach (array_keys(RFQ_TABLES) as $table) {
    $source = $sourceQuery(
        'SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$table],
    );
    $target = $sql->prepare(
        "SELECT c.name, t.name AS type FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id
         WHERE c.object_id = OBJECT_ID(?, N'U') ORDER BY c.column_id",
    );
    $target->execute(["[rfq].[{$table}]"]);
    $targetColumns = $target->fetchAll();

    if ($source === []) {
        $problems[] = "{$table}: missing in the source";
        continue;
    }
    if ($targetColumns === []) {
        $problems[] = "{$table}: missing in the target";
        continue;
    }
    $sourceNames = array_column($source, 'name');
    $targetNames = array_column($targetColumns, 'name');
    $sortedSource = $sourceNames;
    $sortedTarget = $targetNames;
    sort($sortedSource);
    sort($sortedTarget);
    if ($sortedSource !== $sortedTarget) {
        $problems[] = sprintf(
            '%s: columns differ (source only: %s; target only: %s)',
            $table,
            implode(', ', array_diff($sourceNames, $targetNames)) ?: '-',
            implode(', ', array_diff($targetNames, $sourceNames)) ?: '-',
        );
        continue;
    }
    $targetTypes = array_column($targetColumns, 'type', 'name');
    $columns[$table] = LegacyRfqRowHash::kinds(array_map(
        static fn (string $name): array => ['name' => $name, 'type' => (string) $targetTypes[$name]],
        $sourceNames,
    ));

    $targetRows = (int) $sql->query("SELECT COUNT_BIG(*) FROM [rfq].[{$table}]")->fetchColumn();
    if ($targetRows !== 0) {
        $problems[] = "{$table}: target holds {$targetRows} rows; the import only loads empty tables";
    }
    $sourceCounts[$table] = (int) $sourceQuery("SELECT COUNT(*) AS n FROM `{$table}`")[0]['n'];
}

$sourceHost = preg_match('/host=([^;]+)/', (string) $config->tcDsn, $match) === 1 ? $match[1] : 'tc';
printf("source    %s (snapshot %s)\ntarget    %s/%s\n\n", $sourceHost, $snapshotAt, $server, $database);
foreach ($sourceCounts as $table => $count) {
    printf("  %-32s %7d rows\n", $table, $count);
}
printf("  %-32s %7d rows\n\n", 'total', array_sum($sourceCounts));

if ($problems !== []) {
    $fail(2, "REFUSING:\n  - " . implode("\n  - ", $problems));
}
if (!$apply) {
    print("DRY RUN. Target tables exist, are empty and match the source. Nothing written. Re-run with --apply.\n");
    exit(0);
}

// ---- Load ---------------------------------------------------------------------

// Row identity and content hashing: LegacyRfqRowHash, shared with bin/sync-rfq-legacy-store.php.
$rowKey = LegacyRfqRowHash::key(...);
$rowHash = LegacyRfqRowHash::row(...);

$report = ['source' => 'tc-mysql-live/tc', 'snapshotAt' => $snapshotAt, 'target' => "{$server}/{$database}", 'tables' => []];
$started = microtime(true);

$sql->beginTransaction();
try {
    foreach (RFQ_TABLES as $table => $pk) {
        $cols = $columns[$table];
        $names = array_column($cols, 'name');
        $sourceHashes = [];
        $perStatement = max(1, min(PAGE_ROWS, intdiv(2000, count($names))));
        $columnList = '[' . implode('], [', $names) . ']';
        $mysqlList = '`' . implode('`, `', $names) . '`';
        $orderBy = '`' . implode('`, `', $pk) . '`';

        // Keyset paging on a one-column key; the one two-column key
        // (correction memory, ~500 rows) is read whole.
        $after = null;
        do {
            if (count($pk) === 1) {
                $rows = $sourceQuery(
                    "SELECT {$mysqlList} FROM `{$table}`" . ($after === null ? '' : " WHERE `{$pk[0]}` > ?")
                        . " ORDER BY {$orderBy} LIMIT " . PAGE_ROWS,
                    $after === null ? [] : [$after],
                );
                $after = $rows === [] ? null : $rows[array_key_last($rows)][$pk[0]];
                $more = count($rows) === PAGE_ROWS;
            } else {
                $rows = $sourceQuery("SELECT {$mysqlList} FROM `{$table}` ORDER BY {$orderBy}");
                $more = false;
            }

            foreach (array_chunk($rows, $perStatement) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '(' . implode(', ', array_fill(0, count($names), '?')) . ')'));
                $insert = $sql->prepare("INSERT INTO [rfq].[{$table}] ({$columnList}) VALUES {$placeholders}");
                $position = 1;
                foreach ($chunk as $row) {
                    foreach ($names as $name) {
                        $value = $row[$name];
                        $insert->bindValue($position++, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    }
                    $key = $rowKey($row, $pk);
                    if (isset($sourceHashes[$key])) {
                        throw new RuntimeException("{$table}: two source rows share the key {$key}");
                    }
                    $sourceHashes[$key] = $rowHash($row, $cols);
                }
                $insert->execute();
            }
        } while ($more);

        // Read every row back inside the transaction and compare.
        $targetHashes = [];
        $readBack = $sql->query("SELECT {$columnList} FROM [rfq].[{$table}]");
        while (($row = $readBack->fetch()) !== false) {
            $targetHashes[$rowKey($row, $pk)] = $rowHash($row, $cols);
        }
        $readBack->closeCursor();

        $missing = array_diff_key($sourceHashes, $targetHashes);
        $extra = array_diff_key($targetHashes, $sourceHashes);
        $changed = array_keys(array_diff_assoc(array_intersect_key($sourceHashes, $targetHashes), $targetHashes));
        if ($missing !== [] || $extra !== [] || $changed !== []) {
            throw new RuntimeException(sprintf(
                '%s: verify failed (%d missing, %d extra, %d differ; first: %s)',
                $table,
                count($missing),
                count($extra),
                count($changed),
                (string) (array_key_first($missing) ?? array_key_first($extra) ?? $changed[0]),
            ));
        }

        $report['tables'][$table] = [
            'rows' => count($sourceHashes),
            'sha256' => LegacyRfqRowHash::digest($sourceHashes),
        ];
        printf("  %-32s %7d rows loaded, every row verified\n", $table, count($sourceHashes));
    }

    $sql->commit();
} catch (Throwable $exception) {
    if ($sql->inTransaction()) {
        $sql->rollBack();
    }
    $mysql->exec('ROLLBACK');
    $fail(3, "\nFAILED, nothing committed: " . $exception->getMessage());
}
$mysql->exec('ROLLBACK');

$report['totalRows'] = array_sum(array_column($report['tables'], 'rows'));
$report['seconds'] = round(microtime(true) - $started, 1);
printf("\nCommitted %d rows in %.1f s.\n", $report['totalRows'], $report['seconds']);
if ($reportPath !== null) {
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    print("Report: {$reportPath}\n");
}
