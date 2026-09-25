<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use Throwable;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderRunSummary;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;
use TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem;
use TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory;
use TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingReminderRepository;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;
use TriCab\Core\Onboarding\Domain\Service\ReminderPolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * The reminder job (5.2): one digest per person, listing what is still theirs
 * to do. Run by `bin/onboarding-send-reminders.php`; there is no scheduler.
 *
 * Ported from the source's `runReminders`, keeping the three things that made
 * it safe to run nightly:
 *
 *  - **One email per person, not per item.** A full induction with no due
 *    dates would otherwise send the whole set every night.
 *  - **Claim before send.** Every item in a digest is claimed in
 *    `NotificationLog` first, so a retried or doubled run skips it. A second run
 *    on the same day only picks up what became due since.
 *  - **All or nothing per digest.** If the provider refuses, every claimed row
 *    in that digest is marked failed, so the retry sends the digest whole rather
 *    than a fragment. The source deleted them; marking keeps the attempt as
 *    evidence, and the repository re-claims a failed row.
 *
 * Only invited people who have not departed and can still sign in are
 * reminded — a link that ends on a refusal is not a reminder.
 */
final readonly class SendOnboardingRemindersService
{
    public function __construct(
        private OnboardingReminderRepository $reminders,
        private OnboardingAccountDirectory $accounts,
        private OnboardingReminderEmailSender $sender,
        private OnboardingReminderDelivery $delivery,
        private Clock $clock,
        private string $publicAppBaseUrl,
    ) {
    }

    public function run(bool $dryRun = false): OnboardingReminderRunSummary
    {
        $now = $this->clock->now();
        $today = OverduePolicy::todayFor($now);

        if (!$this->delivery->enabled && !$dryRun) {
            return $this->summary(false, $today, 0, 0, 0, 0, 0, []);
        }

        $candidates = $this->reminders->listOutstanding(
            ReminderPolicy::remindedStatuses(),
            ReminderPolicy::horizonDate($today),
            $now,
        );

        /** @var array<int, list<array{item: OutstandingReminderItem, type: string}>> $byPerson */
        $byPerson = [];
        foreach ($candidates as $item) {
            $type = ReminderPolicy::typeFor($item->status, $item->dueDate, $today);
            if ($type !== null) {
                $byPerson[$item->userId][] = ['item' => $item, 'type' => $type];
            }
        }

        $people = 0;
        $sent = 0;
        $included = 0;
        $alreadySent = 0;
        $failures = [];
        $url = rtrim($this->publicAppBaseUrl, '/') . '/onboarding';

        foreach ($byPerson as $userId => $entries) {
            if (!$this->accounts->canSignIn($userId)) {
                continue;
            }
            $people++;

            if ($dryRun) {
                $included += count($entries);
                continue;
            }

            $claimed = [];
            $lines = [];
            foreach ($entries as ['item' => $item, 'type' => $type]) {
                $id = $this->reminders->claim($userId, $type, $item->itemType, $item->assignmentPublicId, $today);
                if ($id === null) {
                    $alreadySent++;
                    continue;
                }
                $claimed[] = $id;
                $lines[] = new ReminderDigestItem($item->title, $item->dueDate, $type === ReminderPolicy::TYPE_OVERDUE);
            }
            if ($lines === []) {
                continue;
            }

            $first = $entries[0]['item'];
            try {
                $to = EmailAddress::fromString($this->delivery->testRecipient ?? $first->emailAddress);
                $this->sender->sendReminder($to, $first->displayName, $lines, $url);
                $sent++;
                $included += count($lines);
            } catch (Throwable $failure) {
                $reason = (new \ReflectionClass($failure))->getShortName();
                $this->reminders->markFailed($claimed, $reason);
                $failures[] = sprintf('user %d — %s', $userId, $reason);
            }
        }

        return $this->summary($this->delivery->enabled, $today, count($candidates), $people, $sent, $included, $alreadySent, $failures, $dryRun);
    }

    /**
     * @param list<string> $failures
     */
    private function summary(
        bool $enabled,
        string $today,
        int $candidates,
        int $people,
        int $sent,
        int $included,
        int $alreadySent,
        array $failures,
        bool $dryRun = false,
    ): OnboardingReminderRunSummary {
        return new OnboardingReminderRunSummary(
            $enabled,
            $dryRun,
            $this->delivery->reason,
            $this->delivery->testRecipient !== null,
            $today,
            $candidates,
            $people,
            $sent,
            $included,
            $alreadySent,
            $failures,
        );
    }
}
