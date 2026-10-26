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

    /** True when nothing was read: no field, no confidence, no overall score. */
    public function isEmpty(): bool
    {
        return $this->confidence === [] && $this->overall <= 0.0 && array_filter($this->toArray()['fields'], static fn (mixed $v): bool => $v !== null) === [];
    }

    /**
     * The stable JSON form: `{fields: {number: ..., ...}, confidence: {field: 0..1}, overall: 0..1}`.
     *
     * @return array{fields: array<string, string|float|null>, confidence: array<string, float>, overall: float}
     */
    public function toArray(): array
    {
        return [
            'fields' => [
                'number' => $this->number,
                'issued' => $this->issued,
                'due' => $this->due,
                'currency' => $this->currency,
                'net' => $this->net,
                'tax' => $this->tax,
                'total' => $this->total,
                'po' => $this->po,
                'quantity' => $this->quantity,
                'poUnitPrice' => $this->poUnitPrice,
                'credit' => $this->credit,
                'creditRef' => $this->creditRef,
                'creditNet' => $this->creditNet,
                'creditTax' => $this->creditTax,
                'entity' => $this->entity,
            ],
            'confidence' => $this->confidence,
            'overall' => $this->overall,
        ];
    }

    /** @param array<array-key, mixed> $data the output of {@see toArray()}; anything malformed is read as "not found" */
    public static function fromArray(array $data): self
    {
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $text = static fn (string $k): ?string => is_string($fields[$k] ?? null) ? $fields[$k] : null;
        $number = static fn (string $k): ?float => is_int($fields[$k] ?? null) || is_float($fields[$k] ?? null) ? (float) $fields[$k] : null;
        $confidence = [];
        foreach (is_array($data['confidence'] ?? null) ? $data['confidence'] : [] as $field => $score) {
            if (is_string($field) && (is_int($score) || is_float($score))) {
                $confidence[$field] = (float) $score;
            }
        }
        $overall = $data['overall'] ?? 0.0;

        return new self(
            $text('number'),
            $text('issued'),
            $text('due'),
            $text('currency'),
            $number('net'),
            $number('tax'),
            $number('total'),
            $text('po'),
            $number('quantity'),
            $number('poUnitPrice'),
            $number('credit'),
            $text('creditRef'),
            $number('creditNet'),
            $number('creditTax'),
            $text('entity'),
            $confidence,
            is_int($overall) || is_float($overall) ? (float) $overall : 0.0,
        );
    }
}
