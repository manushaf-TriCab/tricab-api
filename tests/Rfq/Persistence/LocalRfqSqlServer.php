<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A disposable SQL Server for the RFQ store tests: a database of its own on a
 * local server (Docker), with the identity schema (people are matched by
 * email against it) and `create-rfq-schema.sql` applied.
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

    /** Empties every RFQ table and the test's people, children first. */
    public static function truncate(PDO $pdo): void
    {
        if ($pdo->query("SELECT OBJECT_ID(N'[rfq].[rfq_legacy_import_rows]', N'U')")?->fetchColumn() !== null) {
            $pdo->exec('DELETE FROM [rfq].[rfq_legacy_import_rows]');
            $pdo->exec('DELETE FROM [rfq].[rfq_legacy_import_runs]');
        }
        foreach (['rfq_documents', 'rfq_draft_rows', 'rfq_draft_activity', 'rfq_draft_row_edits', 'rfq_entries', 'rfq_reader_jobs',
            'rfq_sync_attempts', 'rfq_draft_quotes', 'rfq_sessions', 'rfq_correction_memory',
            'rfq_roles', 'rfq_user_roles', 'rfq_identities', 'rfq_notification_outbox', 'rfq_mail_audit', 'rfq_activity_log',
            'rfq_quote_relationships', 'rfq_user_accessory_preferences'] as $table) {
            $pdo->exec("DELETE FROM [rfq].[{$table}]");
        }
        $pdo->exec('DELETE FROM [identity].[UserEmailAddresses]');
        $pdo->exec('DELETE FROM [identity].[Users]');
    }

    private static function applySchema(PDO $pdo): void
    {
        foreach (['create-identity-schema.sql', 'create-identity-user-email-addresses.sql', 'create-rfq-schema.sql', 'create-rfq-documents-schema.sql', 'create-rfq-accessory-tables.sql', 'create-rfq-legacy-import-ledger.sql'] as $file) {
            $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/' . $file);
            if ($script === false) {
                throw new RuntimeException("{$file} is missing.");
            }
            foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
                if (trim($batch) !== '') {
                    $pdo->exec($batch);
                }
            }
        }
    }
}
