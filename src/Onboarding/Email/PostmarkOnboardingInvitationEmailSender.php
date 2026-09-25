<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender;

/** Follows `PostmarkGuestPasskeyEmailSender`. Sends the invitation (4.1) and the reminder digest (5.2). */
final class PostmarkOnboardingInvitationEmailSender implements OnboardingInvitationEmailSender, OnboardingReminderEmailSender
{
    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $serverToken,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $messageStream = 'outbound',
    ) {
        if (trim($serverToken) === '' || trim($fromEmail) === '') {
            throw new RuntimeException('Postmark token and from email are required.');
        }
    }

    public function sendInvitation(EmailAddress $to, string $displayName, string $onboardingUrl): void
    {
        $this->send(
            $to,
            OnboardingInvitationEmailTemplate::subject(),
            OnboardingInvitationEmailTemplate::html($displayName, $onboardingUrl),
            OnboardingInvitationEmailTemplate::text($displayName, $onboardingUrl),
        );
    }

    public function sendReminder(EmailAddress $to, string $displayName, array $items, string $onboardingUrl): void
    {
        $this->send(
            $to,
            OnboardingReminderEmailTemplate::subject($items),
            OnboardingReminderEmailTemplate::html($displayName, $items, $onboardingUrl),
            OnboardingReminderEmailTemplate::text($displayName, $items, $onboardingUrl),
        );
    }

    private function send(EmailAddress $to, string $subject, string $html, string $text): void
    {
        $from = trim($this->fromName) === '' ? $this->fromEmail : sprintf('%s <%s>', $this->fromName, $this->fromEmail);

        $response = $this->http->postJson(
            self::ENDPOINT,
            ['X-Postmark-Server-Token' => $this->serverToken],
            [
                'From' => $from,
                'To' => $to->toString(),
                'Subject' => $subject,
                'HtmlBody' => $html,
                'TextBody' => $text,
                'MessageStream' => $this->messageStream,
            ],
        );

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException(sprintf('Postmark answered %d.', $response['status']));
        }
    }
}
