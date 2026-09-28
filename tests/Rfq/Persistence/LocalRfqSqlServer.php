<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A disposable SQL Server for the RFQ store tests: a database of its own on a
 * local server (Docker), with `create-rfq-schema.sql` applied.
 *
 * Local rather than the shared `tc`, because these tests need what a
 * rolled-back transaction on a shared database cannot give: two sessions
 * racing for the same draft, and committed rows to read back.
 *
 *     docker run -d --name rfq-mssql --platform linux/amd64 -e ACCEPT_EULA=Y \
 *         -e MSSQL_SA_PASSWORD=… -e MSSQL_COLLATION=SQL_Latin1_General_CP1_CI_AS \
 *         -p 14333:1433 mcr.microsoft.com/mssql/server:2022-latest
 *     RFQ_TEST_SQLSERVER=127.0.0.1,14333 RFQ_TEST_SQLSERVER_PASSWORD=… vendor/bin/phpunit tests/Rfq/Persistence
 *
 * Unset, the tests skip. The collation matches `tricab-mel`'s.
 */
final class LocalRfqSqlServer
{
    public const DATABASE = 'rfq_store_test';

    public static function connect(): ?PDO
    {
        $server = getenv('RFQ_TEST_SQLSERVER') ?: '';
        $password = getenv('RFQ_TEST_SQLSERVER_PASSWORD') ?: '';
        if ($server === '' || $password === '') {
            return null;
        }
        $user = getenv('RFQ_TEST_SQLSERVER_USER') ?: 'sa';
        try {
            $master = self::open($server, 'master', $user, $password);
            $master->exec("IF DB_ID(N'" . self::DATABASE . "') IS NULL CREATE DATABASE [" . self::DATABASE . '] COLLATE SQL_Latin1_General_CP1_CI_AS');
            $pdo = self::open($server, self::DATABASE, $user, $password);
        } catch (PDOException) {
            return null;
        }
        self::applySchema($pdo);

        return $pdo;
    }

    public static function open(string $server, string $database, string $user, string $password): PDO
    {
        return new PDO(
            "sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=yes;LoginTimeout=5",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8],
        );
    }

    /** Empties every RFQ table, children first. */
    public static function truncate(PDO $pdo): void
    {
        foreach (['rfq_draft_rows', 'rfq_draft_activity', 'rfq_draft_row_edits', 'rfq_entries', 'rfq_draft_quotes', 'rfq_sessions'] as $table) {
            $pdo->exec("DELETE FROM [rfq].[{$table}]");
        }
    }

    private static function applySchema(PDO $pdo): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/create-rfq-schema.sql');
        if ($script === false) {
            throw new RuntimeException('create-rfq-schema.sql is missing.');
        }
        foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
            if (trim($batch) !== '') {
                $pdo->exec($batch);
            }
        }
    }
}
