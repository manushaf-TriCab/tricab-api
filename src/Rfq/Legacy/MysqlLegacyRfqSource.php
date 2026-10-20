<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

/**
 * tc-mysql-live / tc, read in one `READ ONLY` transaction `WITH CONSISTENT
 * SNAPSHOT`, so every table is the same instant. Every SELECT passes the
 * legacy read-only guard; the two session statements that open the snapshot
 * are the only unguarded ones (the guard refuses START).
 */
final class MysqlLegacyRfqSource implements LegacyRfqSource
{
    private const PAGE = 500;
    private const KEY_BATCH = 200;

    private readonly LegacyReadOnlySqlGuard $guard;
    private readonly DateTimeImmutable $snapshotAt;

    private function __construct(private readonly PDO $pdo)
    {
        $this->guard = new LegacyReadOnlySqlGuard();
        $this->pdo->exec('SET NAMES utf8mb4');
        $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $this->snapshotAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function open(LiveLegacySourceConfig $config): self
    {
        // As ReadOnlyMysqlConnectionFactory, which cannot hand over a raw PDO.
        return new self(new PDO((string) $config->tcDsn, (string) $config->tcUser, (string) $config->tcPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            PDO::MYSQL_ATTR_SSL_CA => '',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ]));
    }

    public function snapshotAt(): DateTimeImmutable
    {
        return $this->snapshotAt;
    }

    public function close(): void
    {
        $this->pdo->exec('ROLLBACK');
    }

    public function columns(string $table): array
    {
        return array_map(static fn (array $r): string => LegacyRfqRowHash::text($r['name'] ?? ''), $this->query(
            'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        ));
    }

    public function rows(string $table, array $columns, array $pk): iterable
    {
        $list = self::list($columns);
        $order = self::list($pk);
        if (count($pk) !== 1) {
            // The one two-column key (correction memory, ~500 rows) is read whole.
            yield from $this->query("SELECT {$list} FROM `{$table}` ORDER BY {$order}");

            return;
        }
        $after = null;
        do {
            $rows = $this->query(
                "SELECT {$list} FROM `{$table}`" . ($after === null ? '' : " WHERE `{$pk[0]}` > ?") . " ORDER BY {$order} LIMIT " . self::PAGE,
                $after === null ? [] : [$after],
            );
            yield from $rows;
            $after = $rows === [] ? null : LegacyRfqRowHash::text($rows[array_key_last($rows)][$pk[0]] ?? '');
        } while (count($rows) === self::PAGE);
    }

    public function rowsByKeys(string $table, array $columns, array $pk, array $keys): array
    {
        $found = [];
        foreach (array_chunk($keys, self::KEY_BATCH) as $batch) {
            $match = '(' . implode(' AND ', array_map(static fn (string $c): string => "`{$c}` = ?", $pk)) . ')';
            array_push($found, ...$this->query(
                'SELECT ' . self::list($columns) . " FROM `{$table}` WHERE " . implode(' OR ', array_fill(0, count($batch), $match)),
                array_merge(...$batch),
            ));
        }

        return $found;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        $this->guard->assertReadOnly($sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (is_array($row)) {
                $named = [];
                foreach ($row as $column => $value) {
                    $named[(string) $column] = $value;
                }
                $rows[] = $named;
            }
        }

        return $rows;
    }

    /** @param list<string> $columns */
    private static function list(array $columns): string
    {
        return '`' . implode('`, `', $columns) . '`';
    }
}
