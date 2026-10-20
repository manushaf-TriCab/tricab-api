<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * A candidate as the design list shows it: who made and last saved it, and its current revision's
 * NPR revision and stored stale bit. Names and emails are null where the user is unknown.
 */
final readonly class CandidateListRow
{
    public function __construct(
        public CableFitCandidate $candidate,
        public ?string $createdByName,
        public ?string $createdByEmail,
        public ?string $updatedByName,
        public ?string $updatedByEmail,
        public ?int $sourceNprRevision,
        public bool $storedStale,
    ) {
    }
}
