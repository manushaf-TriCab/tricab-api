<?php

declare(strict_types=1);

namespace TriCab\Api\Onboarding\Email;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender;
use TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender;

/**
 * Follows `MicrosoftGraphGuestPasskeyEmailSender`: client-credentials token,
 * `sendMail` from the configured mailbox. Failures throw; the invite service
 * turns any of them into a 502 and puts the invitation stamp back, and the
 * reminder job (5.2) marks that person's digest failed.
 */
final class MicrosoftGraphOnboardingInvitationEmailSender implements OnboardingInvitationEmailSender, OnboardingReminderEmailSender
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';
    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const SEND_MAIL_URL_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailbox,
        private readonly string $fromDisplayName = 'TriCab',
    ) {
        if (trim($tenantId) === '' || trim($clientId) === '' || trim($clientSecret) === '' || trim($mailbox) === '') {
            throw new RuntimeException('GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, and RFQ_MAILBOX are required.');
        }
    }

    public function sendInvitation(EmailAddress $to, string $displayName, string $onboardingUrl): void
    {
        $this->send($to, OnboardingInvitationEmailTemplate::subject(), OnboardingInvitationEmailTemplate::html($displayName, $onboardingUrl));
    }

    public function sendReminder(EmailAddress $to, string $displayName, array $items, string $onboardingUrl): void
    {
        $this->send($to, OnboardingReminderEmailTemplate::subject($items), OnboardingReminderEmailTemplate::html($displayName, $items, $onboardingUrl));
    }

    private function send(EmailAddress $to, string $subject, string $html): void
    {
        $response = $this->http->postJson(
            sprintf(self::SEND_MAIL_URL_TEMPLATE, rawurlencode($this->mailbox)),
            ['Authorization' => 'Bearer ' . $this->accessToken()],
            [
                'message' => [
                    'subject' => $subject,
                    'body' => ['contentType' => 'HTML', 'content' => $html],
                    'toRecipients' => [['emailAddress' => ['address' => $to->toString()]]],
                    'from' => ['emailAddress' => ['address' => $this->mailbox, 'name' => $this->fromDisplayName]],
                ],
                'saveToSentItems' => false,
            ],
        );

        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException(sprintf('Microsoft Graph sendMail answered %d%s.', $response['status'], self::graphErrorCode($response['body'])));
        }
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->cachedAccessToken !== null && $now < ($this->cachedAccessTokenExpiresAt - 60)) {
            return $this->cachedAccessToken;
        }

        $response = $this->http->postForm(
            sprintf(self::TOKEN_URL_TEMPLATE, rawurlencode($this->tenantId)),
            [],
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => self::GRAPH_SCOPE,
                'grant_type' => 'client_credentials',
            ],
        );
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new RuntimeException(sprintf('Microsoft identity platform answered %d%s.', $response['status'], self::identityErrorCode($response['body'])));
        }

        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('The token response was not an object.');
        }
        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('No access token in the token response.');
        }
        $expiresIn = $decoded['expires_in'] ?? 3600;

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, is_numeric($expiresIn) ? (int) $expiresIn : 3600);

        return $token;
    }

    /**
     * The identity platform's error and AADSTS number — `invalid_client`,
     * AADSTS7000215 for an expired client secret — and nothing else from the
     * body: its description quotes the client id and a trace id.
     */
    private static function identityErrorCode(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $error = is_string($decoded['error'] ?? null) ? preg_replace('/[^a-z_]/', '', $decoded['error']) : '';
        $codes = is_array($decoded['error_codes'] ?? null) ? array_filter($decoded['error_codes'], 'is_int') : [];
        $parts = array_filter([$error, ...array_map(static fn (int $c): string => 'AADSTS' . $c, $codes)]);

        return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
    }

    /** Graph's error code (`ErrorAccessDenied`, `MailboxNotEnabledForRESTAPI`…), without its message. */
    private static function graphErrorCode(string $body): string
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? ' (' . preg_replace('/[^A-Za-z0-9_.]/', '', $code) . ')' : '';
    }
}
