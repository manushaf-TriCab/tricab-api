<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\ResearchFinding;

/**
 * The model's answer, parsed: the usable findings, each with its index in the model's own list (what the
 * assessment's `findingRefs` count), and the assessment object as the model gave it (null when it gave none).
 */
final readonly class ResearchAnswer
{
    /**
     * @param list<ResearchFinding> $findings
     * @param list<int>             $modelIndexes $modelIndexes[$i] is the model's index of $findings[$i]
     */
    public function __construct(
        public array $findings,
        public array $modelIndexes,
        public mixed $assessment,
    ) {
    }
}
