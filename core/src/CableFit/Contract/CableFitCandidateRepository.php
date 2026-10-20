<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Contract;

use TriCab\Core\CableFit\Domain\CableFitCandidate;
use TriCab\Core\CableFit\Domain\CandidateListPage;
use TriCab\Core\CableFit\Domain\CandidateSearch;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;

/**
 * Persistence for `[cablefit].candidates` and `[cablefit].candidate_revisions`.
 */
interface CableFitCandidateRepository
{
    /**
     * Candidates linked to an NPR request, most recently updated first.
     *
     * @return list<CableFitCandidate>
     */
    public function listByNprRequest(string $nprRequestPublicId): array;

    /** One page of every candidate, most recently updated first, with who made and last saved each. */
    public function search(CandidateSearch $search): CandidateListPage;

    /**
     * Every distinct NPR request public id that some candidate is linked to.
     *
     * @return list<string>
     */
    public function linkedNprRequestPublicIds(): array;

    public function findByPublicId(string $candidatePublicId): ?CableFitCandidate;

    /** The revision whose number equals the version of the candidate. */
    public function latestRevision(string $candidatePublicId): ?CandidateRevision;

    /** Insert a new candidate together with its revision 1. */
    public function create(CableFitCandidate $candidate, CandidateRevision $firstRevision): void;

    /**
     * Append a revision and advance the candidate, atomically.
     *
     * @throws CandidateVersionConflict when the stored version is not $expectedVersion
     */
    public function appendRevision(
        string $candidatePublicId,
        int $expectedVersion,
        CandidateRevision $revision,
        int $userId,
    ): CableFitCandidate;

    /** Set the stale bit of a revision (the NPR request was revised after it was built). */
    public function markRevisionStale(string $candidatePublicId, int $revisionNumber, bool $stale): void;
}
