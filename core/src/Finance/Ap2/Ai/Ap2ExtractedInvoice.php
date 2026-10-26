<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/**
 * Invoice fields read from a document. Every field is nullable (null = not found). `confidence` holds a 0 to 1 score
 * for each field that is not null (keyed by field name); `overall` is the 0 to 1 score for the whole read.
 *
 * Dates are `yyyy-MM-dd`; money is a number in the document's currency; `currency` is AUD, USD, NZD or SGD (anything
 * else is left null); `entity` is the TriCab company the invoice is addressed to.
 */
final readonly class Ap2ExtractedInvoice
{
    public const FIELDS = ['number', 'issued', 'due', 'currency', 'net', 'tax', 'total', 'po', 'quantity', 'poUnitPrice', 'credit', 'creditRef', 'creditNet', 'creditTax', 'entity'];

    /**
     * @param array<string, float> $confidence per-field, 0 to 1
     */
    public function __construct(
        public ?string $number = null,
        public ?string $issued = null,
        public ?string $due = null,
        public ?string $currency = null,
        public ?float $net = null,
        public ?float $tax = null,
        public ?float $total = null,
        public ?string $po = null,
        public ?float $quantity = null,
        public ?float $poUnitPrice = null,
        public ?float $credit = null,
        public ?string $creditRef = null,
        public ?float $creditNet = null,
        public ?float $creditTax = null,
        public ?string $entity = null,
        public array $confidence = [],
        public float $overall = 0.0,
    ) {
    }

    /** "AI not connected": every field null, no confidence. */
    public static function none(): self
    {
        return new self();
    }
}
