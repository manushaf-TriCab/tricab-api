<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Reminder;

/**
 * Whether reminders may be emailed in this environment, and to whom (5.2).
 *
 * Work order Phase 5.5: disabled by default in non-production, with a
 * recipient override. Three switches decide it, and every way of getting them
 * wrong ends in no email rather than the wrong email:
 *
 *  - `ONBOARDING_REMINDER_EMAILS` must be exactly `on`. Unset, `off`, `true`, a
 *    typo — all mean off, as in the source, where HR had asked for the nightly
 *    mail-out to stop and a fresh deployment must not quietly restart it.
 *  - `ONBOARDING_EMAIL_TEST_RECIPIENT`, when set, receives every reminder in
 *    place of the real person.
 *  - Outside production, real people are never emailed: without a test
 *    recipient the run is refused unless the provider is `development`, which
 *    delivers nothing.
 */
final readonly class OnboardingReminderDelivery
{
    private function __construct(
        public bool $enabled,
        public ?string $testRecipient,
        public string $reason,
    ) {
    }

    public static function decide(
        string $switch,
        string $testRecipient,
        bool $isProduction,
        bool $providerDeliversNothing,
    ): self {
        $override = strtolower(trim($testRecipient));
        $override = $override === '' ? null : $override;

        if (strtolower(trim($switch)) !== 'on') {
            return new self(false, $override, 'ONBOARDING_REMINDER_EMAILS is not "on".');
        }
        if (!$isProduction && $override === null && !$providerDeliversNothing) {
            return new self(
                false,
                null,
                'Outside production reminders need ONBOARDING_EMAIL_TEST_RECIPIENT, or GUEST_EMAIL_PROVIDER=development.',
            );
        }

        return new self(
            true,
            $override,
            $override === null ? 'Reminders go to each person.' : 'Every reminder goes to the test recipient.',
        );
    }

    public static function disabled(string $reason = 'Reminders are off.'): self
    {
        return new self(false, null, $reason);
    }
}
