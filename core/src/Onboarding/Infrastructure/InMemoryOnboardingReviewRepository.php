<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Contract\OnboardingReviewRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Test double and the container default, as the other Onboarding repositories
 * are. Keeps every decision it was handed so a test can read back exactly what
 * would have been written.
 */
final class InMemoryOnboardingReviewRepository implements OnboardingReviewRepository
{
    /** @var array<string, ReviewItemRecord> */
    private array $records = [];

    /** @var array<string, ReviewQueueEntry> */
    private array $entries = [];

    /** @var array<string, ?array{revisionNumber: int, submittedAtUtc: string, hasRestrictedData: bool, fields: list<array{path: string, label: string, value: ?string, withheld: bool}>}> */
    private array $submissions = [];

    /** @var list<array{transition: StatusTransition, event: OnboardingAdminEvent, actorUserId: int}> */
    public array $decisions = [];

    /**
     * @param ?array{revisionNumber: int, submittedAtUtc: string, hasRestrictedData: bool, fields: list<array{path: string, label: string, value: ?string, withheld: bool}>} $formSubmission
     */
    public function withItem(ReviewItemRecord $record, ReviewQueueEntry $entry, ?array $formSubmission = null): void
    {
        $this->records[$record->assignmentPublicId] = $record;
        $this->entries[$record->assignmentPublicId] = $entry;
        $this->submissions[$record->assignmentPublicId] = $formSubmission;
    }

    public function listQueue(int $page, int $pageSize): array
    {
        $queued = array_values(array_filter(
            array_map(fn (string $id): ReviewQueueEntry => $this->current($id), array_keys($this->entries)),
            static fn (ReviewQueueEntry $e): bool => $e->status->needsReview(),
        ));
        usort($queued, static fn (ReviewQueueEntry $a, ReviewQueueEntry $b): int
            => [$a->status->queueOrder(), $a->waitingSinceUtc ?? '~', $a->assignmentPublicId]
            <=> [$b->status->queueOrder(), $b->waitingSinceUtc ?? '~', $b->assignmentPublicId]);

        return [
            'items' => array_slice($queued, ($page - 1) * $pageSize, $pageSize),
            'total' => count($queued),
        ];
    }

    public function findReviewItem(string $assignmentPublicId): ?ReviewItemRecord
    {
        return $this->records[$assignmentPublicId] ?? null;
    }

    public function reviewDetail(string $assignmentPublicId): ?ReviewItemDetail
    {
        $record = $this->records[$assignmentPublicId] ?? null;
        if ($record === null) {
            return null;
        }

        $history = [];
        foreach (array_reverse($this->decisions) as $decision) {
            if ($decision['event']->targetPublicId !== $assignmentPublicId) {
                continue;
            }
            $history[] = [
                'fromStatus' => $decision['transition']->from->toString(),
                'toStatus' => $decision['transition']->to->toString(),
                'actorType' => $decision['transition']->actor->toString(),
                'actorDisplayName' => null,
                'note' => $decision['transition']->note,
                'atUtc' => '2026-09-23T00:00:00Z',
            ];
        }

        return new ReviewItemDetail(
            $this->current($assignmentPublicId),
            $record->rowVersion->toBase64(),
            null,
            null,
            null,
            null,
            null,
            $history,
            $this->submissions[$assignmentPublicId] ?? null,
            [],
            null,
        );
    }

    public function applyDecision(
        ReviewItemRecord $item,
        RowVersionToken $expectedRowVersion,
        StatusTransition $transition,
        OnboardingAdminEvent $event,
        ActorContext $actor,
    ): void {
        $stored = $this->records[$item->assignmentPublicId] ?? null;
        if (
            $stored === null
            || !$stored->rowVersion->equals($expectedRowVersion)
            || !$stored->status->equals($transition->from)
        ) {
            throw OnboardingWriteConflict::staleToken();
        }

        $this->decisions[] = ['transition' => $transition, 'event' => $event, 'actorUserId' => $actor->userId];

        // A new token, as ROWVERSION would give.
        $this->records[$item->assignmentPublicId] = new ReviewItemRecord(
            $stored->assignmentId,
            $stored->assignmentPublicId,
            $stored->subjectUserId,
            $stored->itemType,
            $stored->itemPublicId,
            $stored->title,
            $transition->to,
            RowVersionToken::fromBinary(random_bytes(8)),
            $stored->latestRevisionNumber,
        );
    }

    /** The entry with the record's current status, which a decision moves. */
    private function current(string $assignmentPublicId): ReviewQueueEntry
    {
        $entry = $this->entries[$assignmentPublicId];
        $status = $this->records[$assignmentPublicId]->status;

        return new ReviewQueueEntry(
            $entry->assignmentPublicId,
            $entry->subjectUserId,
            $entry->personPublicId,
            $entry->displayName,
            $entry->itemType,
            $entry->itemPublicId,
            $entry->title,
            $entry->versionNumber,
            $status,
            $entry->waitingSinceUtc,
            $entry->dueDate,
            $entry->revisionNumber,
        );
    }
}
