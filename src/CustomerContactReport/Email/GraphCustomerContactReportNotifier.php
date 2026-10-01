<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Email;

use JsonException;
use RuntimeException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotice;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier;

/**
 * Microsoft Graph sendMail for Customer Contact, using the shared
 * client-credentials application. Transient HTTP failures are tried at most
 * three times. There is no outbox. When sending is disabled, Graph is not called.
 */
final class GraphCustomerContactReportNotifier implements CustomerContactReportNotifier
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SEND_MAIL_URL_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const MAX_ATTEMPTS = 3;

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly CustomerContactReportEmailTemplate $templates,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailFrom,
        private readonly bool $sendingEnabled,
    ) {
    }

    public function send(CustomerContactReportNotice $notice): void
    {
        if (!$this->sendingEnabled || $notice->recipients === [] || trim($this->mailFrom) === '') {
            return;
        }

        $rendered = $this->templates->render($notice);
        $to = [];
        foreach ($notice->recipients as $recipient) {
            $to[] = ['emailAddress' => ['address' => $recipient]];
        }
        $payload = [
            'message' => [
                'subject' => $rendered['subject'],
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $rendered['html'],
                ],
                'toRecipients' => $to,
                'from' => [
                    'emailAddress' => [
                        'address' => $this->mailFrom,
                        'name' => 'TriCab Customer Contact',
                    ],
                ],
            ],
            'saveToSentItems' => false,
        ];
        $url = sprintf(self::SEND_MAIL_URL_TEMPLATE, rawurlencode($this->mailFrom));

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->postJson($url, [
                    'Authorization' => 'Bearer ' . $this->accessToken(),
                ], $payload);
            } catch (Throwable) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new RuntimeException('Customer Contact mail could not be sent.');
                }
                continue;
            }

            $status = $response['status'];
            if ($status >= 200 && $status <= 299) {
                return;
            }
            if ($status >= 400 && $status < 500 && $status !== 429) {
                throw new RuntimeException('Customer Contact mail could not be sent.');
            }
            if ($attempt === self::MAX_ATTEMPTS) {
                throw new RuntimeException('Customer Contact mail could not be sent.');
            }
        }
    }

    private function accessToken(): string
    {
        $now = time();
        if (
            $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - 60)
        ) {
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
            throw new RuntimeException('Customer Contact mail could not be sent.');
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Customer Contact mail could not be sent.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Customer Contact mail could not be sent.');
        }
        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Customer Contact mail could not be sent.');
        }
        $expiresIn = $decoded['expires_in'] ?? 3600;
        $ttl = is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn)) ? (int) $expiresIn : 3600;
        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }
}
