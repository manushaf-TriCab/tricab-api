<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * `POST /api/v1/rfqs` — the draft a paste created. Contract `rfq.created.v1`.
 *
 * The RFQ itself is read from `GET /api/v1/rfqs/{publicId}`; this says only
 * what the parse was like, so the editor can say so before the rep starts:
 * rows that did not balance (`coverageComplete` false: a line went missing),
 * and a degraded parse (a price lookup failed) with the parser's notes.
 */
final class CreatedRfq
{
    /**
     * @param array{inputRows: int, keptRows: int, excludedRows: int, skippedRows: int} $coverage
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $publicId,
        public readonly int $lineCount,
        public readonly array $coverage,
        public readonly bool $coverageComplete,
        public readonly bool $degraded,
        public readonly array $notes,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'lineCount' => $this->lineCount,
            'coverage' => $this->coverage,
            'coverageComplete' => $this->coverageComplete,
            'degraded' => $this->degraded,
            'notes' => $this->notes,
        ];
    }
}
