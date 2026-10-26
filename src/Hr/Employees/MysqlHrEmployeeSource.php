<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

use PDO;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

/**
 * tc-mysql-live / tc, read in one `READ ONLY` transaction `WITH CONSISTENT
 * SNAPSHOT`. Every query goes through {@see ReadOnlyLegacyQuery}; the two
 * session statements that open the snapshot are the only unguarded ones (the
 * guard refuses START).
 */
final class MysqlHrEmployeeSource implements HrEmployeeSource
{
    private const CHUNK = 200;

    private function __construct(private readonly PDO $pdo, private readonly ReadOnlyLegacyQuery $query)
    {
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }

    public static function open(LiveLegacySourceConfig $config): self
    {
        // As MysqlLegacyRfqSource: ReadOnlyMysqlConnectionFactory cannot hand over a raw PDO.
        $pdo = new PDO((string) $config->tcDsn, (string) $config->tcUser, (string) $config->tcPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            \Pdo\Mysql::ATTR_MULTI_STATEMENTS => false,
            \Pdo\Mysql::ATTR_SSL_CA => '',
            \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]);

        return new self($pdo, new ReadOnlyLegacyQuery($pdo));
    }

    public function employees(): iterable
    {
        $columns = '`' . implode('`, `', EmployeeMapper::COLUMNS) . '`';
        $after = null;
        do {
            $rows = $this->query->select(
                "SELECT {$columns} FROM `employee`" . ($after === null ? '' : ' WHERE `employee_code` > ?') . ' ORDER BY `employee_code` LIMIT ' . self::CHUNK,
                $after === null ? [] : [$after],
            );
            yield from $rows;
            $after = $rows === [] ? null : (string) EmployeeMapper::text($rows[array_key_last($rows)]['employee_code'] ?? null);
        } while (count($rows) === self::CHUNK && $after !== '');
    }

    public function departmentManagers(): array
    {
        return $this->query->select('SELECT `department`, `manager`, `alt_manager` FROM `dept_manager` ORDER BY `department`');
    }

    public function close(): void
    {
        $this->pdo->exec('ROLLBACK');
    }
}
