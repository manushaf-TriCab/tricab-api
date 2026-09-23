<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One form as the person allocated it sees it: the schema to render, and their
 * own draft if they have started one.
 *
 * ## What is NOT here
 *
 * The submitted data. Once a form is handed in, the ordinary half sits in
 * `onboarding.FormSubmissions` and the payroll and restricted halves sit in
 * `[onboarding_hr]`, and reassembling them for a read-back would recreate in one
 * object precisely the thing {@see \TriCab\Core\Onboarding\Domain\Service\FormPayloadSplitter}
 * exists to prevent. A person who wants a copy of what they submitted gets the
 * generated document, through the audited document route.
 *
 * A draft is different and is here: it is the person's own scratch, it has not
 * been split yet, and losing it on a page refresh is the fastest way to make
 * somebody abandon an induction.
 *
 * ## The reviewer's note, and only while it applies
 *
 * {@see $reviewerNote} is what a reviewer wrote when they sent the form back
 * (4.3), and it is set only while the form is `fix_and_resubmit`. It is the
 * one reviewer note the person is shown, because it is the one addressed to
 * them: without it they are told "changes requested" and left to guess which.
 * Once they resubmit, it is history, and history is HR's view.
 *
 * ## The row version is the point of this object
 *
 * {@see $draftRowVersion} is the `RowVersionToken` for
 * `onboarding.FormDrafts.RowVersion`, and a save must send it back. This is the
 * one write path in Phase 3.3 where optimistic concurrency earns its keep:
 * progress and acknowledgements cannot conflict — one only grows and the other
 * happens once — but two tabs with the same form open will happily overwrite
 * each other, and the loser is somebody's twenty minutes of typing. Null means
 * no draft exists yet, so the first save is an insert.
 */
final readonly class OnboardingFormDetail
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $draftData
     */
    public function __construct(
        public string $assignmentPublicId,
        public string $title,
        public string $versionPublicId,
        public int $versionNumber,
        public AssignmentStatus $status,
        public array $schema,
        public array $draftData,
        public ?string $draftRowVersion,
        public ?string $draftUpdatedAtUtc,
        public int $submittedRevisionCount,
        public ?string $lastSubmittedAtUtc,
        public ?string $reviewerNote = null,
    ) {
    }

    public function hasDraft(): bool
    {
        return $this->draftRowVersion !== null;
    }

    public function isSubmitted(): bool
    {
        return $this->submittedRevisionCount > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'assignmentPublicId' => $this->assignmentPublicId,
            'itemType' => 'form',
            'title' => $this->title,
            'versionPublicId' => $this->versionPublicId,
            'versionNumber' => $this->versionNumber,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'schema' => $this->schema,
            'draftData' => (object) $this->draftData,
            'draftRowVersion' => $this->draftRowVersion,
            'draftUpdatedAtUtc' => $this->draftUpdatedAtUtc,
            'submittedRevisionCount' => $this->submittedRevisionCount,
            'lastSubmittedAtUtc' => $this->lastSubmittedAtUtc,
            'reviewerNote' => $this->reviewerNote,
        ];
    }
}
