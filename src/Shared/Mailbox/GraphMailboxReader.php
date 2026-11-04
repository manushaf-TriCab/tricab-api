<?php

declare(strict_types=1);

namespace TriCab\Api\Shared\Mailbox;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Shared\Mailbox\FolderMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxFolders;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;
use TriCab\Core\Shared\Mailbox\MailboxSyncExpired;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * Reads a shared mailbox through Microsoft Graph with app-only (client credentials)
 * access: `GET /users/{mailbox}/messages` and `/messages/{id}/attachments`. The
 * registration's credentials are passed in; this class reads no environment.
 *
 * **Every request to Graph carries `Prefer: IdType="ImmutableId"`.** Without it Graph
 * hands out per-folder ids that stop working when a message is filed, and Tri-AR's and
 * the portal's stored ids went stale that way. The token request is not a Graph call
 * and does not carry it. A test pins the header on every call.
 *
 * It follows `@odata.nextLink` only to Graph itself, never to a host the response
 * names; retries 429 and 5xx up to three attempts (honouring `Retry-After`, capped);
 * and puts nothing secret in an error: a failure names Graph's status and its own
 * error code. No web link is selected: the shared mailboxes cannot be opened in Outlook.
 *
 * Modelled on `invoice-flow/server/graph_client.py`, the Tri-AP client that does this
 * correctly today, and on `GraphFinanceMailTransport` for the token and retry shape.
 */
