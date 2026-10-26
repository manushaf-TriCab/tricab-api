<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2ExportStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Export;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** `finance.ap2_exports`: ids are `CSV-0001`... and the CSV is kept as it was made. */
final class AzureSqlAp2ExportStore extends AzureSqlAp2Support implements Ap2ExportStore
{
    private const COLUMNS = '[id], [invoice_ids], [headers], [rows], [csv], [created_at], [created_by_user_id], [created_by_name], [created_by_email]';

    public function nextId(): string
    {
        $rows = $this->connection->fetchAll(
            'SELECT ISNULL(MAX(CAST(SUBSTRING([id], 5, 10) AS INT)), 0) AS [n] FROM [finance].[ap2_exports] WITH (UPDLOCK, HOLDLOCK)',
        );

        return 'CSV-' . str_pad((string) (self::int($rows[0]['n'] ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function insert(Ap2Export $x): void
    {
        $this->write(
            'INSERT INTO [finance].[ap2_exports] (' . self::COLUMNS . ') VALUES (:id, :invoice_ids, :headers, :rows, :csv, :created_at, :user, :name, :email)',
            [
                'id' => $x->id,
                'invoice_ids' => json_encode($x->invoiceIds, JSON_THROW_ON_ERROR),
                'headers' => json_encode($x->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'rows' => json_encode($x->rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'csv' => $x->csv,
                'created_at' => self::at($x->createdAt),
                'user' => $x->createdBy->userId,
                'name' => mb_substr($x->createdBy->name, 0, 200),
                'email' => mb_substr($x->createdBy->email, 0, 320),
            ],
            'The export id is already used',
        );
    }

    public function find(string $id): ?Ap2Export
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_exports] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::export($rows[0]);
    }

    public function all(): array
    {
        return array_map(self::export(...), $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_exports] ORDER BY [id] DESC'));
    }

    /** @param array<string, mixed> $row */
    private static function export(array $row): Ap2Export
    {
        $ids = json_decode(self::text($row['invoice_ids']), true);
        $headers = json_decode(self::text($row['headers']), true);
        $data = json_decode(self::text($row['rows']), true);

        return new Ap2Export(
            self::text($row['id']),
            is_array($ids) ? array_values(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $ids)) : [],
            is_array($headers) ? array_values(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $headers)) : [],
            self::rows($data),
            self::text($row['csv']),
            self::when($row['created_at'], 'created_at'),
            new Ap2Person(self::int($row['created_by_user_id']), self::text($row['created_by_name']), self::text($row['created_by_email'])),
        );
    }

    /** @return list<list<string|int|float|null>> */
    private static function rows(mixed $data): array
    {
        $out = [];
        foreach (is_array($data) ? $data : [] as $line) {
            $cells = [];
            foreach (is_array($line) ? $line : [] as $cell) {
                $cells[] = is_string($cell) || is_int($cell) || is_float($cell) ? $cell : null;
            }
            $out[] = $cells;
        }

        return $out;
    }
}
