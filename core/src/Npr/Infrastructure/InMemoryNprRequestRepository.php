<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Infrastructure;

use TriCab\Core\Npr\Contract\NprEventSink;
use TriCab\Core\Npr\Contract\NprRequestRepository;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;
use TriCab\Core\Npr\Domain\NewNprRequest;
use TriCab\Core\Npr\Domain\NprDraftChange;
use TriCab\Core\Npr\Domain\NprRequest;
use TriCab\Core\Npr\Domain\NprTransition;
use TriCab\Core\Npr\Domain\NprWorkflowEvent;
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

    /** @var array<string, list<RecordedActivity>> */
    private array $activity = [];

    /** @var array<string, list<RecordedEndorsement>> */
    private array $endorsements = [];

    private int $sequence = 0;
    private int $versionCounter = 0;

    private ?NprEventSink $events;

    public function __construct(private readonly Clock $clock = new SystemClock(), ?NprEventSink $events = null)
    {
        $this->events = $events;
    }

    /** Test seam: the activity row a collaborator (attachments) adds to a request. */
    public function appendActivity(string $publicId, RecordedActivity $activity): void
    {
        $this->activity[strtolower($publicId)][] = $activity;
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
        $saved = $current->with([
            'revision' => $revision,
            'title' => $change->title,
            'customerName' => $change->customerName,
            'urgencyLevel' => $change->urgencyLevel,
            'answers' => $change->answers,
            'variants' => $change->variants,
            'version' => $this->nextVersion(),
            'updatedAt' => $this->clock->now(),
        ]);

        return $this->store($saved);
    }

    public function listForManager(int $userId): array
    {
        return $this->newestFirst(array_filter(
            $this->requests,
            static fn (NprRequest $request): bool => $request->isManagedBy($userId) && $request->status !== RequestStatus::Draft,
        ));
    }

    public function transition(string $publicId, string $expectedVersion, NprTransition $transition): ?NprRequest
    {
        $current = $this->find($publicId);
        if ($current === null) {
            return null;
        }
        if ($current->version !== $expectedVersion) {
            throw new NprRequestConflict($current->version);
        }
        if (!in_array($current->status, $transition->from, true)) {
            throw NprWorkflowRefused::notAllowedIn($current->status->value);
        }

        $now = $this->clock->now();
        $changes = ['version' => $this->nextVersion(), 'updatedAt' => $now];
        if ($transition->to !== null) {
            $changes['status'] = $transition->to;
        }
        if ($transition->assignManager !== null) {
            $changes['manager'] = $transition->assignManager;
        }
        if ($transition->assignTsdOwner !== null) {
            $changes['tsdOwner'] = $transition->assignTsdOwner;
        }
        if ($transition->assignSignatory !== null) {
            $changes['signatory'] = $transition->assignSignatory;
        }
        if ($transition->markSubmitted) {
            $changes['submittedAt'] = $now;
        }
        if ($transition->markEndorsed) {
            $changes['endorsedAt'] = $now;
            $changes['tsdReceivedAt'] = $current->tsdReceivedAt ?? $now;
        }
        if ($transition->markAcknowledged) {
            $changes['tsdAcknowledgedAt'] = $now;
            $changes['tsdAcknowledgedByName'] = $transition->actor->name;
        }
        if ($transition->decision !== null) {
            $changes['screeningOutcome'] = $transition->decision->outcome;
            $changes['screeningDecidedAt'] = $now;
            $changes['screeningRationale'] = $transition->decision->rationale;
            $changes['screeningConditions'] = $transition->decision->conditions;
        }
        if ($transition->answerPatch !== []) {
            $changes['answers'] = array_merge($current->answers, $transition->answerPatch);
        }

        $saved = $current->with($changes);

        // The sink runs before anything is kept, as it does inside the SQL transaction: if it throws,
        // the transition did not happen (no header change, no endorsement, no activity).
        $event = NprWorkflowEvent::committed($transition, $current->status, $saved);
        if ($event !== null && $this->events !== null) {
            $this->events->record($event, $saved, count($this->activity[$current->publicId] ?? []) + 1, 0);
        }
        if ($transition->endorsement !== null) {
            $this->endorsements[$current->publicId][] = new RecordedEndorsement(
                $current->revision,
                $transition->endorsement['action'],
                $transition->actor,
                $transition->endorsement['declaration'],
                $transition->endorsement['comment'],
                $now,
            );
        }
        $this->activity[$current->publicId][] = new RecordedActivity(
            $current->revision,
            $transition->actor,
            $transition->actorRole,
            $transition->action,
            $current->status,
            $transition->to ?? $current->status,
            $transition->summary,
            $transition->payload,
            $now,
        );

        return $this->store($saved);
    }

    /**
     * Test seam: the Activity rows of a request, oldest first.
     *
     * @return list<RecordedActivity>
     */
    public function activityLog(string $publicId): array
    {
        return $this->activity[strtolower($publicId)] ?? [];
    }

    /**
     * Test seam: the Endorsements rows of a request, oldest first.
     *
     * @return list<RecordedEndorsement>
     */
    public function endorsementLog(string $publicId): array
    {
        return $this->endorsements[strtolower($publicId)] ?? [];
    }

    /** Test seam: the newest Activity row of a request. */
    public function lastActivity(string $publicId): RecordedActivity
    {
        return ($this->activityLog($publicId)[count($this->activityLog($publicId)) - 1] ?? throw new \LogicException('No activity recorded.'));
    }

    /** Test seam: the newest Endorsements row of a request. */
    public function lastEndorsement(string $publicId): RecordedEndorsement
    {
        return ($this->endorsementLog($publicId)[count($this->endorsementLog($publicId)) - 1] ?? throw new \LogicException('No endorsement recorded.'));
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
