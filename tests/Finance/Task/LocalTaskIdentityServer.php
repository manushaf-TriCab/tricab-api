<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PDO;
use PDOException;
use RuntimeException;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;

/**
 * A disposable local SQL Server database with the `[identity]` schema, Tri-Task's tables and
 * the finance and Tri-Task seeds applied, for the member directory's tests (T2.5). A database
 * of its own on a LOCAL server, never `tc` and never anything shared; it refuses any host but
 * this machine, as {@see LocalFinanceSqlServer} does. Skipped without the same two
 * environment variables.
 */
final class LocalTaskIdentityServer
{
    public const DATABASE = 'finance_task_identity_test';

    private const SCRIPTS = [
        'create-identity-schema.sql',
        'create-finance-task-schema.sql',
        'alter-finance-task-board.sql',
        'alter-finance-task-project-status.sql',
        'alter-finance-task-simple-status.sql',
        'seed-finance-permissions.sql',
        'seed-finance-roles.sql',
        'seed-finance-task-permissions.sql',
        'seed-finance-task-roles.sql',
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

        return $pdo;
    }

    /** Empties the people and Tri-Task's rows; the seeded permissions and roles stay. */
    public static function reset(PDO $pdo): void
    {
        foreach (['task_member_profiles', 'task_report_recipients'] as $table) {
            $pdo->exec("DELETE FROM [finance].[{$table}]");
        }
        $pdo->exec('DELETE FROM [identity].[UserRoles]');
        $pdo->exec('DELETE FROM [identity].[Users]');
    }
}
