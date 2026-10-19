<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/** The expected version supplied by the caller is not the current version. */
final class CandidateVersionConflict extends CableFitException
{
    public function __construct(
        public readonly string $candidatePublicId,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
    ) {
        parent::__construct(sprintf(
            'Candidate %s is at version %d, not the expected %d.',
            $candidatePublicId,
            $currentVersion,
            $expectedVersion,
        ));
    }
}
