<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar\Migration;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use TriCab\Core\Finance\Ar\Migration\ArMigrationSource;

/**
 * The old app's SQLite file (`server/data/ar.sqlite3`), opened read-only: a path that does not exist is an error,
 * never a new empty file. Rows come in `rowid` order, which is how the source ordered them. A table the file lacks
 * is an error (a wrong file must not read as "nothing to migrate"); a column it lacks reads as absent.
 */
final class SqliteArMigrationSource implements ArMigrationSource
{
    private readonly PDO $pdo;

    public function __construct(string $file)
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException('The source database file does not exist or cannot be read.');
        }
        try {
            $this->pdo = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // The class constants moved to Pdo\Sqlite in PHP 8.4 and the old ones are deprecated from 8.5.
                self::constant('ATTR_OPEN_FLAGS', 'SQLITE_ATTR_OPEN_FLAGS') => self::constant('OPEN_READONLY', 'SQLITE_OPEN_READONLY'),
            ]);
            $names = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
            $have = $names === false ? [] : $names->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException) {
            throw new InvalidArgumentException('The source file is not a readable SQLite database.');
        }
        foreach (self::TABLES as $table) {
            if (!in_array($table, $have, true)) {
                throw new InvalidArgumentException('The source file is not a Tri-AR database: it has no ' . $table . ' table.');
            }
        }
    }

    private static function constant(string $new, string $old): int
    {
        $value = defined('Pdo\Sqlite::' . $new) ? constant('Pdo\Sqlite::' . $new) : constant('PDO::' . $old);

        return is_int($value) ? $value : 0;
    }

    public function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $count = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
            $out[$table] = $count === false ? 0 : (int) $count->fetchColumn();
        }

        return $out;
    }

    public function records(): iterable
    {
        return $this->stream('SELECT * FROM email_records ORDER BY rowid');
    }

    public function messageRefs(): array
    {
        /** @var list<array{id: mixed, mailbox: mixed, source_message_id: mixed}> $rows */
        $rows = $this->rows('SELECT id, mailbox, source_message_id FROM email_records ORDER BY rowid');

        return $rows;
    }

    public function attachmentsOf(string $recordId): array
    {
        return $this->rows('SELECT * FROM email_attachments WHERE email_record_id = ? ORDER BY rowid', [$recordId]);
    }

    public function linesOf(string $attachmentId): array
    {
        return $this->rows('SELECT * FROM remittance_lines WHERE attachment_id = ? ORDER BY rowid', [$attachmentId]);
    }

    public function scanRuns(): iterable
    {
        return $this->stream('SELECT * FROM scan_runs ORDER BY rowid');
    }

    public function optionRows(): iterable
    {
        return $this->stream('SELECT * FROM option_lists ORDER BY rowid');
    }

    public function deletedMessages(): iterable
    {
        return $this->stream('SELECT * FROM deleted_messages ORDER BY rowid');
    }

    public function orphanAttachmentIds(): array
    {
        return $this->ids('SELECT id FROM email_attachments WHERE email_record_id NOT IN (SELECT id FROM email_records) ORDER BY rowid');
    }

    public function orphanLineIds(): array
    {
        return $this->ids('SELECT id FROM remittance_lines WHERE attachment_id NOT IN (SELECT id FROM email_attachments) ORDER BY rowid');
    }

    /**
     * @param list<string> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function stream(string $sql): Generator
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return;
        }
        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            yield $row;
        }
    }

    /** @return list<string> */
    private function ids(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $ids = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $ids));
    }
}
