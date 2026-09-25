<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One item waiting on a reviewer (4.3).
 *
 * Least privilege for the reviewer's list: who, what, since when. No email
 * address — the roster under `ONBOARDING.PEOPLE.MANAGE` has that, and a
 * reviewer deciding a form does not need to contact anybody from the queue —
 * no score, no note, no form value. The source's queue carried email, branch,
 * position and cohort on every row; everything past the name is on the item's
 * own page, one click and one permission check away.
 *
 * `subjectUserId` is here for the service and never serialised. The client is
 * told only whether the item is the viewer's own, which is what the console
 * needs to explain why it will not let them decide it.
 */
final readonly class ReviewQueueEntry
{
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function __construct(
        public string $assignmentPublicId,
        public int $subjectUserId,
        public string $personPublicId,
        public string $displayName,
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public int $versionNumber,
        public AssignmentStatus $status,
        public ?string $waitingSinceUtc,
        public ?string $dueDate,
        public ?int $revisionNumber,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $viewerUserId): array
    {
        return [
            'assignmentPublicId' => $this->assignmentPublicId,
            'personPublicId' => $this->personPublicId,
            'displayName' => $this->displayName,
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'waitingSinceUtc' => $this->waitingSinceUtc,
            'dueDate' => $this->dueDate,
            'revisionNumber' => $this->revisionNumber,
            'isOwn' => $this->subjectUserId === $viewerUserId,
        ];
    }
}
