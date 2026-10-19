<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\RequestStatus;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * The repository's rules held in memory: the default binding and the test
 * double. It keeps every revision, as the table does.
 */
final class InMemoryNprRequestRepository implements NprRequestRepository
{
    /** @var array<string, NprRequest> */
    private array $requests = [];

    /** @var array<string, array<int, array{answers: array<string, mixed>, variants: list<array<string, mixed>>}>> */
    private array $revisions = [];

    private int $sequence = 0;
    private int $versionCounter = 0;

    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    /** Test seam: put a record in as it stands, in any status. */
    public function withRequest(NprRequest $request): self
    {
        $this->requests[$request->publicId] = $request;
        $this->revisions[$request->publicId][$request->revision] = ['answers' => $request->answers, 'variants' => $request->variants];

        return $this;
    }

    /** Test seam: every stored revision number of a request. @return list<int> */
    public function revisionNumbers(string $publicId): array
    {
        return array_keys($this->revisions[$publicId] ?? []);
    }

    public function create(NewNprRequest $draft): NprRequest
    {
        $now = $this->clock->now();
        $this->sequence++;
        $publicId = sprintf('00000000-0000-4000-8000-%012d', $this->sequence);
        $request = new NprRequest(
            $publicId,
            sprintf('NPR-%s-%04d', $now->format('Y'), $this->sequence),
            1,
            RequestStatus::Draft,
            $draft->title,
            $draft->customerName,
            $draft->urgencyLevel,
            $draft->requesterUserId,
            strtolower($draft->requesterEmail),
            $draft->requesterName,
            $draft->requesterBranch,
            $draft->answers,
            $draft->variants,
            $this->nextVersion(),
            $now,
            $now,
        );

        return $this->store($request);
    }

    public function find(string $publicId): ?NprRequest
    {
        return $this->requests[strtolower($publicId)] ?? null;
    }

    public function listForRequester(int $userId): array
    {
        return $this->newestFirst(array_filter(
            $this->requests,
            static fn (NprRequest $request): bool => $request->isOwnedBy($userId),
        ));
    }

    public function listForTsd(): array
    {
        return $this->newestFirst(array_filter(
            $this->requests,
            static fn (NprRequest $request): bool => $request->isVisibleToTsd(),
        ));
    }

    public function saveDraft(string $publicId, string $expectedVersion, NprDraftChange $change): ?NprRequest
    {
        $current = $this->find($publicId);
        if ($current === null) {
            return null;
        }
        if ($current->version !== $expectedVersion) {
            throw new NprRequestConflict($current->version);
        }
        if (!$current->status->isEditableBySales()) {
            throw new NprRequestNotEditable($current->status->value);
        }

        $revision = $current->status === RequestStatus::ReturnedToSales ? $current->revision + 1 : $current->revision;
        $saved = new NprRequest(
            $current->publicId,
            $current->requestNumber,
            $revision,
            $current->status,
            $change->title,
            $change->customerName,
            $change->urgencyLevel,
            $current->requesterUserId,
            $current->requesterEmail,
            $current->requesterName,
            $current->requesterBranch,
            $change->answers,
            $change->variants,
            $this->nextVersion(),
            $current->createdAt,
            $this->clock->now(),
            $current->submittedAt,
            $current->screeningOutcome,
            $current->tsdReceivedAt,
        );

        return $this->store($saved);
    }

    private function store(NprRequest $request): NprRequest
    {
        $this->requests[$request->publicId] = $request;
        $this->revisions[$request->publicId][$request->revision] = ['answers' => $request->answers, 'variants' => $request->variants];

        return $request;
    }

    private function nextVersion(): string
    {
        $this->versionCounter++;

        return sprintf('0x%016X', $this->versionCounter);
    }

    /**
     * @param array<string, NprRequest> $requests
     * @return list<NprRequest>
     */
    private function newestFirst(array $requests): array
    {
        $list = array_values($requests);
        usort($list, static fn (NprRequest $a, NprRequest $b): int => $b->updatedAt <=> $a->updatedAt ?: strcmp($b->requestNumber, $a->requestNumber));

        return $list;
    }
}
