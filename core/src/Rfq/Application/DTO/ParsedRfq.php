<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * `POST /api/v1/rfqs/parse` — Match Text's answer. Contract `rfq.parsed.v1`.
 *
 * Nothing is stored (parity plan D3). `lines` are `rfq.line.v1`, each already
 * carrying the `lineUid` it keeps once saved, so the editor's decisions name
 * the same lines before and after. `parseToken` is the sealed parse
 * ({@see \TriCab\Core\Rfq\Parser\ParseSeal}) that "Save as Draft" sends back.
 * `coverageComplete` false means a pasted line went missing; `degraded`
 * means a price lookup failed while reading, with the parser's notes.
 */
final class ParsedRfq
{
    /**
     * @param list<ParsedLine> $lines
     * @param array{inputRows: int, keptRows: int, excludedRows: int, skippedRows: int} $coverage
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $parseToken,
        public readonly array $lines,
        public readonly array $coverage,
        public readonly bool $coverageComplete,
        public readonly bool $degraded,
        public readonly array $notes,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withPricing = false): array
    {
        return [
            'parseToken' => $this->parseToken,
            'lines' => array_map(static fn (ParsedLine $line): array => $line->toArray($withPricing), $this->lines),
            'coverage' => $this->coverage,
            'coverageComplete' => $this->coverageComplete,
            'degraded' => $this->degraded,
            'notes' => $this->notes,
        ];
    }
}
