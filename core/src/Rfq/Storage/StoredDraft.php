<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

/**
 * One draft as stored: the `rfq_draft_quotes` row with its JSON decoded, and
 * its lines in order. The source's `get_draft`, less the derived parts
 * (readiness, allowed actions), which belong to the services that own them.
 */
final class StoredDraft
{
    /**
     * @param array<string, mixed>       $columns      the `rfq_draft_quotes` row, text as stored
     * @param array<string, mixed>       $quoteDetails
     * @param array<string, mixed>       $emailMeta
     * @param list<array<string, mixed>> $rows         `row_data`, with `_draft_row_id` and `_system_snapshot_valid` as the source adds them
     * @param list<array<string, mixed>> $systemRows   `system_row_data`, the parser's answer per line
     */
    public function __construct(
        public readonly string $id,
        public readonly int $revision,
        public readonly string $status,
        public readonly array $columns,
        public readonly array $quoteDetails,
        public readonly array $emailMeta,
        public readonly array $rows,
        public readonly array $systemRows,
    ) {
    }
}
