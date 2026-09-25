<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Report;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * The counts behind the HR overview and the top of the reports screen (4.5).
 *
 * Counts only. Nothing in here names a person, so it is safe to hand to both
 * `ONBOARDING.REPORTS.READ` and the overview under `ONBOARDING.PEOPLE.MANAGE`.
 *
 * Two populations, deliberately:
 *
 *  - `people` and `work` cover **current** people — everyone with an induction
 *    record who has not yet left. A leaver's unfinished item is not work anyone
 *    will chase, so counting it as in progress or overdue would overstate what
 *    is outstanding. Leavers are counted, once, as `departed`.
 *  - `queue` counts exactly what the reviewer queue lists — every live item
 *    submitted or asking for help, leaver or not — so the number on the
 *    overview is the number of rows a reviewer will find.
 */
final readonly class OnboardingReportSummary
{
    /**
     * @param array<string, int> $workByStatus live assignments of current people, per status code
     */
    public function __construct(
        public string $asOf,
        public int $currentPeople,
        public int $departedPeople,
        public int $notYetInvited,
        public int $nothingAllocated,
        public int $finished,
        public array $workByStatus,
        public int $overdue,
        public int $waitingOnReview,
        public int $needHelp,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $byStatus = [];
        foreach (AssignmentStatus::all() as $status) {
            $byStatus[$status->toString()] = $this->workByStatus[$status->toString()] ?? 0;
        }

        return [
            'asOf' => $this->asOf,
            'people' => [
                'current' => $this->currentPeople,
                'departed' => $this->departedPeople,
                'notYetInvited' => $this->notYetInvited,
                'nothingAllocated' => $this->nothingAllocated,
                'finished' => $this->finished,
            ],
            'work' => [
                'total' => array_sum($byStatus),
                'byStatus' => $byStatus,
                'overdue' => $this->overdue,
            ],
            'queue' => [
                'waitingOnReview' => $this->waitingOnReview,
                'needHelp' => $this->needHelp,
            ],
        ];
    }
}
