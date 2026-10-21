<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A disposable SQL Server for the finance repository tests: a database of its own
 * on a LOCAL server (Docker), with the finance schema scripts (shared tables, jobs, attachments, access, Odoo, Tri-Task and Tri-AR) applied. It is never
 * the shared `tc` database, and it refuses any server that is not this machine.
 *
 *     FINANCE_TEST_SQLSERVER=localhost,1433 FINANCE_TEST_SQLSERVER_PASSWORD=… \
 *         php vendor/bin/phpunit --filter Finance
 *
 * Unset, the tests skip. The collation matches `tricab-mel`'s.
 */
final class LocalFinanceSqlServer
{
    public const DATABASE = 'finance_store_test';

    public static function connect(): ?PDO
    {
        $server = getenv('FINANCE_TEST_SQLSERVER') ?: '';
        $password = getenv('FINANCE_TEST_SQLSERVER_PASSWORD') ?: '';
        if ($server === '' || $password === '') {
            return null;
        }
        $host = strtolower(trim(explode(',', $server)[0]));
        if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('The finance repository tests only run against a local SQL Server, not ' . $host . '.');
        }
        $user = getenv('FINANCE_TEST_SQLSERVER_USER') ?: 'sa';
        try {
            $master = self::open($server, 'master', $user, $password);
            $database = self::database();
            $master->exec("IF DB_ID(N'" . $database . "') IS NULL CREATE DATABASE [" . $database . '] COLLATE SQL_Latin1_General_CP1_CI_AS');
            $pdo = self::open($server, $database, $user, $password);
        } catch (PDOException) {
            return null;
        }
        self::applySchema($pdo);

        return $pdo;
    }

    /**
     * `finance_store_test`, or the name in FINANCE_TEST_SQLSERVER_DATABASE: two checkouts running these tests at once
     * need a database each, since every test empties the tables.
     */
    public static function database(): string
    {
        $name = getenv('FINANCE_TEST_SQLSERVER_DATABASE') ?: '';

        return preg_match('/^finance_[a-z0-9_]{1,40}$/', $name) === 1 ? $name : self::DATABASE;
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

    /** Empties every finance table, children first. */
    public static function truncate(PDO $pdo): void
    {
        foreach (['invoice_audit_events', 'invoice_outbound_messages', 'invoice_approval_decisions', 'invoice_approval_items', 'invoice_approval_batches', 'invoice_scan_skips', 'invoice_email_attachments', 'invoice_invoices', 'invoice_emails', 'invoice_scan_runs', 'invoice_signing_limits', 'invoice_account_categories', 'invoice_cost_centres', 'invoice_companies', 'ar_remittance_lines', 'ar_email_attachments', 'ar_outbound_messages', 'ar_email_records', 'ar_credit_note_requests', 'ar_scan_runs', 'ar_option_lists', 'ar_deleted_messages', 'task_mail_links', 'task_report_recipients', 'task_member_profiles', 'task_history', 'task_dependencies', 'task_tasks', 'task_groups', 'task_project_templates', 'task_project_groups', 'task_templates', 'task_statement_areas', 'task_projects', 'task_entities', 'odoo_sync_runs', 'odoo_records', 'audit_events', 'job_runs', 'attachments', 'access_scopes', 'portal_user_map', 'mail_deliveries', 'mailbox_grants', 'mailboxes'] as $table) {
            $pdo->exec("DELETE FROM [finance].[{$table}]");
        }
    }

    private static function applySchema(PDO $pdo): void
    {
        foreach (['create-finance-schema.sql', 'create-finance-jobs-schema.sql', 'create-finance-attachments-schema.sql', 'create-finance-access-schema.sql', 'create-finance-odoo-schema.sql', 'create-finance-task-schema.sql', 'alter-finance-task-board.sql', 'alter-finance-task-project-status.sql', 'alter-finance-task-simple-status.sql', 'alter-finance-task-project-groups.sql', 'alter-finance-task-order.sql', 'alter-finance-task-history-fields.sql', 'create-finance-ar-schema.sql', 'create-finance-invoice-schema.sql', 'alter-finance-mail-status-suppressed.sql'] as $file) {
            $script = file_get_contents(dirname(__DIR__, 2) . '/database/scripts/development/' . $file);
            if ($script === false) {
                throw new RuntimeException($file . ' is missing.');
            }
            foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
                if (trim($batch) !== '') {
                    $pdo->exec($batch);
                }
            }
        }
    }
}
