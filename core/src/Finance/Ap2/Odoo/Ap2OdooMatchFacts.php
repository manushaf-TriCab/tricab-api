<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * What Odoo says about the PO an invoice quotes, as the invoice's matching fields: `orderedQty`, `receivedQty`,
 * `poUnitPrice` (null when the lines differ in price: a person decides) and `receiptRef` (the done receipts).
 * `found` is false for a PO number Odoo does not have; `issues` names what a person should look at.
 */
final readonly class Ap2OdooMatchFacts
{
    /** @param list<string> $issues */
    public function __construct(
        public string $invoiceId,
        public string $po,
        public bool $found,
        public float $orderedQty = 0.0,
        public float $receivedQty = 0.0,
        public ?float $poUnitPrice = null,
        public string $receiptRef = '',
        public string $currency = '',
        public string $companyName = '',
        public ?int $orderId = null,
        public array $issues = [],
    ) {
    }

    public static function of(Ap2OdooMatchRequest $request, ?Ap2OdooPurchaseOrder $order): self
    {
        if ($order === null) {
            return new self($request->invoiceId, $request->po, false, issues: ['PO ' . $request->po . ' is not in Odoo']);
        }
        $issues = [];
        if (!$order->isConfirmed()) {
            $issues[] = 'PO ' . $order->name . ' is ' . ($order->state === 'cancel' ? 'cancelled' : 'not confirmed') . ' in Odoo';
        }
        if ($order->currency !== '' && $order->currency !== $request->currency) {
            $issues[] = 'PO ' . $order->name . ' is in ' . $order->currency . ', the invoice in ' . $request->currency;
        }
        if ($order->lines === []) {
            $issues[] = 'PO ' . $order->name . ' has no product lines';
        }
        $price = $order->uniformUnitPrice();
        if ($price === null && $order->lines !== []) {
            $issues[] = 'PO ' . $order->name . ' lines have different unit prices; check the price by line';
        }

        return new self(
            $request->invoiceId,
            $order->name,
            true,
            $order->orderedQty(),
            $order->receivedQty(),
            $price,
            $order->receiptRef(),
            $order->currency,
            $order->companyName,
            $order->id,
            $issues,
        );
    }
}
