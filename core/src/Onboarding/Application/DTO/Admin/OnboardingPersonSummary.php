<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Admin;

use TriCab\Core\Onboarding\Domain\Service\AssignmentProgress;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * One row of the HR roster.
 *
 * Name and email come from `identity.Users` — the platform's directory, which
 * HR already sees in User Management. Nothing from `onboarding_hr` is here:
 * the roster is read by everyone with PEOPLE.MANAGE, and a bank account or a
 * medical answer has no business in a list view (invariant 11).
 */
final readonly class OnboardingPersonSummary
{
    /**
     * @param array<string, int> $statusCounts live assignments per status code
     */
    public function __construct(
        public string $publicId,
        public string $displayName,
        public string $emailAddress,
        public ?string $position,
        public ?string $department,
        public ?string $branch,
        public ?string $startDate,
        public ?string $departedAtUtc,
        public ?string $invitedAtUtc,
        public array $statusCounts,
    ) {
    }

    public function assignedCount(): int
    {
        return array_sum($this->statusCounts);
    }

    public function progressPercent(): int
    {
        $statuses = [];
        foreach ($this->statusCounts as $code => $count) {
            for ($i = 0; $i < $count; $i++) {
                $statuses[] = AssignmentStatus::fromString($code);
            }
        }

        return AssignmentProgress::percent($statuses);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'displayName' => $this->displayName,
            'emailAddress' => $this->emailAddress,
            'position' => $this->position,
            'department' => $this->department,
            'branch' => $this->branch,
            'startDate' => $this->startDate,
            'departedAtUtc' => $this->departedAtUtc,
            'invitedAtUtc' => $this->invitedAtUtc,
            'assignedCount' => $this->assignedCount(),
            'completedCount' => $this->statusCounts[AssignmentStatus::COMPLETED] ?? 0,
            'awaitingReviewCount' => ($this->statusCounts[AssignmentStatus::SUBMITTED] ?? 0)
                + ($this->statusCounts[AssignmentStatus::NEED_HELP] ?? 0),
            'progressPercent' => $this->progressPercent(),
        ];
    }
}