final class GraphMailboxReader implements FolderMailboxReader
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SUMMARY_FIELDS = 'id,subject,from,receivedDateTime,hasAttachments,bodyPreview';

    private const MESSAGE_FIELDS = self::SUMMARY_FIELDS . ',body,toRecipients,ccRecipients';

    private const ATTACHMENT_FIELDS = 'id,name,contentType,size,isInline';

    private const PAGE_SIZE = 50;

    private const MAX_ATTEMPTS = 3;

    private const MAX_RETRY_DELAY_SECONDS = 5;

    private const IMMUTABLE_IDS = 'IdType="ImmutableId"';

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $pauseBetweenRetries = true,
    ) {
    }

    public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        return $this->list($mailbox, '/messages', $from, $to, $maxMessages);
    }

    public function listMessagesInFolder(string $mailbox, string $folder, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        // A well-known name only: it goes into the URL.
        return $this->list($mailbox, '/mailFolders/' . MailboxFolders::normalise($folder) . '/messages', $from, $to, $maxMessages);
    }

    /**
     * @return list<MailboxMessageSummary>
     */
    private function list(string $mailbox, string $path, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        $maxMessages = max(1, $maxMessages);
        $utc = new DateTimeZone('UTC');
        $query = [
            '$top' => (string) min(self::PAGE_SIZE, $maxMessages),
            '$orderby' => 'receivedDateTime desc',
            '$select' => self::SUMMARY_FIELDS,
            '$filter' => sprintf(
                'receivedDateTime ge %s and receivedDateTime le %s',
                $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            ),
        ];

        $messages = [];
        $url = $this->mailboxUrl($mailbox, $path, $query);
        while ($url !== null) {
            $page = $this->get($url);
            foreach (self::rows($page['value'] ?? null) as $row) {
                $messages[] = self::summary($row);
                if (count($messages) >= $maxMessages) {
                    return $messages;
                }
            }
            $url = self::continuation($page['@odata.nextLink'] ?? null);
        }

        return $messages;
    }

    /**
     * The first URL of an Inbox delta query (RFQ mail reader): the messages received since `$since`, then
     * only what changes. `$select` names the fields wanted. Pass the result, then each `nextLink` and the
     * final `deltaLink`, to {@see deltaPage()}.
     */
    public function deltaStartUrl(string $mailbox, string $folder, DateTimeImmutable $since, string $select): string
    {
        return $this->mailboxUrl($mailbox, '/mailFolders/' . self::folderSegment($folder) . '/messages/delta', [
            '$select' => $select,
            '$filter' => 'receivedDateTime ge ' . $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * The first URL of a plain (non-delta) listing of one folder: messages received since `$since`, newest first.
     * Pass it, then each `nextLink`, to {@see deltaPage()} (which reads any Graph page). Used by the backfill, which
     * must not touch delta state.
     */
    public function folderMessagesStartUrl(string $mailbox, string $folder, DateTimeImmutable $since, string $select): string
    {
        return $this->mailboxUrl($mailbox, '/mailFolders/' . self::folderSegment($folder) . '/messages', [
            '$select' => $select,
            '$filter' => 'receivedDateTime ge ' . $since->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime desc',
        ]);
    }

    /**
     * The folders directly under one folder (a well-known name such as `inbox`, or a folder id).
     *
     * @return list<array{id: string, name: string, childCount: int}>
     */
    public function childFolders(string $mailbox, string $folder): array
    {
        $url = $this->mailboxUrl($mailbox, '/mailFolders/' . self::folderSegment($folder) . '/childFolders', [
            '$top' => '100',
            '$select' => 'id,displayName,childFolderCount',
        ]);
        $folders = [];
        while ($url !== null) {
            $page = $this->get($url);
            foreach (self::rows($page['value'] ?? null) as $row) {
                $id = $row['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $count = $row['childFolderCount'] ?? 0;
                    $folders[] = ['id' => $id, 'name' => self::text($row['displayName'] ?? null), 'childCount' => is_int($count) ? $count : 0];
                }
            }
            $url = self::continuation($page['@odata.nextLink'] ?? null);
        }

        return $folders;
    }

    /** A well-known folder name (any case) or a folder id, made safe for a URL path. */
    private static function folderSegment(string $folder): string
    {
        $name = strtolower(trim($folder));
        if (in_array($name, FolderMailboxReader::WELL_KNOWN, true)) {
            return $name;
        }
        if (trim($folder) === '') {
            throw new MailboxReadFailed('A folder is required.');
        }

        return rawurlencode(trim($folder));
    }

    /**
     * One page of a delta query. Only a Graph URL is followed. A 410 (token expired) is a
     * {@see MailboxSyncExpired}.
     *
     * @return array{rows: list<array<string, mixed>>, nextLink: ?string, deltaLink: ?string}
     */
    public function deltaPage(string $url, int $pageSize = 25): array
    {
        if (!str_starts_with($url, self::GRAPH_BASE . '/')) {
            throw new MailboxReadFailed('That is not a Microsoft Graph URL.');
        }
        $page = $this->get($url, $pageSize);

        return [
            'rows' => self::rows($page['value'] ?? null),
            'nextLink' => self::continuation($page['@odata.nextLink'] ?? null),
            'deltaLink' => self::continuation($page['@odata.deltaLink'] ?? null),
        ];
    }

    /**
     * A message's Internet headers, names lower-cased (the first of a repeated header wins).
     *
     * @return array<string, string>
     */
    public function internetHeaders(string $mailbox, string $messageId): array
    {
        $row = $this->get($this->mailboxUrl($mailbox, '/messages/' . rawurlencode($messageId), ['$select' => 'id,internetMessageHeaders']));

        return self::headerMap($row['internetMessageHeaders'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public static function headerMap(mixed $value): array
    {
        $headers = [];
        foreach (self::rows($value) as $row) {
            $name = strtolower(trim(self::text($row['name'] ?? null, '')));
            if ($name !== '' && !isset($headers[$name])) {
                $headers[$name] = trim(self::text($row['value'] ?? null, ''));
            }
        }

        return $headers;
    }

    public function getMessage(string $mailbox, string $messageId): MailboxMessage
    {
        $row = $this->get($this->mailboxUrl($mailbox, '/messages/' . rawurlencode($messageId), ['$select' => self::MESSAGE_FIELDS]));
        $body = is_array($row['body'] ?? null) ? $row['body'] : [];

        return new MailboxMessage(
            self::summary($row),
            strtolower(self::text($body['contentType'] ?? null, 'html')) === 'text' ? MailboxMessage::BODY_TEXT : MailboxMessage::BODY_HTML,
            self::text($body['content'] ?? null, ''),
            self::recipients($row['toRecipients'] ?? null),
            self::recipients($row['ccRecipients'] ?? null),
        );
    }

    public function listAttachments(string $mailbox, string $messageId): array
    {
        $url = $this->mailboxUrl($mailbox, '/messages/' . rawurlencode($messageId) . '/attachments', ['$select' => self::ATTACHMENT_FIELDS]);
        $summaries = [];
        while ($url !== null) {
            $page = $this->get($url);
            foreach (self::rows($page['value'] ?? null) as $row) {
                $summaries[] = self::attachmentSummary($row);
            }
            $url = self::continuation($page['@odata.nextLink'] ?? null);
        }

        return $summaries;
    }

    public function getAttachment(string $mailbox, string $messageId, string $attachmentId): MailboxAttachment
    {
        $row = $this->get($this->mailboxUrl($mailbox, '/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId)));
        if (($row['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
            throw new MailboxReadFailed('That attachment is not a file (it is an attached item or a link), so it has no content to read.');
        }
        $encoded = $row['contentBytes'] ?? null;
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($content === false) {
            throw new MailboxReadFailed('Microsoft Graph returned the attachment without readable content.');
        }

        return new MailboxAttachment(self::attachmentSummary($row), $content);
    }

    /**
     * @param array<string, string> $query
     */
    private function mailboxUrl(string $mailbox, string $path, array $query = []): string
    {
        $url = self::GRAPH_BASE . '/users/' . rawurlencode($mailbox) . $path;

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A continuation link is followed only when it is Graph's own.
     */
    private static function continuation(mixed $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if (!is_string($link) || !str_starts_with($link, self::GRAPH_BASE . '/')) {
            throw new MailboxReadFailed('Microsoft Graph returned an unexpected continuation URL.');
        }

        return $link;
    }

    /**
     * One Graph GET, with the retry rules; returns the decoded JSON object.
     *
     * @return array<string, mixed>
     */
    private function get(string $url, ?int $pageSize = null): array
    {
        $prefer = $pageSize === null ? self::IMMUTABLE_IDS : self::IMMUTABLE_IDS . ', odata.maxpagesize=' . max(1, $pageSize);
        $status = 0;
        $body = '';
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = $this->accessToken();
            try {
                $response = $this->http->getJson($url, [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'Prefer' => $prefer,
                ]);
            } catch (Throwable) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new MailboxReadUnavailable('Microsoft Graph could not be reached.');
                }
                $this->pause($attempt);
                continue;
            }

            $status = $response['status'];
            $body = $response['body'];
            if ($status >= 200 && $status <= 299) {
                return self::decode($body);
            }
            if ($status === 401 || $status === 403) {
                $this->token = null;
                $this->tokenExpiresAt = 0;
                throw new MailboxAccessDenied(self::describe($status, $body) . ' The finance Graph registration may lack admin-consented Mail.Read, or an Application Access Policy leaves this mailbox out.');
            }
            if ($status === 410) {
                throw new MailboxSyncExpired('Microsoft Graph says this sync token has expired.');
            }
            if ($status === 404) {
                throw new MailboxItemNotFound('The mailbox, message or attachment was not found.');
            }
            // 4xx other than 429 means the request is wrong; retrying only burns the throttle.
            if ($status >= 400 && $status < 500 && $status !== 429) {
                throw new MailboxReadFailed(self::describe($status, $body));
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                $this->pause($this->retryDelay($response, $attempt));
            }
        }

        throw new MailboxReadUnavailable(self::describe($status, $body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MailboxReadFailed('Microsoft Graph answered with something that was not JSON.');
        }
        if (!is_array($decoded)) {
            throw new MailboxReadFailed('Microsoft Graph answered with something that was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function summary(array $row): MailboxMessageSummary
    {
        $id = $row['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new MailboxReadFailed('Microsoft Graph returned a message with no id.');
        }
        $from = is_array($row['from'] ?? null) && is_array($row['from']['emailAddress'] ?? null) ? $row['from']['emailAddress'] : [];
        try {
            $received = new DateTimeImmutable(self::text($row['receivedDateTime'] ?? null, ''), new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new MailboxReadFailed('Microsoft Graph returned a message with no received time.');
        }

        return new MailboxMessageSummary(
            $id,
            self::text($row['subject'] ?? null, ''),
            strtolower(trim(self::text($from['address'] ?? null, ''))),
            self::text($from['name'] ?? null, ''),
            $received->setTimezone(new DateTimeZone('UTC')),
            ($row['hasAttachments'] ?? false) === true,
            self::text($row['bodyPreview'] ?? null, ''),
        );
    }

    /**
     * @return list<MailboxRecipient>
     */
    private static function recipients(mixed $value): array
    {
        $recipients = [];
        foreach (self::rows($value) as $row) {
            $address = is_array($row['emailAddress'] ?? null) ? $row['emailAddress'] : [];
            $text = strtolower(trim(self::text($address['address'] ?? null, '')));
            if ($text !== '') {
                $recipients[] = new MailboxRecipient($text, self::text($address['name'] ?? null, ''));
            }
        }

        return $recipients;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function attachmentSummary(array $row): MailboxAttachmentSummary
    {
        $id = $row['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new MailboxReadFailed('Microsoft Graph returned an attachment with no id.');
        }

        return new MailboxAttachmentSummary(
            $id,
            self::text($row['name'] ?? null, ''),
            self::text($row['contentType'] ?? null, 'application/octet-stream'),
            is_numeric($row['size'] ?? null) ? (int) $row['size'] : 0,
            ($row['isInline'] ?? false) === true,
        );
    }

    private static function text(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
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
            throw new MailboxReadUnavailable('The Microsoft sign-in endpoint could not be reached.');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new MailboxReadUnavailable('The finance Graph registration was refused a token (status ' . $response['status'] . '). Check its tenant, client id and secret.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MailboxReadUnavailable('The Microsoft token response was not JSON.');
        }
        if (!is_array($decoded) || !is_string($decoded['access_token'] ?? null) || $decoded['access_token'] === '') {
            throw new MailboxReadUnavailable('The Microsoft token response had no access token.');
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

        return 'Microsoft Graph refused the read with status ' . $status . $code . '.';
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
