<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOException;
use RuntimeException;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;

/**
 * A disposable local SQL Server database holding both the `[identity]` schema (with the finance and Tri-AP permission and
 * role seeds) and the finance tables, for running the Tri-AP sample seed for real (T6.12). A database of its own on a LOCAL
 * server, never `tc` and never anything shared; it refuses any host but this machine, as {@see LocalFinanceSqlServer}
 * does. Skipped without the same two environment variables. Its own database name, so it never meets the Tri-AR twin's
 * (`finance_ar_sample_test`). The Tri-Task and Tri-AR tables are made too, only because {@see LocalFinanceSqlServer::truncate()}
 * empties every finance table.
 */
final class LocalApSampleServer
{
    public const DATABASE = 'finance_ap_sample_test';

    private const SCRIPTS = [
        'create-identity-schema.sql',
        'seed-finance-permissions.sql',
        'seed-finance-roles.sql',
        'seed-finance-ap-permissions.sql',
        'seed-finance-ap-roles.sql',
        'create-finance-schema.sql',
        'create-finance-jobs-schema.sql',
        'create-finance-attachments-schema.sql',
        'create-finance-access-schema.sql',
        'create-finance-odoo-schema.sql',
        'create-finance-task-schema.sql',
        'alter-finance-task-board.sql',
        'alter-finance-task-project-status.sql',
        'alter-finance-task-simple-status.sql',
        'alter-finance-task-project-groups.sql',
        'alter-finance-task-order.sql',
        'alter-finance-task-history-fields.sql',
        'alter-finance-task-assignees.sql',
        'alter-finance-task-user-state.sql',
        'alter-finance-task-calendar-invites.sql',
        'alter-finance-task-time-spent.sql',
        'create-finance-ar-schema.sql',
        'create-finance-invoice-schema.sql',
        'alter-finance-mail-status-suppressed.sql',
    ];

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
            $master = LocalFinanceSqlServer::open($server, 'master', $user, $password);
            $master->exec("IF DB_ID(N'" . self::DATABASE . "') IS NULL CREATE DATABASE [" . self::DATABASE . '] COLLATE SQL_Latin1_General_CP1_CI_AS');
            $pdo = LocalFinanceSqlServer::open($server, self::DATABASE, $user, $password);
        } catch (PDOException) {
            return null;
        }
        foreach (self::SCRIPTS as $file) {
            self::run($pdo, $file);
        }

        return $pdo;
    }

    /** Runs one of the development scripts, batch by batch (`GO` separates them). */
    public static function run(PDO $pdo, string $file): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/' . $file);
        if ($script === false) {
            throw new RuntimeException($file . ' is missing.');
        }
        foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
            if (trim($batch) !== '') {
                $pdo->exec($batch);
            }
        }
    }

    /** Empties the sample's tables and its people; the schema and the seeded permissions and roles stay. */
    public static function reset(PDO $pdo): void
    {
        LocalFinanceSqlServer::truncate($pdo);
        $pdo->exec('DELETE FROM [identity].[UserRoles]');
        $pdo->exec('DELETE FROM [identity].[Users]');
    }
}
