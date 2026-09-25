<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Report;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One piece of work past its due date (4.5): who, what, since when.
 *
 * The least a person chasing it needs. No email address or phone — the People
 * screen has those under `ONBOARDING.PEOPLE.MANAGE`, and holding
 * `ONBOARDING.REPORTS.READ` alone should not turn a report into a contact
 * list. No score, note or answer. `personPublicId` is there so a viewer who
 * does hold PEOPLE.MANAGE can be offered the link; it opens nothing by itself.
 *
 * `displayName` is null when the account has none. The report does not fall
 * back to the email address, as the roster does, because this is not the roster.
 */
final readonly class OverdueWorkRow
{
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function __construct(
        public string $personPublicId,
        public ?string $displayName,
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public AssignmentStatus $status,
        public string $dueDate,
        public int $daysOverdue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'personPublicId' => $this->personPublicId,
            'displayName' => $this->displayName,
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'status' => $this->status->toString(),
            'statusLabel' => $this->status->label(),
            'dueDate' => $this->dueDate,
            'daysOverdue' => $this->daysOverdue,
        ];
    }
}
