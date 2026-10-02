<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * The prices of the lines as they stand in the editor, saved or not (parity
 * plan E5), carried on `rfq.readiness.v1` as `pricing` for anyone who holds
 * `RFQ.PRICING.VIEW`. Nothing is stored by asking.
 *
 * `total` is the quote total over the included lines that have a price, in
 * `currency`; `unpriced` counts the lines that ought to have a price and have
 * none (the editor's "No price for X of N lines"); `pricedOf` is N, the lines
 * that are being quoted. `error` is set when the prices could not be had at
 * all (the parser or the Cost API was down), so the editor can say so and
 * offer Retry instead of showing a blank.
 */
final class RfqPricingView
{
    /**
     * @param list<array{lineUid: string, pricing: LinePricing}> $lines
     */
    public function __construct(
        public readonly string $currency,
        public readonly ?string $total,
        public readonly int $unpriced,
        public readonly int $quoted,
        public readonly array $lines,
        public readonly ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'total' => $this->total,
            'unpriced' => $this->unpriced,
            'quoted' => $this->quoted,
            'error' => $this->error,
            'lines' => array_map(
                static fn (array $line): array => ['lineUid' => $line['lineUid'], ...$line['pricing']->toArray()],
                $this->lines,
            ),
        ];
    }
}
