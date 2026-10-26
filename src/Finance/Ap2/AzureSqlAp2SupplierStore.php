<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** `finance.ap2_suppliers` (`create-finance-ap2-schema.sql`). */
final class AzureSqlAp2SupplierStore extends AzureSqlAp2Support implements Ap2SupplierStore
{
    private const TABLE = '[finance].[ap2_suppliers]';

    private const COLUMNS = [
        'id', 'name', 'industry', 'email', 'contact', 'cc', 'entity', 'phone', 'terms', 'owner_user_id', 'owner_name', 'owner_email',
        'bank_verified', 'bank_change', 'hold', 'notes', 'odoo_partner_id', 'created_at', 'updated_at',
    ];

    public function all(): array
    {
        return array_map(self::supplier(...), $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' ORDER BY [name], [id]'));
    }

    public function find(string $id): ?Ap2Supplier
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::select() . ' FROM ' . self::TABLE . ' WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::supplier($rows[0]);
    }

    public function insert(Ap2Supplier $s): void
    {
        $names = array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS);
        $marks = array_map(static fn (string $c): string => ':' . $c, self::COLUMNS);
        $this->write(
            'INSERT INTO ' . self::TABLE . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', $marks) . ')',
            self::params($s),
            'The supplier already exists',
        );
    }

    public function update(Ap2Supplier $s): void
    {
        $sets = [];
        foreach (self::COLUMNS as $column) {
            if ($column !== 'id' && $column !== 'created_at') {
                $sets[] = '[' . $column . '] = :' . $column;
            }
        }
        $params = self::params($s);
        unset($params['created_at']);
        $this->write('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE [id] = :id', $params, 'The supplier clashes with another');
    }

    private static function select(): string
    {
        return implode(', ', array_map(static fn (string $c): string => '[' . $c . ']', self::COLUMNS));
    }

    /** @return array<string, scalar|null> */
    private static function params(Ap2Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'industry' => $s->industry,
            'email' => $s->email,
            'contact' => $s->contact,
            'cc' => $s->cc,
            'entity' => $s->entity,
            'phone' => $s->phone,
            'terms' => $s->terms,
            'owner_user_id' => $s->owner?->userId,
            'owner_name' => $s->owner->name ?? '',
            'owner_email' => $s->owner->email ?? '',
            'bank_verified' => $s->bankVerified ? 1 : 0,
            'bank_change' => $s->bankChange ? 1 : 0,
            'hold' => $s->hold ? 1 : 0,
            'notes' => $s->notes,
            'odoo_partner_id' => $s->odooPartnerId,
            'created_at' => self::at($s->createdAt),
            'updated_at' => self::at($s->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function supplier(array $row): Ap2Supplier
    {
        return new Ap2Supplier(
            self::id($row['id']),
            self::text($row['name']),
            self::when($row['created_at'], 'created_at'),
            self::when($row['updated_at'], 'updated_at'),
            self::text($row['industry']),
            self::text($row['email']),
            self::text($row['contact']),
            self::text($row['cc']),
            self::text($row['entity']),
            self::text($row['phone']),
            self::int($row['terms']),
            self::person($row, 'owner'),
            self::bool($row['bank_verified']),
            self::bool($row['bank_change']),
            self::bool($row['hold']),
            self::text($row['notes']),
            AzureSqlValueMapper::nullableInt($row['odoo_partner_id']),
        );
    }
}
