<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One allocated item, as the person it was allocated to sees it.
 *
 * A read projection, not an entity. It carries what the trainee's own worklist
 * needs to render and refuses to carry anything else — no internal keys, no
 * reviewer notes, no score, no form payload. Work order §8 asks for explicit
 * response projections per audience, and the cheapest way to keep a guest view
 * free of HR data is for the object the guest endpoint builds to have nowhere
 * to put it.
 *
 * Identifiers are public ids. `AssignmentId` and the version table keys never
 * leave the repository: the platform convention is that a database primary key
 * is not an API value, and here it is also the difference between an opaque
 * handle and a number a client can iterate.
 */
final readonly class OnboardingAssignmentView
{
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    private function __construct(
        public string $publicId,
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public int $versionNumber,
        public AssignmentStatus $status,
        public ?string $dueDate,
        public string $allocatedAtUtc,
        public ?string $submittedAtUtc,
        public ?string $completedAtUtc,
    ) {
    }

    /**
     * Builds from storage, forgivingly where forgiveness is safe.
     *
     * The status goes through {@see AssignmentStatus::fromStorage()} rather than
     * `fromString()` deliberately: a row somebody hand-edited must not make a
     * person's whole induction unreadable, because they would be locked out by a
     * data defect they can neither see nor fix. The item type is NOT forgiving —
     * there is no safe default for "what kind of thing is this", and guessing
     * would render a policy as a training module.
     */
    public static function fromStorage(
        string $publicId,
        string $itemType,
        string $itemPublicId,
        string $title,
        int $versionNumber,
        mixed $status,
        ?string $dueDate,
        string $allocatedAtUtc,
        ?string $submittedAtUtc,
        ?string $completedAtUtc,
    ): self {
        $normalizedType = strtolower(trim($itemType));

        if ($normalizedType !== 'training' && $normalizedType !== 'policy' && $normalizedType !== 'form') {
            throw InvalidOnboardingValue::forOneOf(
                'onboarding item type',
                $itemType,
                ['training', 'policy', 'form'],
            );
        }

        return new self(
            $publicId,
            $normalizedType,
            $itemPublicId,
            $title,
            $versionNumber,
            AssignmentStatus::fromStorage($status),
            $dueDate,
            $allocatedAtUtc,
            $submittedAtUtc,
            $completedAtUtc,
        );
    }

    /** Still on this person's plate, as opposed to handed in or finished. */
    public function isActionable(): bool
    {
        return $this->status->isToBeWorkedOn();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'dueDate' => $this->dueDate,
            'allocatedAtUtc' => $this->allocatedAtUtc,
            'submittedAtUtc' => $this->submittedAtUtc,
            'completedAtUtc' => $this->completedAtUtc,
        ];
    }
}
