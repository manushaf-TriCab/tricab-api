<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Reminder;

/**
 * What one reminder run did. Counts and outcomes only: no addresses, because
 * the summary is printed by a scheduled job whose output lands in logs.
 */
final readonly class OnboardingReminderRunSummary
{
    /**
     * @param list<string> $failures one line per failed digest, naming the user id and the reason class
     */
    public function __construct(
        public bool $enabled,
        public bool $dryRun,
        public string $deliveryReason,
        public bool $testRecipientOverride,
        public string $localDate,
        public int $candidates,
        public int $people,
        public int $emailsSent,
        public int $itemsIncluded,
        public int $alreadySentToday,
        public array $failures,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'dryRun' => $this->dryRun,
            'deliveryReason' => $this->deliveryReason,
            'testRecipientOverride' => $this->testRecipientOverride,
            'localDate' => $this->localDate,
            'candidates' => $this->candidates,
            'people' => $this->people,
            'emailsSent' => $this->emailsSent,
            'itemsIncluded' => $this->itemsIncluded,
            'alreadySentToday' => $this->alreadySentToday,
            'failures' => $this->failures,
        ];
    }
}
