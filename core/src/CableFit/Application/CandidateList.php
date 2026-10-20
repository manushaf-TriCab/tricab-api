<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

/**
 * A page of the design list and how many designs match in all.
 */
final readonly class CandidateList
{
    /**
     * @param list<CandidateListItem> $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {
    }
}
