<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

use DateTimeImmutable;

/**
 * Row of `[cablefit].candidate_revisions`.
 */
final readonly class CandidateRevision
{
    /**
     * @param array<string, mixed> $design Keys: construction, provenance, unresolvedRequirements, chosenAlternatives.
     */
    public function __construct(
        public string $candidatePublicId,
        public int $revisionNumber,
        public array $design,
        public string $mappingVersion,
        public bool $stale,
        public ?int $sourceNprRevision,
        public DateTimeImmutable $createdAt,
        public int $createdByUserId,
    ) {
    }
}
