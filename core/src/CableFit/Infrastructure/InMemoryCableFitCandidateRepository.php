<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use TriCab\Core\CableFit\Contract\CableFitCandidateRepository;
use TriCab\Core\CableFit\Domain\CableFitCandidate;
use TriCab\Core\CableFit\Domain\CandidateListPage;
use TriCab\Core\CableFit\Domain\CandidateListRow;
use TriCab\Core\CableFit\Domain\CandidateSearch;
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

    /** User names are not known here, so they are null. */
    public function search(CandidateSearch $search): CandidateListPage
    {
        $text = $search->text === null ? null : mb_strtolower($search->text);
        $linked = array_flip($search->nprRequestPublicIds);
        $list = array_values(array_filter(
            $this->candidates,
            static fn (CableFitCandidate $c): bool => $text === null
                || str_contains(mb_strtolower($c->name), $text)
                || ($c->nprRequestPublicId !== null && isset($linked[$c->nprRequestPublicId])),
        ));
        // Newest first; among equal times, the later created (array order) first, like candidate_id DESC.
        $order = array_flip(array_keys($this->candidates));
        usort($list, static fn (CableFitCandidate $a, CableFitCandidate $b): int => [$b->updatedAt, $order[$b->publicId]] <=> [$a->updatedAt, $order[$a->publicId]]);

        $rows = [];
        foreach (array_slice($list, $search->offset, $search->limit) as $candidate) {
            $revision = $this->latestRevision($candidate->publicId);
            $rows[] = new CandidateListRow($candidate, null, null, null, null, $revision?->sourceNprRevision, $revision !== null && $revision->stale);
        }

        return new CandidateListPage($rows, count($list));
    }

    public function linkedNprRequestPublicIds(): array
    {
        $ids = [];
        foreach ($this->candidates as $candidate) {
            if ($candidate->nprRequestPublicId !== null) {
                $ids[] = $candidate->nprRequestPublicId;
            }
        }

        return array_values(array_unique($ids));
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
