<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * One line of a draft vendor bill: amount before tax, the expense account, the purchase taxes and the cost centre
 * (an analytic account), all as Odoo ids the caller has resolved (from `finance.odoo_records`). A null account lets
 * Odoo pick the vendor's or the product's default.
 */
final readonly class Ap2OdooBillLine
{
    /** @param list<int> $taxIds */
    public function __construct(
        public string $description,
        public float $quantity,
        public float $priceUnit,
        public ?int $accountId = null,
        public array $taxIds = [],
        public ?int $analyticAccountId = null,
    ) {
    }

    /**
     * The line as `invoice_line_ids` takes it: a create command `[0, 0, {...}]`.
     *
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    public function command(): array
    {
        $values = ['name' => $this->description, 'quantity' => $this->quantity, 'price_unit' => round($this->priceUnit, 4), 'tax_ids' => [[6, 0, $this->taxIds]]];
        if ($this->accountId !== null) {
            $values['account_id'] = $this->accountId;
        }
        if ($this->analyticAccountId !== null) {
            $values['analytic_distribution'] = [(string) $this->analyticAccountId => 100];
        }

        return [0, 0, $values];
    }
}
