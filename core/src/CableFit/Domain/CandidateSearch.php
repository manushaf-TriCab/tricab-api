<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * One page of the list of every candidate. With no text, every candidate matches; with text, a
 * candidate matches when its name contains the text or it is linked to one of the NPR requests
 * (the caller has already matched the text against NPR numbers and customers).
 */
final readonly class CandidateSearch
{
    /**
     * @param list<string> $nprRequestPublicIds
     */
    public function __construct(
        public ?string $text,
        public array $nprRequestPublicIds,
        public int $limit,
        public int $offset,
    ) {
    }
}
