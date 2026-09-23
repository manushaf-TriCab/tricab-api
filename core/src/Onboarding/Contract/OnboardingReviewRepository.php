<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusTransition;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * The reviewer queue and the one write a reviewer makes (4.3).
 *
 * Addressed by the assignment's public id: an item in the queue is one person's
 * one piece of work, and that id names exactly it.
 */
interface OnboardingReviewRepository
{
    /**
     * Live items whose status needs review, most in need of a person first
     * ({@see \TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus::queueOrder()}),
     * then longest waiting, then assignment public id — a total order, so a page
     * boundary never moves under somebody paging through it.
     *
     * @return array{items: list<ReviewQueueEntry>, total: int}
     */
    public function listQueue(int $page, int $pageSize): array;

    public function findReviewItem(string $assignmentPublicId): ?ReviewItemRecord;

    /**
     * Any live item, queued or not — the page is reloaded after a decision, and
     * by then the item has left the queue.
     */
    public function reviewDetail(string $assignmentPublicId): ?ReviewItemDetail;

    /**
     * Moves the item, records who reviewed it, and appends both audit rows —
     * the status event (with the note) and the HR event (without it) — all or
     * none.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict when the token is stale or the item has moved
     */
    public function applyDecision(
        ReviewItemRecord $item,
        RowVersionToken $expectedRowVersion,
        StatusTransition $transition,
        OnboardingAdminEvent $event,
        ActorContext $actor,
    ): void;
}
