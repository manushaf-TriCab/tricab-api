<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

/**
 * The model's verdict on one mailbox attachment (`classify_and_extract_invoice`): an invoice or not, why, how sure, and
 * the invoice fields it read, graded as {@see ApExtraction} grades them.
 *
 * An email is "Invoice" when any of its attachments is one, else "Other" ({@see self::emailLabel()}); the scan decides
 * that, this class only names the two words.
 */
final readonly class ApClassification
{
    public const INVOICE = 'Invoice';

    public const OTHER = 'Other';

    public function __construct(
        public bool $isInvoice,
        public ?string $reason,
        public float $confidence,
        public ApExtraction $extraction,
    ) {
    }

    /**
     * @param array<array-key, mixed> $result the service's `ap_classify_extract` answer
     * @param list<ApExtractionCompany> $companies every row of the companies list
     */
    public static function fromResult(array $result, array $companies, float $threshold = ApExtraction::CONFIDENCE_THRESHOLD): self
    {
        $reason = $result['classification_reason'] ?? null;
        $confidence = $result['classification_confidence'] ?? null;

        return new self(
            ($result['is_invoice'] ?? null) === true,
            is_string($reason) && $reason !== '' ? $reason : null,
            is_int($confidence) || is_float($confidence) ? min(max((float) $confidence, 0.0), 1.0) : 0.0,
            ApExtraction::fromResult($result, $companies, $threshold),
        );
    }

    /** The word an attachment carries: "Invoice" or "Other". */
    public function label(): string
    {
        return $this->isInvoice ? self::INVOICE : self::OTHER;
    }

    public static function emailLabel(bool $invoiceDetected): string
    {
        return $invoiceDetected ? self::INVOICE : self::OTHER;
    }

    /**
     * The `review_reason` a captured attachment is stored with. A file the AI did not call an invoice but a person
     * captured anyway (`$forced`) gets the fixed forced reason; otherwise it is the extraction's own, which is null
     * when the capture looks sound.
     */
    public function reviewReason(bool $forced = false): ?string
    {
        if ($forced && !$this->isInvoice) {
            return ApExtraction::FORCED_REVIEW_REASON;
        }

        return $this->extraction->reviewReason;
    }

    /** "Needs Review" or "In Progress" for the invoice the scan creates from this attachment. */
    public function status(bool $forced = false): string
    {
        return $this->reviewReason($forced) === null ? ApExtraction::STATUS_IN_PROGRESS : ApExtraction::STATUS_NEEDS_REVIEW;
    }
}
