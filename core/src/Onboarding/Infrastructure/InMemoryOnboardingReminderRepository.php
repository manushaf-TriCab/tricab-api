<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;
use TriCab\Core\Onboarding\Contract\OnboardingReminderRepository;

/**
 * Holds candidates as given and a log keyed exactly as
 * `UX_onboarding_NotificationLog_OncePerDay` is.
 */
final class InMemoryOnboardingReminderRepository implements OnboardingReminderRepository
{
    /** @var list<OutstandingReminderItem> */
    public array $outstanding = [];

    /** @var array<int, array{key: string, outcome: string, reason: ?string}> */
    public array $log = [];

    private int $nextId = 1;

    public function listOutstanding(array $statuses, string $horizonDate, DateTimeImmutable $nowUtc): array
    {
        return array_values(array_filter(
            $this->outstanding,
            static fn (OutstandingReminderItem $item): bool => in_array($item->status, $statuses, true)
                && ($item->dueDate === null || $item->dueDate <= $horizonDate),
        ));
    }

    public function claim(int $userId, string $notificationType, string $subjectType, string $subjectPublicId, string $sentOn): ?int
    {
        $key = implode('|', [$userId, $notificationType, $subjectType, strtolower($subjectPublicId), $sentOn]);
        foreach ($this->log as $id => $row) {
            if ($row['key'] !== $key) {
                continue;
            }
            if ($row['outcome'] !== 'failed') {
                return null;
            }
            $this->log[$id] = ['key' => $key, 'outcome' => 'sent', 'reason' => null];

            return $id;
        }

        $id = $this->nextId++;
        $this->log[$id] = ['key' => $key, 'outcome' => 'sent', 'reason' => null];

        return $id;
    }

    public function markFailed(array $notificationLogIds, string $reason): void
    {
        foreach ($notificationLogIds as $id) {
            if (isset($this->log[$id])) {
                $this->log[$id]['outcome'] = 'failed';
                $this->log[$id]['reason'] = $reason;
            }
        }
    }

    /** @return list<array{key: string, outcome: string, reason: ?string}> */
    public function rowsWithOutcome(string $outcome): array
    {
        return array_values(array_filter($this->log, static fn (array $row): bool => $row['outcome'] === $outcome));
    }
}
