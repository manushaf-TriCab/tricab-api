<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Contract;

use DateTimeImmutable;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchRun;

/**
 * Persistence for `[cablefit].research_runs` and `[cablefit].research_findings`.
 */
interface ResearchRunRepository
{
    /** Insert a queued run. */
    public function add(ResearchRun $run): void;

    /**
     * Runs of a candidate, newest first, with their finding counts.
     *
     * @return list<ResearchRun>
     */
    public function listByCandidate(string $candidatePublicId): array;

    public function find(string $runPublicId): ?ResearchRun;

    /**
     * @return list<ResearchFinding> in saved order
     */
    public function findings(string $runPublicId): array;

    /**
     * Claim the oldest queued run, or a running one whose claim is older than $staleBefore (a crashed
     * worker), by moving it to running. Two workers never get the same run.
     */
    public function claimNext(DateTimeImmutable $now, DateTimeImmutable $staleBefore): ?ResearchRun;

    /** Runs still queued or running; zero lets the worker go back to waiting for a wake-up. */
    public function outstanding(): int;

    /**
     * Save the findings and mark the run succeeded, together.
     *
     * @param list<ResearchFinding> $findings
     * @param list<string>          $sourcesUsed
     */
    public function markSucceeded(
        string $runPublicId,
        array $findings,
        array $sourcesUsed,
        ResearchCost $cost,
        int $droppedFindings,
        DateTimeImmutable $at,
    ): void;

    public function markFailed(string $runPublicId, string $error, ?ResearchCost $cost, DateTimeImmutable $at): void;
}
