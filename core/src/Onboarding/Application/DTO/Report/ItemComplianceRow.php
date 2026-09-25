<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Report;

/**
 * One training module, policy or form, and where current people are on it
 * (4.5) — "has everybody acknowledged the WHS policy?".
 *
 * Counted per item across all its versions: somebody who completed version 2
 * before version 3 was published without a reissue has still done it. Current
 * people only, live assignments only (see {@see OnboardingReportSummary}).
 *
 * Counts, no names. Who the stragglers are is the overdue list, and their
 * contact details are the People screen, under a different permission.
 */
final readonly class ItemComplianceRow
{
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function __construct(
        public string $itemType,
        public string $itemPublicId,
        public string $title,
        public bool $offered,
        public int $assigned,
        public int $completed,
        public int $waitingOnReview,
        public int $inProgress,
        public int $notStarted,
        public int $overdue,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemType' => $this->itemType,
            'itemPublicId' => $this->itemPublicId,
            'title' => $this->title,
            'offered' => $this->offered,
            'assigned' => $this->assigned,
            'completed' => $this->completed,
            'waitingOnReview' => $this->waitingOnReview,
            'inProgress' => $this->inProgress,
            'notStarted' => $this->notStarted,
            'overdue' => $this->overdue,
        ];
    }
}
