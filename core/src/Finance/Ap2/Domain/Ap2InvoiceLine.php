<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/**
 * One line of the Odoo vendor-bill template (`Invoice Lines/Label`, `/Account`, `/Quantity`, `/Unit Price`, `/Taxes`).
 * An invoice with no saved lines exports one line made from its own coding ({@see Ap2Invoice::odooLines()}).
 */
final readonly class Ap2InvoiceLine
{
    public function __construct(
        public string $label,
        public string $account,
        public float $quantity,
        public float $unitPrice,
        public string $taxes,
    ) {
    }

    /** @return array{label: string, account: string, quantity: float, unitPrice: float, taxes: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'account' => $this->account, 'quantity' => $this->quantity, 'unitPrice' => $this->unitPrice, 'taxes' => $this->taxes];
    }
}
