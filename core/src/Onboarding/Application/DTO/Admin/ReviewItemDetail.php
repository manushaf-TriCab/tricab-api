<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\Service\ReviewDecisionPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One item as a reviewer opens it: the evidence, the history, and the
 * decisions on offer.
 *
 * ## The evidence, by item type
 *
 *  - **form** — the latest revision, each field beside its label, payroll and
 *    restricted fields named and withheld ({@see \TriCab\Core\Onboarding\Domain\Service\ReviewFieldProjection}).
 *  - **training** — each submitted attempt's score and result. Not the answers:
 *    the grade was derived server-side from the question snapshot (invariant 2),
 *    and a reviewer re-marking answers by eye is a second, unrecorded grading.
 *  - **policy** — when it was acknowledged.
 *
 * ## `outcomes` comes from the domain, not the page
 *
 * {@see ReviewDecisionPolicy::outcomesFrom()} decides what the console offers,
 * so the buttons a reviewer sees are the decisions the API will accept. An
 * empty list is the normal state of an item that has already been decided.
 */
final readonly class ReviewItemDetail
{
    /**
     * @param list<array{fromStatus: ?string, toStatus: string, actorType: string, actorDisplayName: ?string, note: ?string, atUtc: string}> $history newest first
     * @param ?array{revisionNumber: int, submittedAtUtc: string, hasRestrictedData: bool, fields: list<array{path: string, label: string, value: ?string, withheld: bool}>} $formSubmission
     * @param list<array{attemptNumber: int, submittedAtUtc: ?string, scorePercent: ?int, isPass: ?bool}> $attempts
     */
    public function __construct(
        public ReviewQueueEntry $entry,
        public string $rowVersion,
        public ?string $position,
        public ?string $department,
        public ?string $branch,
        public ?string $submittedAtUtc,
        public ?string $completedAtUtc,
        public array $history,
        public ?array $formSubmission,
        public array $attempts,
        public ?string $acknowledgedAtUtc,
    ) {
    }

    public function status(): AssignmentStatus
    {
        return $this->entry->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(int $viewerUserId): array
    {
        $isOwn = $this->entry->subjectUserId === $viewerUserId;

        return [
            'item' => $this->entry->toArray($viewerUserId),
            'rowVersion' => $this->rowVersion,
            'person' => [
                'publicId' => $this->entry->personPublicId,
                'displayName' => $this->entry->displayName,
                'position' => $this->position,
                'department' => $this->department,
                'branch' => $this->branch,
            ],
            'submittedAtUtc' => $this->submittedAtUtc,
            'completedAtUtc' => $this->completedAtUtc,
            // Nothing is offered on one's own work. The API refuses it anyway;
            // offering buttons that can only fail is the page lying.
            'outcomes' => $isOwn ? [] : array_map(
                static fn (AssignmentStatus $to): array => [
                    'status' => $to->toString(),
                    'label' => $to->label(),
                    'requiresNote' => ReviewDecisionPolicy::requiresNote($to),
                ],
                ReviewDecisionPolicy::outcomesFrom($this->entry->status),
            ),
            'history' => $this->history,
            'formSubmission' => $this->formSubmission,
            'attempts' => $this->attempts,
            'acknowledgedAtUtc' => $this->acknowledgedAtUtc,
        ];
    }
}
