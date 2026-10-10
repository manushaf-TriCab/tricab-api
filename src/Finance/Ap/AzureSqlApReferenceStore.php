<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;

/**
 * The Settings lists in `finance.invoice_companies`, `invoice_cost_centres` and `invoice_account_categories`
 * (`create-finance-invoice-schema.sql`). The table and column names come from {@see ApReferenceSpec},
 * never from a request. A duplicate of the unique column is an `ApConflict`.
 */
final class AzureSqlApReferenceStore extends AzureSqlApSupport implements ApReferenceStore
{
    public function rows(ApReferenceSpec $spec, string $sortColumn, bool $descending, int $limit): array
    {
        if (!in_array($sortColumn, $spec->sortable(), true)) {
            $sortColumn = 'created_date';
        }
        $rows = $this->connection->fetchAll(
            'SELECT TOP (' . max(1, $limit) . ') ' . self::columns($spec) . ' FROM [finance].[' . $spec->table . ']'
            . ' ORDER BY [' . $sortColumn . '] ' . ($descending ? 'DESC' : 'ASC') . ', [id]',
        );

        return array_map(fn (array $row): ApReferenceRow => self::row($spec, $row), $rows);
    }

    public function find(ApReferenceSpec $spec, string $id): ?ApReferenceRow
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::columns($spec) . ' FROM [finance].[' . $spec->table . '] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows === [] ? null : self::row($spec, $rows[0]);
    }

    public function insert(ApReferenceRow $row): void
    {
        $spec = ApReferenceSpec::for($row->list);
        $names = array_keys($spec->fields);
        $params = ['id' => $row->id, 'active' => $row->active ? 1 : 0, 'created' => self::at($row->createdAt)];
        foreach ($names as $name) {
            $params['v_' . $name] = $row->values[$name] ?? '';
        }
        $this->write(
            'INSERT INTO [finance].[' . $spec->table . '] ([id], ' . implode(', ', array_map(static fn (string $n): string => '[' . $n . ']', $names))
            . ', [is_active], [created_date]) VALUES (:id, ' . implode(', ', array_map(static fn (string $n): string => ':v_' . $n, $names)) . ', :active, :created)',
            $params,
            $spec->conflictMessage(),
        );
    }

    public function update(ApReferenceRow $row): void
    {
        $spec = ApReferenceSpec::for($row->list);
        $sets = [];
        $params = ['id' => $row->id, 'active' => $row->active ? 1 : 0];
        foreach (array_keys($spec->fields) as $name) {
            $sets[] = '[' . $name . '] = :v_' . $name;
            $params['v_' . $name] = $row->values[$name] ?? '';
        }
        $this->write(
            'UPDATE [finance].[' . $spec->table . '] SET ' . implode(', ', $sets) . ', [is_active] = :active WHERE [id] = :id',
            $params,
            $spec->conflictMessage(),
        );
    }

    public function delete(ApReferenceSpec $spec, string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[' . $spec->table . '] WHERE [id] = :id', ['id' => $id]);
    }

    private static function columns(ApReferenceSpec $spec): string
    {
        return '[id], ' . implode(', ', array_map(static fn (string $n): string => '[' . $n . ']', array_keys($spec->fields))) . ', [is_active], [created_date]';
    }

    /** @param array<string, mixed> $row */
    private static function row(ApReferenceSpec $spec, array $row): ApReferenceRow
    {
        $values = [];
        foreach (array_keys($spec->fields) as $name) {
            $values[$name] = self::text($row[$name] ?? null);
        }

        return new ApReferenceRow(
            $spec->list,
            self::id($row['id']),
            $values,
            self::bool($row['is_active']),
            self::when($row['created_date'], 'created_date'),
        );
    }
}
