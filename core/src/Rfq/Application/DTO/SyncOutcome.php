<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * What a sync that reached 4js came to. Contract `rfq.sync-result.v1`.
 *
 * `outcome` is never softened:
 *
 *  - `succeeded`: every line landed; the RFQ is completed;
 *  - `partial`: the quote exists in 4js and some lines landed, some did not;
 *  - `failed`: nothing usable landed (the header was refused, or no line was accepted);
 *  - `uncertain`: the header request did not complete, so 4js may hold a quote
 *    nobody has the number of. **Do not retry blind**; the RFQ is held until a person checks 4js.
 *
 * Lines are named in the rep's terms: the line's number as stored, its id and its
 * code. The PDF link is the adapter's; only an `https` one is passed on. A fallback
 * margin's percentage and a reviewed or required price are pricing: they go only to
 * a holder of `RFQ.PRICING.VIEW`.
 */
final class SyncOutcome
{
    public const SUCCEEDED = 'succeeded';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const UNCERTAIN = 'uncertain';

    /**
     * @param list<array{lineNumber: ?int, lineUid: ?string, code: string, reason: string}> $failures
     * @param list<array{lineNumber: ?int, lineUid: ?string, code: string, reason: string}> $excluded
     * @param list<array{lineNumber: ?int, lineUid: ?string, code: string, margin: int|float}> $fallbackMargins
     */
    public function __construct(
        public readonly string $outcome,
        public readonly SyncAttemptView $attempt,
        public readonly string $message,
        public readonly ?string $quoteNumber,
        public readonly ?string $quoteVersion,
        public readonly bool $isUpdate,
        public readonly int $linesSent,
        public readonly int $linesTotal,
        public readonly array $failures,
        public readonly array $excluded,
        public readonly array $fallbackMargins,
        public readonly ?string $pdfUrl,
        public readonly ?string $pdfError,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->outcome === self::SUCCEEDED;
    }

    /**
     * @param bool $withPricing the reader holds `RFQ.PRICING.VIEW`: only then does a fallback margin name its percentage
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $withPricing = false): array
    {
        return [
            'outcome' => $this->outcome,
            'ok' => $this->isSuccess(),
            'message' => $this->message,
            'quote' => [
                'number' => $this->quoteNumber,
                'version' => $this->quoteVersion,
                'isUpdate' => $this->isUpdate,
                'pdfUrl' => $this->pdfUrl !== null && str_starts_with($this->pdfUrl, 'https://') ? $this->pdfUrl : null,
                'pdfError' => $this->pdfError,
            ],
            'linesSent' => $this->linesSent,
            'linesFailed' => count($this->failures),
            'linesTotal' => $this->linesTotal,
            'failures' => $this->failures,
            'excluded' => $this->excluded,
            // A margin is withheld from anyone without RFQ.PRICING.VIEW: the line is still named, so the rep knows to check it in 4js.
            'fallbackMargins' => array_map(
                static fn (array $item): array => ['margin' => $withPricing ? $item['margin'] : null] + $item,
                $this->fallbackMargins,
            ),
            'attempt' => $this->attempt->toArray(),
        ];
    }
}
