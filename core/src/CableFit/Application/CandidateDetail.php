<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Application;

use TriCab\Core\CableFit\Domain\CableFitCandidate;

/**
 * A candidate as returned to the API: latest design plus stale flag,
 * provenance and unresolved requirements.
 */
final readonly class CandidateDetail
{
    /**
     * @param array<string, mixed>       $construction
     * @param array<string, mixed>       $provenance
     * @param list<array<string, mixed>> $unresolvedRequirements
     * @param array<string, mixed>       $chosenAlternatives
     */
    public function __construct(
        public CableFitCandidate $candidate,
        public int $revisionNumber,
        public string $mappingVersion,
        public ?int $sourceNprRevision,
        public ?int $currentNprRevision,
        public bool $stale,
        public ?string $staleReason,
        public array $construction,
        public array $provenance,
        public array $unresolvedRequirements,
        public array $chosenAlternatives,
        public bool $canDelete = false,
    ) {
    }
}
