<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Credit;

/**
 * One persisted order line. Everything except the credit_* values comes from
 * 4JS. The credit_* values are what is being credited, already capped.
 */
final readonly class CustomerContactReportLine
{
    public function __construct(
        public string $orderNo,
        public string $invoiceNo,
        public int $lineNo,
        public string $productCode,
        public string $description,
        public string $qty,
        public string $unit,
        public int $packs,
        public string $packSize,
        public string $unitPrice,
        public string $net,
        public string $creditQty,
        public string $creditRate,
        public string $creditNet,
        public string $warehouse,
        public string $comment,
        /** Fraction (0.10) from the invoice, or null when only an order was ticked. */
        public ?string $gstRate,
        /** True when a requested figure was above what 4JS invoiced and the server replaced it. */
        public bool $capped = false,
    ) {
    }

    public function signature(): string
    {
        return $this->orderNo . ':' . $this->lineNo . '=' . $this->creditQty . '/' . $this->creditNet;
    }
}
