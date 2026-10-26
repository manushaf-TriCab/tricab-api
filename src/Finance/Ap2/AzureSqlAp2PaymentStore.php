<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** `finance.ap2_payments`. */
final class AzureSqlAp2PaymentStore extends AzureSqlAp2Support implements Ap2PaymentStore
{
    private const COLUMNS = '[id], [supplier_id], [invoice_id], [amount], [currency], [date], [status], [approval_id], [odoo_payment_ref], [rejection],
        [created_by_user_id], [created_by_name], [created_at], [updated_at]';

    public function all(): array
    {
        return array_map(self::payment(...), $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_payments] ORDER BY [created_at] DESC, [id] DESC'));
    }

    public function find(string $id): ?Ap2Payment
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[ap2_payments] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::payment($rows[0]);
    }

    public function insert(Ap2Payment $p): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[ap2_payments] (' . self::COLUMNS . ')
             VALUES (:id, :supplier_id, :invoice_id, :amount, :currency, :date, :status, :approval_id, :odoo_payment_ref, :rejection,
                     :created_by_user_id, :created_by_name, :created_at, :updated_at)',
            [
                'id' => $p->id,
                'supplier_id' => $p->supplierId,
                'invoice_id' => $p->invoiceId,
                'amount' => self::money($p->amount),
                'currency' => $p->currency,
                'date' => $p->date,
                'status' => $p->status,
                'approval_id' => $p->approvalId,
                'odoo_payment_ref' => $p->odooPaymentRef,
                'rejection' => $p->rejection,
                'created_by_user_id' => $p->createdBy?->userId,
                'created_by_name' => $p->createdBy->name ?? '',
                'created_at' => self::at($p->createdAt),
                'updated_at' => self::at($p->updatedAt),
            ],
        );
    }

    public function update(Ap2Payment $p): void
    {
        $this->connection->execute(
            'UPDATE [finance].[ap2_payments] SET [status] = :status, [approval_id] = :approval_id, [odoo_payment_ref] = :odoo_payment_ref,
                    [rejection] = :rejection, [updated_at] = :updated_at WHERE [id] = :id',
            ['id' => $p->id, 'status' => $p->status, 'approval_id' => $p->approvalId, 'odoo_payment_ref' => $p->odooPaymentRef, 'rejection' => $p->rejection, 'updated_at' => self::at($p->updatedAt)],
        );
    }

    /** @param array<string, mixed> $row */
    private static function payment(array $row): Ap2Payment
    {
        $userId = AzureSqlValueMapper::nullableInt($row['created_by_user_id']);

        return new Ap2Payment(
            self::id($row['id']),
            self::id($row['supplier_id']),
            self::id($row['invoice_id']),
            self::amount($row['amount']),
            trim(self::text($row['currency'])),
            self::day($row['date']) ?? '',
            self::when($row['created_at'], 'created_at'),
            self::when($row['updated_at'], 'updated_at'),
            self::text($row['status']),
            self::textOrNull($row['approval_id']),
            self::text($row['odoo_payment_ref']),
            self::text($row['rejection']),
            $userId === null ? null : new Ap2Person($userId, self::text($row['created_by_name']), ''),
        );
    }
}
