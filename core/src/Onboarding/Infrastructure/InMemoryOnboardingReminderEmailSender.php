<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem;
use TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender;

final class InMemoryOnboardingReminderEmailSender implements OnboardingReminderEmailSender
{
    /** @var list<array{to: string, displayName: string, items: list<ReminderDigestItem>, onboardingUrl: string}> */
    public array $sent = [];

    /** @var list<string> addresses whose next send throws */
    public array $refuse = [];

    public function sendReminder(EmailAddress $to, string $displayName, array $items, string $onboardingUrl): void
    {
        $address = $to->toString();
        $index = array_search($address, $this->refuse, true);
        if ($index !== false) {
            array_splice($this->refuse, $index, 1);
            throw new RuntimeException('provider refused');
        }

        $this->sent[] = ['to' => $address, 'displayName' => $displayName, 'items' => array_values($items), 'onboardingUrl' => $onboardingUrl];
    }
}
