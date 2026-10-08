<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/**
 * What the model read from one attachment (`ar_remittance`): the payment header and one line per invoice settled.
 * `reconciles` is null when there is nothing to check against (no total, or no paid amounts), as the source's was.
 *
 * @phpstan-type Line array{invoice_number: string, invoice_date: ?string, gross_amount: ?float, discount_amount: ?float, paid_amount: ?float}
 */
final readonly class ArExtraction
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public bool $isRemittance,
        public string $payerName,
        public ?string $paymentDate,
        public ?string $paymentReference,
        public string $currency,
        public ?float $totalAmount,
        public array $lines,
        public float $confidence,
        public string $notes,
    ) {
    }

    /** Whether the line items add up to the stated total (the source's `reconciles`, tolerance one cent). */
    public function reconciles(float $tolerance = 0.01): ?bool
    {
        $paid = [];
        foreach ($this->lines as $line) {
            if ($line['paid_amount'] !== null) {
                $paid[] = $line['paid_amount'];
            }
        }
        if ($this->totalAmount === null || $paid === []) {
            return null;
        }

        return abs(array_sum($paid) - $this->totalAmount) <= $tolerance;
    }
}
