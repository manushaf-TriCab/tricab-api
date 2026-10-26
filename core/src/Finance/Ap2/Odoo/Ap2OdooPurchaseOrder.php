<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * A purchase order as Odoo holds it (`purchase.order`), with its lines (`purchase.order.line`) and its receipts
 * (`stock.picking`). Quantities are in each line's unit; `currency` is the order's ISO code.
 */
final readonly class Ap2OdooPurchaseOrder
{
    public const STATES = ['draft', 'sent', 'to approve', 'purchase', 'done', 'cancel'];

    /**
     * @param list<Ap2OdooPoLine>  $lines   product lines only (section and note lines are left out)
     * @param list<Ap2OdooReceipt> $receipts
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $state,
        public ?int $partnerId,
        public string $partnerName,
        public ?int $companyId,
        public string $companyName,
        public string $currency,
        public ?string $orderDate,
        public array $lines,
        public array $receipts,
    ) {
    }

    /** Confirmed in Odoo (`purchase` or `done`): only then does it count for matching. */
    public function isConfirmed(): bool
    {
        return $this->state === 'purchase' || $this->state === 'done';
    }

    public function orderedQty(): float
    {
        return round(array_sum(array_map(static fn (Ap2OdooPoLine $l): float => $l->orderedQty, $this->lines)), 4);
    }

    public function receivedQty(): float
    {
        return round(array_sum(array_map(static fn (Ap2OdooPoLine $l): float => $l->receivedQty, $this->lines)), 4);
    }

    /** The unit price when every product line has the same one, else null (no averaging: a person decides). */
    public function uniformUnitPrice(): ?float
    {
        $prices = array_values(array_unique(array_map(static fn (Ap2OdooPoLine $l): string => number_format($l->priceUnit, 4, '.', ''), $this->lines)));

        return count($prices) === 1 ? (float) $prices[0] : null;
    }

    /** The names of the receipts that are done, e.g. "WH/IN/00042, WH/IN/00045". */
    public function receiptRef(): string
    {
        $done = array_filter($this->receipts, static fn (Ap2OdooReceipt $r): bool => $r->state === 'done');

        return implode(', ', array_map(static fn (Ap2OdooReceipt $r): string => $r->name, $done));
    }
}
