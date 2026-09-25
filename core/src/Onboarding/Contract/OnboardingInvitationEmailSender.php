<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Sends the invitation to start onboarding (4.1).
 *
 * **There is no code in it** (invariant 6). It links to the onboarding page,
 * where the person signs in and — if they sign in by email — asks for their own
 * code. HR never sees, sends or handles one.
 *
 * Same three providers as the sign-in code (`GUEST_EMAIL_PROVIDER`): development,
 * Microsoft Graph, Postmark.
 *
 * @throws \Throwable when the provider does not accept the message
 */
interface OnboardingInvitationEmailSender
{
    public function sendInvitation(EmailAddress $to, string $displayName, string $onboardingUrl): void;
}
