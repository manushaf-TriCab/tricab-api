<?php

declare(strict_types=1);

namespace TriCab\Api\Npr\Mail;

use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Npr\Mail\NprMailFailed;
use TriCab\Core\Npr\Mail\NprMailMessage;
use TriCab\Core\Npr\Mail\NprMailTransport;

/**
 * Microsoft Graph `sendMail` from the NPR mailbox (`NPR_MAIL_SENDER`), with To
 * and Cc, using the platform's `GRAPH_*` client-credentials registration, as the
 * onboarding sender does. Nothing secret is put in an error: a failure names
 * the status and Graph's own error code.
 */
final class GraphNprMailTransport implements NprMailTransport
{
    private const SCOPE = 'https://graph.microsoft.com/.default';
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const SEND_URL = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailbox,
        private readonly string $fromName = 'TriCab NPR',
    ) {
    }

    public function send(NprMailMessage $message): void
    {
        try {
            $response = $this->http->postJson(
                sprintf(self::SEND_URL, rawurlencode($this->mailbox)),
                ['Authorization' => 'Bearer ' . $this->accessToken()],
                [
                    'message' => [
                        'subject' => $message->subject,
                        'body' => ['contentType' => 'HTML', 'content' => $message->html],
                        'toRecipients' => self::recipients($message->to),
                        'ccRecipients' => self::recipients($message->cc),
                        'from' => ['emailAddress' => ['address' => $this->mailbox, 'name' => $this->fromName]],
                    ],
                    'saveToSentItems' => false,
                ],
            );
        } catch (NprMailFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new NprMailFailed('Microsoft Graph could not be reached.');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new NprMailFailed(sprintf('Microsoft Graph sendMail answered %d%s.', $response['status'], self::graphCode($response['body'])));
        }
    }

    /**
     * @param list<string> $addresses
     * @return list<array{emailAddress: array{address: string}}>
     */
    private static function recipients(array $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = ['emailAddress' => ['address' => $address]];
        }

        return $out;
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->token !== null && $now < $this->tokenExpiresAt - 60) {
            return $this->token;
        }
        $response = $this->http->postForm(
            sprintf(self::TOKEN_URL, rawurlencode($this->tenantId)),
            [],
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => self::SCOPE,
                'grant_type' => 'client_credentials',
            ],
        );
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new NprMailFailed(sprintf('Microsoft identity platform answered %d%s.', $response['status'], self::identityCode($response['body'])));
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            throw new NprMailFailed('No access token in the token response.');
        }
        $token = $decoded['access_token'];
        $expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (int) $decoded['expires_in'] : 3600;
        $this->token = $token;
        $this->tokenExpiresAt = $now + max(60, $expiresIn);

        return $token;
    }

    private static function identityCode(string $body): string
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

    private static function graphCode(string $body): string
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded) && is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? null) : null;

        return is_string($code) && $code !== '' ? ' (' . preg_replace('/[^A-Za-z0-9_.]/', '', $code) . ')' : '';
    }
}
