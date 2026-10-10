<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

use TriCab\Core\Finance\Ap\Service\ApGrading;

/**
 * What the model read from one invoice document, graded: the fields `extract_invoice_fields` returns and Tri-AP stores,
 * the confidence, and `review_reason` (null when the capture looks sound).
 *
 * `fromResult()` is the source's `app.py` in one place: `_match_company` on `company`, then `_grade_extraction`
 * (`INVOICE_CONFIDENCE_THRESHOLD`, 0.80; {@see ApGrading}) with its three reasons, joined by "; ":
 *
 * - `Could not read the invoice number, vendor, total` (whichever of those three are empty);
 * - `Low extraction confidence (NN%)` when the confidence is below the threshold;
 * - `Amounts do not add up: N + T = S, but the total reads X` when net + tax is more than two cents from the total.
 *
 * The confidence is the extraction's own, else the classification's, clamped to 0..1, and 0.0 when neither is a number.
 * A capture with a `review_reason` is "Needs Review"; one without is "In Progress" ({@see self::status()}).
 */
final readonly class ApExtraction
{
    /** `INVOICE_CONFIDENCE_THRESHOLD`'s default: below it a capture goes to Review. */
    public const CONFIDENCE_THRESHOLD = 0.80;

    public const STATUS_NEEDS_REVIEW = 'Needs Review';

    public const STATUS_IN_PROGRESS = 'In Progress';

    /** The `review_reason` of an attachment the AI called a non-invoice that a person captured anyway (the scan's `force`). */
    public const FORCED_REVIEW_REASON = 'Captured by hand from the AP Mailbox; the AI did not read this file as an invoice';

    /** The scan stores this vendor when the model read none. */
    public const UNKNOWN_VENDOR = 'Unknown Vendor';

    public function __construct(
        public ?string $invoiceNo,
        public ?string $vendor,
        public ?string $company,
        public ?string $vendorEmail,
        public ?string $taxId,
        public ?string $invoiceDate,
        public ?string $dueDate,
        public ?string $poNumber,
        public string $currency,
        public ?float $netAmount,
        public ?float $taxAmount,
        public ?float $totalAmount,
        public float $extractionConfidence,
        public ?string $notes,
        public ?string $reviewReason,
    ) {
    }

    /**
     * @param array<array-key, mixed> $result the service's answer (`ap_extract`, or the invoice part of `ap_classify_extract`)
     * @param list<ApExtractionCompany> $companies every row of the companies list
     */
    public static function fromResult(array $result, array $companies, float $threshold = self::CONFIDENCE_THRESHOLD): self
    {
        [$confidence, $reason] = self::grade($result, $threshold);
        $currency = self::text($result['currency'] ?? null);

        return new self(
            self::text($result['invoice_no'] ?? null),
            self::text($result['vendor'] ?? null),
            ApExtractionCompany::match(self::text($result['company'] ?? null), $companies),
            self::text($result['vendor_email'] ?? null),
            self::text($result['tax_id'] ?? null),
            self::text($result['invoice_date'] ?? null),
            self::text($result['due_date'] ?? null),
            self::text($result['po_number'] ?? null),
            $currency !== null && $currency !== '' ? $currency : 'AUD',
            ApGrading::number($result['net_amount'] ?? null),
            ApGrading::number($result['tax_amount'] ?? null),
            ApGrading::number($result['total_amount'] ?? null),
            $confidence,
            self::text($result['notes'] ?? null),
            $reason,
        );
    }

    /**
     * `_grade_extraction()`: the confidence, and in plain words what is wrong with the capture (null when nothing is).
     * This is {@see ApGrading::grade()}, the register's one grader, so manual extraction and capture cannot disagree:
     * sums are compared in whole cents and the percentage rounds half up.
     *
     * @param array<array-key, mixed> $fields
     * @return array{0: float, 1: ?string}
     */
    public static function grade(array $fields, float $threshold = self::CONFIDENCE_THRESHOLD): array
    {
        return ApGrading::grade($fields, $threshold);
    }

    /** "Needs Review" when there is a `review_reason`, else "In Progress" (the scan's status for a new capture). */
    public function status(): string
    {
        return $this->reviewReason === null ? self::STATUS_IN_PROGRESS : self::STATUS_NEEDS_REVIEW;
    }

    public function needsReview(): bool
    {
        return $this->reviewReason !== null;
    }

    /** The scan stores a generated number when the model read none: `INV-` and the Unix time. */
    public function invoiceNoOrGenerated(int $nowTimestamp): string
    {
        return $this->invoiceNo !== null && $this->invoiceNo !== '' ? $this->invoiceNo : 'INV-' . $nowTimestamp;
    }

    public function vendorOrUnknown(): string
    {
        return $this->vendor !== null && $this->vendor !== '' ? $this->vendor : self::UNKNOWN_VENDOR;
    }

    /**
     * `_resolve_vendor_email()`: the address the invoice prints, else the sender, but never one of our own domains
     * (an invoice forwarded by a colleague must not make the vendor's email `ap@tricab.com`).
     *
     * @param list<string> $internalDomains `INTERNAL_EMAIL_DOMAINS`, default the AP mailbox's domain
     */
    public static function resolveVendorEmail(?string $extracted, ?string $sender, array $internalDomains): ?string
    {
        $domains = [];
        foreach ($internalDomains as $domain) {
            $domain = ltrim(strtolower(trim($domain)), '@');
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        $internal = static function (?string $address) use ($domains): bool {
            if ($address === null || !str_contains($address, '@')) {
                return false;
            }

            return in_array(strtolower(substr(strrchr(trim($address), '@') ?: '', 1)), $domains, true);
        };
        $candidate = trim($extracted ?? '');
        if ($candidate !== '' && str_contains($candidate, '@') && !$internal($candidate)) {
            return $candidate;
        }
        if ($sender !== null && $sender !== '' && !$internal($sender)) {
            return $sender;
        }

        return null;
    }

    /**
     * The shape `POST /extract` answers and the capture dialog reads: the source's fields, with `company` matched,
     * `extraction_confidence` graded and `review_reason`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'invoice_no' => $this->invoiceNo,
            'vendor' => $this->vendor,
            'company' => $this->company,
            'vendor_email' => $this->vendorEmail,
            'tax_id' => $this->taxId,
            'invoice_date' => $this->invoiceDate,
            'due_date' => $this->dueDate,
            'po_number' => $this->poNumber,
            'currency' => $this->currency,
            'net_amount' => $this->netAmount,
            'tax_amount' => $this->taxAmount,
            'total_amount' => $this->totalAmount,
            'extraction_confidence' => $this->extractionConfidence,
            'notes' => $this->notes,
            'review_reason' => $this->reviewReason,
        ];
    }

    // ---- Python's rules, which the source's grading relied on --------------------------------

    /** A text field as stored: a string, with a number the model wrote in its place turned into text; nothing else. */
    private static function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}
