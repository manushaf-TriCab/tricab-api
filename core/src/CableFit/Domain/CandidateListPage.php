<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * One page of {@see CandidateListRow}s, newest `updated_at` first, and how many match in all.
 */
final readonly class CandidateListPage
{
    /**
     * @param list<CandidateListRow> $rows
     */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
