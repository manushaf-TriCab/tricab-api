<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Infrastructure;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\CableFit\Contract\ResearchRunRepository;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchDailyLimitReached;
use TriCab\Core\CableFit\Domain\ResearchFinding;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchRunActive;

final class InMemoryResearchRunRepository implements ResearchRunRepository
{
    /** @var array<string, ResearchRun> by public id, in insertion order */
    private array $runs = [];

    /** @var array<string, list<ResearchFinding>> */
    private array $findings = [];

    /** Insert a run without the limit checks (test seeding; the service goes through queueWithinLimits). */
    public function add(ResearchRun $run): void
    {
        $this->runs[$run->publicId] = $run;
    }

    public function queueWithinLimits(ResearchRun $run, int $dailyCap, DateTimeImmutable $now): void
    {
        $active = $this->findActive($run->candidatePublicId);
        if ($active !== null) {
            throw new ResearchRunActive($active->publicId);
        }

        $since = $now->modify("-24 hours");
        $oldest = null;
        $count = 0;
        foreach ($this->runs as $existing) {
            if ($existing->requestedByUserId === $run->requestedByUserId && $existing->requestedAt > $since) {
                $count++;
                if ($oldest === null || $existing->requestedAt < $oldest) {
                    $oldest = $existing->requestedAt;
                }
            }
        }
        if ($count >= $dailyCap && $oldest !== null) {
            throw new ResearchDailyLimitReached($dailyCap, $oldest->modify("+24 hours"));
        }

        $this->add($run);
    }

    public function findActive(string $candidatePublicId): ?ResearchRun
    {
        foreach ($this->runs as $run) {
            if ($run->candidatePublicId === $candidatePublicId
                && ($run->status === ResearchRun::QUEUED || $run->status === ResearchRun::RUNNING)) {
                return $this->withCount($run);
            }
        }

        return null;
    }

    public function listByCandidate(string $candidatePublicId): array
    {
        $list = [];
        foreach (array_reverse($this->runs) as $run) {
            if ($run->candidatePublicId === $candidatePublicId) {
                $list[] = $this->withCount($run);
            }
        }
        usort($list, static fn (ResearchRun $a, ResearchRun $b): int => $b->requestedAt <=> $a->requestedAt);

        return $list;
    }

    public function find(string $runPublicId): ?ResearchRun
    {
        $run = $this->runs[$runPublicId] ?? null;

        return $run === null ? null : $this->withCount($run);
    }

    public function findings(string $runPublicId): array
    {
        return $this->findings[$runPublicId] ?? [];
    }

    public function claimNext(DateTimeImmutable $now, DateTimeImmutable $staleBefore): ?ResearchRun
    {
        $next = null;
        foreach ($this->runs as $run) {
            $claimable = $run->status === ResearchRun::QUEUED
                || ($run->status === ResearchRun::RUNNING && $run->startedAt !== null && $run->startedAt < $staleBefore);
            if ($claimable && ($next === null || $run->requestedAt < $next->requestedAt)) {
                $next = $run;
            }
        }
        if ($next === null) {
            return null;
        }

        return $this->runs[$next->publicId] = $this->copy($next, status: ResearchRun::RUNNING, startedAt: $now, finishedAt: null, error: null);
    }

    public function outstanding(): int
    {
        $count = 0;
        foreach ($this->runs as $run) {
            if ($run->status === ResearchRun::QUEUED || $run->status === ResearchRun::RUNNING) {
                $count++;
            }
        }

        return $count;
    }

    public function markSucceeded(string $runPublicId, array $findings, array $sourcesUsed, ResearchCost $cost, int $droppedFindings, DateTimeImmutable $at): void
    {
        $run = $this->require($runPublicId);
        $this->findings[$runPublicId] = $findings;
        $this->runs[$runPublicId] = $this->copy(
            $run,
            status: ResearchRun::SUCCEEDED,
            sourcesUsed: $sourcesUsed,
            cost: $cost,
            droppedFindings: $droppedFindings,
            error: null,
            finishedAt: $at,
        );
    }

    public function markFailed(string $runPublicId, string $error, ?ResearchCost $cost, DateTimeImmutable $at): void
    {
        $run = $this->require($runPublicId);
        $this->runs[$runPublicId] = $this->copy($run, status: ResearchRun::FAILED, cost: $cost, error: $error, finishedAt: $at);
    }

    private function require(string $runPublicId): ResearchRun
    {
        return $this->runs[$runPublicId] ?? throw new RuntimeException('Unknown research run ' . $runPublicId);
    }

    private function withCount(ResearchRun $run): ResearchRun
    {
        return $this->copy($run, findingCount: count($this->findings[$run->publicId] ?? []));
    }

    /**
     * @param list<string>|null $sourcesUsed
     */
    private function copy(
        ResearchRun $run,
        ?string $status = null,
        ?array $sourcesUsed = null,
        ?ResearchCost $cost = null,
        ?int $droppedFindings = null,
        ?DateTimeImmutable $startedAt = null,
        ?int $findingCount = null,
        string|false|null $error = false,
        DateTimeImmutable|false|null $finishedAt = false,
    ): ResearchRun {
        return new ResearchRun(
            $run->publicId,
            $run->candidatePublicId,
            $status ?? $run->status,
            $run->application,
            $run->parameters,
            $sourcesUsed ?? $run->sourcesUsed,
            $cost !== null && $cost->model !== '' ? $cost->model : $run->model,
            $cost !== null ? $cost->inputTokens : $run->inputTokens,
            $cost !== null ? $cost->outputTokens : $run->outputTokens,
            $cost !== null ? $cost->searchRequests : $run->searchRequests,
            $droppedFindings ?? $run->droppedFindings,
            $error === false ? $run->error : $error,
            $run->requestedByUserId,
            $run->requestedByName,
            $run->requestedAt,
            $startedAt ?? $run->startedAt,
            $finishedAt === false ? $run->finishedAt : $finishedAt,
            $findingCount ?? $run->findingCount,
        );
    }
}
