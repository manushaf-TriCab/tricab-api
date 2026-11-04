<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Intake\IncomingMail;
use TriCab\Core\Mail\Intake\MailBackfillSource;
use TriCab\Core\Mail\Intake\MailChanges;
use TriCab\Core\Mail\Intake\MailDeltaSource;
use TriCab\Core\Mail\Intake\MailFolder;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * {@see MailDeltaSource} over the platform's Graph reader, so auth, retries, immutable ids and the
 * "only follow Graph's own links" rule are the reader's and not copied here.
 */
final class GraphMailDeltaSource implements MailDeltaSource, MailBackfillSource
{
    private const MAX_FOLDERS = 200;

    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly GraphMailboxReader $graph,
        private readonly int $pageSize = 25,
    ) {
    }

    public function folders(string $mailbox): array
    {
        return $this->subtree($mailbox);
    }

    public function backfillFolders(string $mailbox, bool $includeDeleted): array
    {
        $folders = $this->subtree($mailbox);
        if ($includeDeleted) {
            $folders[] = new MailFolder('deleteditems', 'Deleted Items', 'Deleted Items');
        }

        return $folders;
    }

    public function listPage(string $mailbox, MailFolder $folder, ?string $link, DateTimeImmutable $since): MailChanges
    {
        $url = $link ?? $this->graph->folderMessagesStartUrl($mailbox, $folder->id, $since, MailReaderPass::SELECT);
        $page = $this->graph->deltaPage($url, $this->pageSize);
        $messages = [];
        foreach ($page['rows'] as $row) {
            $messages[] = self::message($row);
        }
        $next = $page['nextLink'];

        return new MailChanges($messages, [], $next ?? '', $next === null);
    }

    /**
     * The Inbox and every folder below it, breadth first. Bounded (depth and count) so a pathological tree cannot
     * run away; only the Inbox's own subtree is ever walked.
     *
     * @return list<MailFolder>
     */
    private function subtree(string $mailbox): array
    {
        $folders = [MailFolder::inbox()];
        $queue = [[MailFolder::INBOX, 'Inbox', 1]];
        while ($queue !== [] && count($folders) < self::MAX_FOLDERS) {
            [$parentId, $parentPath, $depth] = array_shift($queue);
            foreach ($this->graph->childFolders($mailbox, $parentId) as $child) {
                $path = $parentPath . '/' . $child['name'];
                $folders[] = new MailFolder($child['id'], $child['name'], $path);
                if ($child['childCount'] > 0 && $depth < self::MAX_DEPTH) {
                    $queue[] = [$child['id'], $path, $depth + 1];
                }
                if (count($folders) >= self::MAX_FOLDERS) {
                    break;
                }
            }
        }

        return $folders;
    }

    public function changes(string $mailbox, ?string $link, DateTimeImmutable $since, string $folderId = MailFolder::INBOX): MailChanges
    {
        $url = $link ?? $this->graph->deltaStartUrl($mailbox, $folderId, $since, MailReaderPass::SELECT);
        $page = $this->graph->deltaPage($url, $this->pageSize);

        $messages = [];
        $removed = [];
        foreach ($page['rows'] as $row) {
            if (array_key_exists('@removed', $row)) {
                $id = $row['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $removed[] = $id;
                }
                continue;
            }
            $messages[] = self::message($row);
        }

        $next = $page['nextLink'];
        $resume = $next ?? $page['deltaLink'];
        if ($resume === null) {
            throw new MailboxReadFailed('Microsoft Graph returned a delta page with neither a next link nor a delta link.');
        }

        return new MailChanges($messages, $removed, $resume, $next === null);
    }

    public function headers(string $mailbox, string $graphMessageId): array
    {
        return $this->graph->internetHeaders($mailbox, $graphMessageId);
    }

    /** @param array<string, mixed> $row */
    private static function message(array $row): IncomingMail
    {
        $id = $row['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new MailboxReadFailed('Microsoft Graph returned a message with no id.');
        }
        try {
            $received = new DateTimeImmutable(self::text($row['receivedDateTime'] ?? null), new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new MailboxReadFailed('Microsoft Graph returned a message with no received time.');
        }
        $from = self::party($row['from'] ?? null);

        return new IncomingMail(
            $id,
            trim(self::text($row['internetMessageId'] ?? null)),
            self::text($row['conversationId'] ?? null),
            self::text($row['subject'] ?? null),
            $from ?? new MailParty('', ''),
            self::parties($row['toRecipients'] ?? null),
            self::parties($row['ccRecipients'] ?? null),
            $received->setTimezone(new DateTimeZone('UTC')),
            self::text($row['bodyPreview'] ?? null),
            ($row['isRead'] ?? false) === true,
            ($row['hasAttachments'] ?? false) === true,
            self::text($row['@odata.type'] ?? null, '#microsoft.graph.message'),
            array_key_exists('internetMessageHeaders', $row) ? GraphMailboxReader::headerMap($row['internetMessageHeaders']) : null,
        );
    }

    private static function party(mixed $value): ?MailParty
    {
        $address = is_array($value) && is_array($value['emailAddress'] ?? null) ? $value['emailAddress'] : null;
        if ($address === null) {
            return null;
        }

        return new MailParty(self::text($address['name'] ?? null), strtolower(trim(self::text($address['address'] ?? null))));
    }

    /** @return list<MailParty> */
    private static function parties(mixed $value): array
    {
        $parties = [];
        foreach (is_array($value) ? $value : [] as $row) {
            $party = self::party($row);
            if ($party !== null && $party->address !== '') {
                $parties[] = $party;
            }
        }

        return $parties;
    }

    private static function text(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
