<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;

/** Follows `PostmarkGuestPasskeyEmailSender`. */
final class PostmarkOnboardingInvitationEmailSender implements OnboardingInvitationEmailSender
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
        $from = trim($this->fromName) === '' ? $this->fromEmail : sprintf('%s <%s>', $this->fromName, $this->fromEmail);

        $response = $this->http->postJson(
            self::ENDPOINT,
            ['X-Postmark-Server-Token' => $this->serverToken],
            [
                'From' => $from,
                'To' => $to->toString(),
                'Subject' => OnboardingInvitationEmailTemplate::subject(),
                'HtmlBody' => OnboardingInvitationEmailTemplate::html($displayName, $onboardingUrl),
                'TextBody' => OnboardingInvitationEmailTemplate::text($displayName, $onboardingUrl),
                'MessageStream' => $this->messageStream,
            ],
        );

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException(sprintf('Postmark answered %d.', $response['status']));
        }
    }
}
