<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Contract\Ap2StatementStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;

/** `finance.ap2_statements`. */
final class AzureSqlAp2StatementStore extends AzureSqlAp2Support implements Ap2StatementStore
{
    private const COLUMNS = '[id], [supplier_id], [mail_id], [date], [amount], [currency], [reference], [status], [evidence], [notes],
        [reviewed_by_user_id], [reviewed_by_name], [reviewed_at], [created_at], [updated_at]';

    public function all(): array
    {
        return array_map(self::statement(...), $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_statements] ORDER BY [date] DESC, [id] DESC'));
    }

    public function find(string $id): ?Ap2Statement
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_statements] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::statement($rows[0]);
    }

    public function insert(Ap2Statement $s): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_statements] (' . self::COLUMNS . ')
             VALUES (:id, :supplier_id, :mail_id, :date, :amount, :currency, :reference, :status, :evidence, :notes, :rb_user, :rb_name, :reviewed_at, :created_at, :updated_at)',
            self::params($s),
        );
    }

    public function update(Ap2Statement $s): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ap2_statements] SET [status] = :status, [evidence] = :evidence, [notes] = :notes, [reviewed_by_user_id] = :rb_user,
                    [reviewed_by_name] = :rb_name, [reviewed_at] = :reviewed_at, [updated_at] = :updated_at WHERE [id] = :id',
            array_intersect_key(self::params($s), array_flip(['id', 'status', 'evidence', 'notes', 'rb_user', 'rb_name', 'reviewed_at', 'updated_at'])),
        );
    }

    /** @return array<string, scalar|null> */
    private static function params(Ap2Statement $s): array
    {
        return [
            'id' => $s->id,
            'supplier_id' => $s->supplierId,
            'mail_id' => $s->mailId,
            'date' => $s->date,
            'amount' => self::money($s->amount),
            'currency' => $s->currency,
            'reference' => mb_substr($s->reference, 0, 255),
            'status' => $s->status,
            'evidence' => $s->evidence,
            'notes' => $s->notes,
            'rb_user' => $s->reviewedBy?->userId,
            'rb_name' => $s->reviewedBy->name ?? '',
            'reviewed_at' => self::atOrNull($s->reviewedAt),
            'created_at' => self::at($s->createdAt),
            'updated_at' => self::at($s->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function statement(array $row): Ap2Statement
    {
        $userId = AzureSqlValueMapper::nullableInt($row['reviewed_by_user_id']);

        return new Ap2Statement(
            self::id($row['id']),
            self::id($row['supplier_id']),
            self::day($row['date']) ?? '',
            self::amount($row['amount']),
            self::when($row['created_at'], 'created_at'),
            self::when($row['updated_at'], 'updated_at'),
            trim(self::text($row['currency'])),
            self::text($row['reference']),
            self::text($row['status']),
            self::text($row['evidence']),
            self::text($row['notes']),
            $userId === null ? null : new Ap2Person($userId, self::text($row['reviewed_by_name']), ''),
            self::whenOrNull($row['reviewed_at']),
            self::textOrNull($row['mail_id']),
        );
    }
}
