<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/export`, read strictly (parity plan E7.1):
 * the source's product summary over the rows it was sent, and the text cells
 * of the two quote CSVs. Contract `rfq-parser.v1`.
 *
 * Cells are text; the CSV file itself, and its escaping, are written by
 * {@see \TriCab\Core\Rfq\Export\SpreadsheetCsv}.
 */
final class QuoteExport
{
    /**
     * @param list<array<string, mixed>>                                    $summary      `build_product_summary`, `line_indexes` into the rows sent
     * @param array{columns: list<string>, rows: list<list<string>>}        $quoteLines
     * @param array{columns: list<string>, rows: list<list<string>>}        $summaryLines
     */
    private function __construct(
        public readonly array $summary,
        public readonly array $quoteLines,
        public readonly array $summaryLines,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $summary = $payload['summary'] ?? null;
        if (!is_array($summary) || !array_is_list($summary)) {
            throw self::malformed($sent);
        }
        $items = [];
        foreach ($summary as $item) {
            if (!is_array($item) || !is_string($item['product_code'] ?? null) || !is_array($item['line_indexes'] ?? null)) {
                throw self::malformed($sent);
            }
            /** @var array<string, mixed> $item */
            $items[] = $item;
        }

        return new self($items, self::table($payload['quoteLines'] ?? null, $sent), self::table($payload['summaryLines'] ?? null, $sent));
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string>>}
     */
    private static function table(mixed $table, CorrelationId $sent): array
    {
        if (!is_array($table) || !is_array($table['columns'] ?? null) || !array_is_list($table['columns']) || !is_array($table['rows'] ?? null) || !array_is_list($table['rows'])) {
            throw self::malformed($sent);
        }
        $columns = [];
        foreach ($table['columns'] as $name) {
            if (!is_string($name)) {
                throw self::malformed($sent);
            }
            $columns[] = $name;
        }
        $rows = [];
        foreach ($table['rows'] as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== count($columns)) {
                throw self::malformed($sent);
            }
            $cells = [];
            foreach ($row as $cell) {
                if (!is_string($cell)) {
                    throw self::malformed($sent);
                }
                $cells[] = $cell;
            }
            $rows[] = $cells;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    private static function malformed(CorrelationId $sent): RfqParserFailure
    {
        return RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable export.', $sent);
    }
}
