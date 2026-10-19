<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use TriCab\Core\CableFit\Contract\CableFitCandidateRepository;
use TriCab\Core\CableFit\Domain\CableFitCandidate;
use TriCab\Core\CableFit\Domain\CandidateRevision;
use TriCab\Core\CableFit\Domain\CandidateVersionConflict;

final class InMemoryCableFitCandidateRepository implements CableFitCandidateRepository
{
    /** @var array<string, CableFitCandidate> */
    private array $candidates = [];

    /** @var array<string, array<int, CandidateRevision>> */
    private array $revisions = [];

    public function listByNprRequest(string $nprRequestPublicId): array
    {
        $list = array_values(array_filter(
            $this->candidates,
            static fn (CableFitCandidate $c): bool => $c->nprRequestPublicId === $nprRequestPublicId,
        ));
        usort($list, static fn (CableFitCandidate $a, CableFitCandidate $b): int => $b->updatedAt <=> $a->updatedAt);

        return $list;
    }

    public function findByPublicId(string $candidatePublicId): ?CableFitCandidate
    {
        return $this->candidates[$candidatePublicId] ?? null;
    }

    public function latestRevision(string $candidatePublicId): ?CandidateRevision
    {
        $candidate = $this->candidates[$candidatePublicId] ?? null;

        return $candidate === null ? null : ($this->revisions[$candidatePublicId][$candidate->version] ?? null);
    }

    public function create(CableFitCandidate $candidate, CandidateRevision $firstRevision): void
    {
        $this->candidates[$candidate->publicId] = $candidate;
        $this->revisions[$candidate->publicId] = [$firstRevision->revisionNumber => $firstRevision];
    }

    public function appendRevision(string $candidatePublicId, int $expectedVersion, CandidateRevision $revision, int $userId): CableFitCandidate
    {
        $candidate = $this->candidates[$candidatePublicId] ?? null;
        if ($candidate === null) {
            throw new \RuntimeException('Unknown candidate ' . $candidatePublicId);
        }
        if ($candidate->version !== $expectedVersion || $revision->revisionNumber !== $expectedVersion + 1) {
            throw new CandidateVersionConflict($candidatePublicId, $expectedVersion, $candidate->version);
        }
        $this->revisions[$candidatePublicId][$revision->revisionNumber] = $revision;

        return $this->candidates[$candidatePublicId] = $candidate->withNewVersion(
            $revision->revisionNumber,
            $revision->createdAt,
            $userId,
        );
    }

    public function markRevisionStale(string $candidatePublicId, int $revisionNumber, bool $stale): void
    {
        $r = $this->revisions[$candidatePublicId][$revisionNumber] ?? null;
        if ($r === null) {
            return;
        }
        $this->revisions[$candidatePublicId][$revisionNumber] = new CandidateRevision(
            $r->candidatePublicId,
            $r->revisionNumber,
            $r->design,
            $r->mappingVersion,
            $stale,
            $r->sourceNprRevision,
            $r->createdAt,
            $r->createdByUserId,
        );
    }

    /** Number of stored revisions for a candidate (test helper). */
    public function revisionCount(string $candidatePublicId): int
    {
        return count($this->revisions[$candidatePublicId] ?? []);
    }
}
