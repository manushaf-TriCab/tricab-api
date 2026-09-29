<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * `POST /api/v1/rfqs/upload` — the draft an upload created. Contract
 * `rfq.uploaded.v1`: `rfq.created.v1` plus `reads`, one per file, saying
 * whether a model reader is re-reading it in the background (plan 3.5).
 */
final class UploadedRfq
{
    /**
     * @param array{inputRows: int, keptRows: int, excludedRows: int, skippedRows: int, measured: bool} $coverage
     * @param list<string>                                  $notes
     * @param list<array{fileName: string, queued: bool}>   $reads
     */
    public function __construct(
        public readonly string $publicId,
        public readonly int $lineCount,
        public readonly array $coverage,
        public readonly bool $coverageComplete,
        public readonly bool $degraded,
        public readonly array $notes,
        public readonly array $reads,
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
            'reads' => $this->reads,
        ];
    }
}
