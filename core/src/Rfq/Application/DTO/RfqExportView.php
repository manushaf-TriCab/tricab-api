<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

use TriCab\Core\Rfq\Export\SpreadsheetCsv;
use TriCab\Core\Rfq\Parser\QuoteExport;

/**
 * The Product Summary and the two quote CSVs of the lines as they stand in the
 * editor, saved or not (parity plan E7.1). Nothing is stored by asking.
 *
 * `summaryToArray()` is the panel's JSON: one entry per product, in code
 * order. It carries no cost, margin or price at all, so it is the same for
 * everyone who can read the RFQ. `cost_status` (STOCK, MAKE...) is the source's
 * tag and is shown to everyone, as the source's simple view shows it.
 *
 * `csv()` is the file: BOM, CRLF, formula payloads neutralised
 * ({@see SpreadsheetCsv}). Its two money columns are in it only when the view
 * was built with `$includesPrices`, which the service allows only to a holder
 * of `RFQ.PRICING.VIEW`.
 *
 * `pricingError` is set when the prices could not be had (the parser or the
 * Cost API was down). A summary still lists its products; a CSV that needs
 * prices, or a summary file (a product with no price is not exported), must
 * not be handed out as if it were complete, see {@see needsPrices()}.
 */
final class RfqExportView
{
    public const QUOTE_LINES = 'quote-lines';
    public const SUMMARY_LINES = 'summary-quote-lines';

    /**
     * @param list<array<string, mixed>>                                $products
     * @param array{columns: list<string>, rows: list<list<string>>}    $quoteLines
     * @param array{columns: list<string>, rows: list<list<string>>}    $summaryLines
     */
    private function __construct(
        public readonly array $products,
        public readonly array $quoteLines,
        public readonly array $summaryLines,
        public readonly ?string $pricingError,
        public readonly bool $includesPrices,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows the rows the export was asked about, in order
     */
    public static function from(QuoteExport $export, array $rows, ?string $pricingError, bool $includesPrices): self
    {
        $uids = array_map(static fn (array $row): string => is_string($row['line_uid'] ?? null) ? $row['line_uid'] : '', $rows);
        $products = [];
        foreach ($export->summary as $item) {
            $lineUids = [];
            foreach (is_array($item['line_indexes'] ?? null) ? $item['line_indexes'] : [] as $index) {
                $uid = is_int($index) ? ($uids[$index] ?? '') : '';
                if ($uid !== '') {
                    $lineUids[] = $uid;
                }
            }
            $cost = $item['cost_status'] ?? '';
            $products[] = [
                'productCode' => $item['product_code'],
                'lineCount' => is_int($item['line_count'] ?? null) ? $item['line_count'] : count($lineUids),
                'requestedTotalLength' => self::number($item['requested_total_length'] ?? null),
                'quotedTotalLength' => self::number($item['quoted_total_length'] ?? null),
                'costStatus' => is_string($cost) && $cost !== '' ? $cost : null,
                'moq' => self::number($item['moq'] ?? null),
                'moqApplied' => ($item['moq_applied'] ?? false) === true,
                'prepackApplied' => ($item['prepack_applied'] ?? false) === true,
                'cutsText' => is_string($item['cuts_text'] ?? null) ? $item['cuts_text'] : '',
                'lineUids' => $lineUids,
            ];
        }

        return new self($products, $export->quoteLines, $export->summaryLines, $pricingError, $includesPrices);
    }

    /** @return array<string, mixed> `rfq.product-summary.v1` */
    public function summaryToArray(): array
    {
        return [
            'products' => $this->products,
            'exportableProducts' => count($this->summaryLines['rows']),
            'pricingError' => $this->pricingError,
        ];
    }

    /** Whether the file `$kind` would be incomplete or wrong without prices, and prices could not be had. */
    public function needsPrices(string $kind): bool
    {
        return $this->pricingError !== null && ($kind === self::SUMMARY_LINES || $this->includesPrices);
    }

    /** The file's text: `quote-lines` or `summary-quote-lines`. */
    public function csv(string $kind): string
    {
        $table = $kind === self::SUMMARY_LINES ? $this->summaryLines : $this->quoteLines;

        return SpreadsheetCsv::encode($table['columns'], $table['rows']);
    }

    /** How many lines the file holds. */
    public function lineCount(string $kind): int
    {
        return count(($kind === self::SUMMARY_LINES ? $this->summaryLines : $this->quoteLines)['rows']);
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }
}
