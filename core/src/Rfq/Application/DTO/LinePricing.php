<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * One line's price, as the source editor's detailed view shows it (parity plan
 * E5.3). Contract `rfq.line-pricing.v1`, carried on `rfq.line.v1` as `pricing`
 * and withheld from anyone without `RFQ.PRICING.VIEW` (Q9: withheld, not just
 * hidden).
 *
 * Money is decimal strings in `currency`. `priceStatus` is the Cost API's own
 * answer for the line (`available`, `not_found`, `error`, `missing_code`,
 * `missing_length`) or the editor's (`pending`, `loading`); `costStatus` is
 * what the cost book says the product is (`STOCK`, `MAKE`). `unitSell` is cost
 * and margin worked out by the parser, never by a browser: margin on sell,
 * rounded up at the third decimal. `sellLocked` means the rep typed the sell
 * price and the margin follows it; `marginLocked` that they typed the margin,
 * which outranks the customer's default.
 */
final class LinePricing
{
    public function __construct(
        public readonly ?string $unitCost,
        public readonly ?string $costStatus,
        public readonly string $priceStatus,
        public readonly ?string $error,
        public readonly ?int $marginPercent,
        public readonly bool $marginLocked,
        public readonly ?string $unitSell,
        public readonly bool $sellLocked,
        public readonly ?string $lineTotal,
        public readonly ?string $currency,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'unitCost' => $this->unitCost,
            'costStatus' => $this->costStatus,
            'priceStatus' => $this->priceStatus,
            'error' => $this->error,
            'marginPercent' => $this->marginPercent,
            'marginLocked' => $this->marginLocked,
            'unitSell' => $this->unitSell,
            'sellLocked' => $this->sellLocked,
            'lineTotal' => $this->lineTotal,
            'currency' => $this->currency,
        ];
    }
}
