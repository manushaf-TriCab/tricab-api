<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * One posted line on a payable account in Odoo (`account.move.line`, `account_type = liability_payable`): a bill, a
 * credit note, a payment, or an opening-balance entry. Odoo's signs: a credit (owed to the supplier) is negative.
 * `balance` and `residual` are in the company's currency, `amountCurrency` and `residualCurrency` in the line's own.
 * `partnerId` is null for an entry not booked to a supplier (the opening balances brought over from 4js).
 */
final readonly class Ap2OdooPayableLine
{
    public function __construct(
        public int $id,
        public int $moveId,
        public string $moveName,
        public string $ref,
        public string $moveType,
        public string $journal,
        public ?int $partnerId,
        public string $partnerName,
        public int $companyId,
        public string $companyCurrency,
        public string $currency,
        public string $date,
        public ?string $dueDate,
        public float $balance,
        public float $residual,
        public float $amountCurrency,
        public float $residualCurrency,
        public bool $reconciled,
    ) {
    }

    /** What is still owed on the line, in the company's currency: positive for a bill, negative for a payment. */
    public function owed(): float
    {
        return -$this->residual;
    }

    /** A payment, or another entry in the supplier's favour, that no bill has been matched to yet. */
    public function isUnallocatedPayment(): bool
    {
        return !$this->reconciled && $this->moveType === 'entry' && $this->residual > 0.005;
    }

    public function isBill(): bool
    {
        return $this->moveType === 'in_invoice';
    }

    /** The day ageing counts from: the due date, or the entry's date when it has none (as Odoo's aged report does). */
    public function agedFrom(): string
    {
        return $this->dueDate ?? $this->date;
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<mixed> $a a kept copy: read defensively */
    public static function fromArray(array $a): self
    {
        $s = static fn (string $k): string => is_string($a[$k] ?? null) ? $a[$k] : '';
        $f = static fn (string $k): float => is_int($a[$k] ?? null) || is_float($a[$k] ?? null) ? (float) $a[$k] : 0.0;
        $i = static fn (string $k): ?int => is_int($a[$k] ?? null) ? $a[$k] : null;

        return new self(
            $i('id') ?? 0,
            $i('moveId') ?? 0,
            $s('moveName'),
            $s('ref'),
            $s('moveType'),
            $s('journal'),
            $i('partnerId'),
            $s('partnerName'),
            $i('companyId') ?? 0,
            $s('companyCurrency'),
            $s('currency'),
            $s('date'),
            is_string($a['dueDate'] ?? null) ? $a['dueDate'] : null,
            $f('balance'),
            $f('residual'),
            $f('amountCurrency'),
            $f('residualCurrency'),
            ($a['reconciled'] ?? false) === true,
        );
    }
}
