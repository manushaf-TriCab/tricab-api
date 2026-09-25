<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * A live (not withdrawn) assignment as HR sees it.
 *
 * `hasEvidence` is whether anything has been recorded against it — presentation
 * progress, an attempt, an acknowledgement, a draft or a submission. It is what
 * decides whether unticking may withdraw it (work order invariant 9), so it is
 * computed in SQL from the evidence tables rather than guessed from the status:
 * a `not_started` assignment with a saved draft has been touched.
 */
final readonly class CurrentAllocation
{
    public function __construct(
        public int $assignmentId,
        public string $assignmentPublicId,
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public int $versionNumber,
        public AssignmentStatus $status,
        public ?string $dueDate,
        public bool $hasEvidence,
        public string $allocatedAtUtc,
    ) {
    }

    public function key(): string
    {
        return $this->itemType . ':' . strtolower($this->itemPublicId);
    }

    /**
     * Untouched: nothing recorded against it, and nobody has moved it on.
     */
    public function isUntouched(): bool
    {
        return !$this->hasEvidence && $this->status->toString() === AssignmentStatus::NOT_STARTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignmentPublicId' => $this->assignmentPublicId,
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'dueDate' => $this->dueDate,
            'hasEvidence' => $this->hasEvidence,
            'allocatedAtUtc' => $this->allocatedAtUtc,
        ];
    }
}
