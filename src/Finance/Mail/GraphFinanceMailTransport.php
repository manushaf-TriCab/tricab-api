<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Api\Infrastructure\Http\JsonMethodHttpClient;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceReplyMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * Sends through Microsoft Graph (`POST /users/{mailbox}/sendMail`, client
 * credentials) as the shared mailbox, as the finance portal's Mail Service does.
 *
 * The credentials are the finance apps' own registration
 * (`FINANCE_GRAPH_TENANT_ID`, `FINANCE_GRAPH_CLIENT_ID`,
 * `FINANCE_GRAPH_CLIENT_SECRET`), never the platform's `GRAPH_*` (plan Q3).
 *
 * `$testRecipient` redirects every send to that one address and drops cc and bcc,
 * as `TRIEXPENSE_EMAIL_TEST_RECIPIENT` does for TriExpense; the ledger still
 * records the intended recipients. Saved to Sent Items, as the portal does.
 * Nothing secret is put in an error: a failure names the status and Graph's own
 * error code.
 */
final class GraphFinanceMailTransport implements FinanceReplyMailTransport
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SEND_MAIL_URL_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private const MAX_ATTEMPTS = 3;

    private const MAX_RETRY_DELAY_SECONDS = 5;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $testRecipient = null,
        private readonly bool $pauseBetweenRetries = true,
    ) {
    }

    public function deliver(FinanceMailMessage $message): void
    {
        $payload = ['message' => $this->graphMessage($message), 'saveToSentItems' => true];
        $url = sprintf(self::SEND_MAIL_URL_TEMPLATE, rawurlencode($message->mailbox));
        $status = 0;
        $body = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->accessToken();
            try {
                $response = $this->http->postJson($url, ['Authorization' => 'Bearer ' . $token], $payload);
            } catch (Throwable) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new FinanceMailTransportFailed('Microsoft Graph could not be reached.');
                }
                $this->pause($attempt);
                continue;
            }

            $status = $response['status'];
            $body = $response['body'];
            if ($status >= 200 && $status <= 299) {
                return;
            }
            if ($status === 401 || $status === 403) {
                $this->token = null;
                $this->tokenExpiresAt = 0;
            }
            // 4xx other than 429 means the registration or the request is wrong; retrying burns the throttle.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelay($response, $attempt));
            }
        }

        throw new FinanceMailTransportFailed(self::describe($status, $body));
    }

    /**
     * Answers a message in its own conversation: `createReply` (Graph addresses, threads and quotes the draft), a
     * PATCH putting the new HTML above the quote with the cc, subject and attachments, then `send`. Three calls,
     * which is the cost of threading properly. A message Graph refuses (not found, or an id it cannot read: 404 or
     * 400) is sent as a new message instead and `false` is returned. Any other failure throws, and a draft that was
     * made but not sent is deleted so none is left in the mailbox. With a test recipient set nothing is threaded:
     * the draft would be addressed to the real sender, so the redirected standalone message is sent instead.
     */
    public function deliverReply(FinanceMailMessage $message, string $replyToMessageId): bool
    {
        if ($this->testRecipient !== null && $this->testRecipient !== '') {
            $this->deliver($message);

            return false;
        }
        if (!$this->http instanceof JsonMethodHttpClient) {
            throw new FinanceMailTransportFailed('Threaded replies need an HTTP client that can PATCH; none is configured.');
        }
        $base = sprintf('https://graph.microsoft.com/v1.0/users/%s/messages/', rawurlencode($message->mailbox));
        $created = $this->call($this->http, 'POST', $base . rawurlencode($replyToMessageId) . '/createReply', null);
        if ($created['status'] === 404 || $created['status'] === 400) {
            $this->deliver($message);

            return false;
        }
        $draft = self::decode($created['body']);
        $draftId = is_string($draft['id'] ?? null) ? $draft['id'] : '';
        if ($created['status'] < 200 || $created['status'] > 299) {
            throw new FinanceMailTransportFailed(self::describe($created['status'], $created['body']));
        }
        if ($draftId === '') {
            throw new FinanceMailTransportFailed('Microsoft Graph made a reply draft but did not name it.');
        }
        $draftUrl = $base . rawurlencode($draftId);
        try {
            $draftBody = $draft['body'] ?? null;
            $quoted = is_array($draftBody) && is_string($draftBody['content'] ?? null) ? $draftBody['content'] : '';
            $patch = [
                'subject' => $message->subject,
                'body' => ['contentType' => 'HTML', 'content' => $message->body . $quoted],
            ];
            if ($message->cc !== []) {
                $patch['ccRecipients'] = self::recipients($message->cc);
            }
            if ($message->bcc !== []) {
                $patch['bccRecipients'] = self::recipients($message->bcc);
            }
            if ($message->attachments !== []) {
                $patch['attachments'] = array_map(static fn ($attachment): array => [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $attachment->name,
                    'contentType' => $attachment->contentType,
                    'contentBytes' => base64_encode($attachment->content),
                ], $message->attachments);
            }
            $patched = $this->call($this->http, 'PATCH', $draftUrl, $patch);
            if ($patched['status'] < 200 || $patched['status'] > 299) {
                throw new FinanceMailTransportFailed(self::describe($patched['status'], $patched['body']));
            }
            $sent = $this->call($this->http, 'POST', $draftUrl . '/send', null);
            if ($sent['status'] < 200 || $sent['status'] > 299) {
                throw new FinanceMailTransportFailed(self::describe($sent['status'], $sent['body']));
            }
        } catch (Throwable $failure) {
            try {
                $this->call($this->http, 'DELETE', $draftUrl, null);
            } catch (Throwable) {
                // Best effort: the reply failed either way, and that is what the caller is told.
            }
            throw $failure instanceof FinanceMailTransportFailed ? $failure : new FinanceMailTransportFailed('Microsoft Graph could not be reached.', 0, $failure);
        }

        return true;
    }

    /**
     * One Graph call with the shared retry rules: transport errors and 429/5xx are retried (three attempts), a 401 or
     * 403 clears the token, and any other 4xx is returned at once for the caller to read.
     *
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private function call(JsonMethodHttpClient $http, string $method, string $url, ?array $payload): array
    {
        $response = ['status' => 0, 'body' => ''];
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->accessToken();
            try {
                $response = $http->sendJson($method, $url, ['Authorization' => 'Bearer ' . $token], $payload);
            } catch (Throwable) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new FinanceMailTransportFailed('Microsoft Graph could not be reached.');
                }
                $this->pause($attempt);
                continue;
            }
            $status = $response['status'];
            if ($status >= 200 && $status <= 299) {
                return $response;
            }
            if ($status === 401 || $status === 403) {
                $this->token = null;
                $this->tokenExpiresAt = 0;
            }
            if ($status >= 400 && $status < 500 && $status !== 429) {
                return $response;
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelay($response, $attempt));
            }
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function graphMessage(FinanceMailMessage $message): array
    {
        $redirect = $this->testRecipient !== null && $this->testRecipient !== '';
        $graph = [
            'subject' => $message->subject,
            'body' => [
                'contentType' => $message->contentType === FinanceMailRules::CONTENT_TEXT ? 'Text' : 'HTML',
                'content' => $message->body,
            ],
            'toRecipients' => self::recipients($redirect ? [(string) $this->testRecipient] : $message->to),
        ];
        if (!$redirect && $message->cc !== []) {
            $graph['ccRecipients'] = self::recipients($message->cc);
        }
        if (!$redirect && $message->bcc !== []) {
            $graph['bccRecipients'] = self::recipients($message->bcc);
        }
        if ($message->attachments !== []) {
            $graph['attachments'] = array_map(static fn ($attachment): array => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->name,
                'contentType' => $attachment->contentType,
                'contentBytes' => base64_encode($attachment->content),
            ], $message->attachments);
        }

        return $graph;
    }

    /**
     * @param list<string> $addresses
     * @return list<array{emailAddress: array{address: string}}>
     */
    private static function recipients(array $addresses): array
    {
        return array_map(static fn (string $address): array => ['emailAddress' => ['address' => $address]], $addresses);
    }

    private function accessToken(): string
    {
        $now = time();
        if ($this->token !== null && $this->token !== '' && $now < $this->tokenExpiresAt - 60) {
            return $this->token;
        }

        try {
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
        } catch (Throwable) {
            throw new FinanceMailTransportFailed('The Microsoft sign-in endpoint could not be reached.');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new FinanceMailTransportFailed('The finance Graph registration was refused a token (status ' . $response['status'] . '). Check its tenant, client id and secret.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FinanceMailTransportFailed('The Microsoft token response was not JSON.');
        }
        if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            throw new FinanceMailTransportFailed('The Microsoft token response had no access token.');
        }
        $token = $decoded['access_token'];
        $expires = $decoded['expires_in'] ?? 3600;
        $this->token = $token;
        $this->tokenExpiresAt = $now + max(60, is_numeric($expires) ? (int) $expires : 3600);

        return $token;
    }

    private static function describe(int $status, string $body): string
    {
        $code = '';
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if (is_array($error) && isset($error['code']) && is_string($error['code'])) {
                $code = ' (' . preg_replace('/[^A-Za-z0-9_.-]/', '', $error['code']) . ')';
            }
        } catch (JsonException) {
            // No JSON body: the status alone.
        }

        return 'Microsoft Graph refused the message with status ' . $status . $code . '.';
    }

    /**
     * @param array{status: int, body: string, headers?: array<string, string>} $response
     */
    private function retryDelay(array $response, int $attempt): int
    {
        $after = ($response['headers'] ?? [])['retry-after'] ?? null;
        if (is_string($after) && preg_match('/^\d+$/', trim($after)) === 1) {
            return max(1, min(self::MAX_RETRY_DELAY_SECONDS, (int) trim($after)));
        }

        return min(self::MAX_RETRY_DELAY_SECONDS, $attempt);
    }

    private function pause(int $seconds): void
    {
        if ($this->pauseBetweenRetries && $seconds > 0) {
            sleep(min(self::MAX_RETRY_DELAY_SECONDS, $seconds));
        }
    }
}
