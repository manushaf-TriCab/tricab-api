<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;

final class InMemoryOnboardingInvitationEmailSender implements OnboardingInvitationEmailSender
{
    /** @var list<array{to: string, displayName: string, onboardingUrl: string}> */
    public array $sent = [];

    public bool $failNext = false;

    public function sendInvitation(EmailAddress $to, string $displayName, string $onboardingUrl): void
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('provider refused');
        }

        $this->sent[] = ['to' => $to->toString(), 'displayName' => $displayName, 'onboardingUrl' => $onboardingUrl];
    }
}
