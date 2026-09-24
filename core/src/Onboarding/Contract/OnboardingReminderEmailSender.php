<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem;

/**
 * Sends one person's reminder digest (5.2): the titles of what is outstanding
 * and when each was due, and a link to their onboarding page.
 *
 * **Nothing else.** No code (invariant 6), no answers, no form values, no HR
 * notes — a reminder is read on a lock screen. Same three providers as the
 * invitation (`GUEST_EMAIL_PROVIDER`).
 *
 * @throws \Throwable when the provider does not accept the message
 */
interface OnboardingReminderEmailSender
{
    /**
     * @param non-empty-list<ReminderDigestItem> $items
     */
    public function sendReminder(EmailAddress $to, string $displayName, array $items, string $onboardingUrl): void;
}
