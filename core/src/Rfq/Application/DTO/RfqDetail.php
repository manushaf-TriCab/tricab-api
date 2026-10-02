<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * `GET /api/v1/rfqs/{publicId}` — contract `rfq.detail.v1`.
 *
 * The summary, the latest saved revision (header and lines), the documents it
 * was read from, their reads, and its sync attempts. Pricing joins in Phase 4
 * with the visibility decision (Q9); readiness joins with it, recomputed from
 * `revision` on the server.
 *
 * `revision` is null for an RFQ whose first read has not produced anything to
 * save yet.
 */
final class RfqDetail
{
    /**
     * @param list<SourceDocument> $documents
     * @param list<ReaderResult> $reads
     * @param list<SyncAttemptView> $syncAttempts
     */
    public function __construct(
        public readonly RfqSummary $summary,
        public readonly ?ReviewRevision $revision,
        public readonly array $documents,
        public readonly array $reads,
        public readonly array $syncAttempts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withPricing = false): array
    {
        return [
            ...$this->summary->toArray(),
            'latestRevision' => $this->revision?->toArray($withPricing),
            'documents' => array_map(static fn (SourceDocument $d): array => $d->toArray(), $this->documents),
            'reads' => array_map(static fn (ReaderResult $r): array => $r->toArray(), $this->reads),
            'syncAttempts' => array_map(static fn (SyncAttemptView $s): array => $s->toArray(), $this->syncAttempts),
        ];
    }
}
