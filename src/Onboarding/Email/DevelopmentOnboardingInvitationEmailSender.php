<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender;

/** `GUEST_EMAIL_PROVIDER=development`: nothing leaves the machine. Invitations (4.1) and reminders (5.2). */
final class DevelopmentOnboardingInvitationEmailSender implements OnboardingInvitationEmailSender, OnboardingReminderEmailSender
{
    /** @var array{to: string, subject: string, onboardingUrl: string}|null */
    private ?array $lastMessage = null;

    public function sendInvitation(EmailAddress $to, string $displayName, string $onboardingUrl): void
    {
        unset($displayName);
        $this->lastMessage = [
            'to' => $to->toString(),
            'subject' => OnboardingInvitationEmailTemplate::subject(),
            'onboardingUrl' => $onboardingUrl,
        ];
    }

    /** @return array{to: string, subject: string, onboardingUrl: string}|null */
    public function lastMessage(): ?array
    {
        return $this->lastMessage;
    }

    public function sendReminder(EmailAddress $to, string $displayName, array $items, string $onboardingUrl): void
    {
        unset($displayName);
        $this->lastMessage = [
            'to' => $to->toString(),
            'subject' => OnboardingReminderEmailTemplate::subject($items),
            'onboardingUrl' => $onboardingUrl,
        ];
    }
}
