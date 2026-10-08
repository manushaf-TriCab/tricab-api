<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mailbox;

use DateTimeImmutable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRefused;
use InvalidArgumentException;
use TriCab\Core\Shared\Mailbox\FolderMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxFolders;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;
use TriCab\Core\Shared\Time\Clock;

/**
 * The finance rules around a mailbox read, from the portal's Mail Service
 * (`mail_messages_list`, `mail_message_get`, `mail_attachments_list`, `_mailbox_capability`,
 * `_mail_actor`, `_parse_mail_window`; inventory `portal.md` 2, 7, 8):
 *
 * - the mailbox must exist, be active, and the app must hold a **read** grant on it;
 * - a window is at most 31 days, `from` not after `to`, and returns at most 500 messages;
 * - a message id is required and at most 1000 characters;
 * - when a person is asking, their mailbox scope must allow the mailbox (no scope rows
 *   means unrestricted, as in the portal);
 * - every successful read writes an audit row (`finance.mail.*`) with facts only:
 *   counts, a window, a SHA-256 of an id, never a subject, a body or a file name.
 *
 * **An audit row that cannot be written fails the read** and the mail is not returned:
 * reading a shared mailbox is what the log is for, and the portal wrote it in the same
 * request. (A job's own failure handling then records it.)
 */
final class FinanceMailboxReadService implements FinanceMailboxReader
{
    public const MAX_MESSAGES = 500;

    public const MAX_WINDOW_DAYS = 31;

    public const MAX_ID_LENGTH = 1000;

    public const ACTION_MESSAGES_LISTED = 'finance.mail.messages_listed';

    public const ACTION_MESSAGE_READ = 'finance.mail.message_read';

    public const ACTION_ATTACHMENTS_LISTED = 'finance.mail.attachments_listed';

    public const ACTION_ATTACHMENT_READ = 'finance.mail.attachment_read';

    public function __construct(
        private readonly FinanceMailboxRegistry $registry,
        private readonly MailboxReader $reader,
        private readonly FinanceAccessScopeReader $scopes,
        private readonly FinanceAuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    public function listMessages(string $application, string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, ?int $maxMessages = null, ?int $actorUserId = null, ?string $folder = null): array
    {
        $box = $this->authorise($application, $mailbox, $actorUserId);
        if ($from > $to) {
            throw new FinanceMailInvalid('The start of the window must not be after its end.');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            throw new FinanceMailInvalid(sprintf('Mailbox queries cannot exceed %d days.', self::MAX_WINDOW_DAYS));
        }
        $limit = $maxMessages === null || $maxMessages === 0
            ? self::MAX_MESSAGES
            : min(max($maxMessages, 1), self::MAX_MESSAGES);

        if ($folder === null) {
            $messages = $this->reader->listMessages($box->address, $from, $to, $limit);
        } else {
            try {
                $folder = MailboxFolders::normalise($folder);
            } catch (InvalidArgumentException $invalid) {
                throw new FinanceMailInvalid($invalid->getMessage());
            }
            if (!$this->reader instanceof FolderMailboxReader) {
                throw new MailboxReadUnavailable('This mailbox reader cannot list a single folder.');
            }
            $messages = $this->reader->listMessagesInFolder($box->address, $folder, $from, $to, $limit);
        }

        $utc = new \DateTimeZone('UTC');
        $this->record(self::ACTION_MESSAGES_LISTED, $application, $box, $actorUserId, [
            'from' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'count' => count($messages),
        ] + ($folder === null ? [] : ['folder' => $folder]));

        return $messages;
    }

    public function getMessage(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): MailboxMessage
    {
        $box = $this->authorise($application, $mailbox, $actorUserId);
        $messageId = self::id($messageId, 'A valid message id is required.');

        $message = $this->reader->getMessage($box->address, $messageId);

        $this->record(self::ACTION_MESSAGE_READ, $application, $box, $actorUserId, ['messageIdSha256' => hash('sha256', $messageId)]);

        return $message;
    }

    public function listAttachments(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): array
    {
        $box = $this->authorise($application, $mailbox, $actorUserId);
        $messageId = self::id($messageId, 'A valid message id is required.');

        $attachments = $this->reader->listAttachments($box->address, $messageId);

        $this->record(self::ACTION_ATTACHMENTS_LISTED, $application, $box, $actorUserId, [
            'messageIdSha256' => hash('sha256', $messageId),
            'count' => count($attachments),
        ]);

        return $attachments;
    }

    public function getAttachment(string $application, string $mailbox, string $messageId, string $attachmentId, ?int $actorUserId = null): MailboxAttachment
    {
        $box = $this->authorise($application, $mailbox, $actorUserId);
        $messageId = self::id($messageId, 'A valid message id is required.');
        $attachmentId = self::id($attachmentId, 'A valid attachment id is required.');

        $attachment = $this->reader->getAttachment($box->address, $messageId, $attachmentId);

        $this->record(self::ACTION_ATTACHMENT_READ, $application, $box, $actorUserId, [
            'messageIdSha256' => hash('sha256', $messageId),
            'attachmentIdSha256' => hash('sha256', $attachmentId),
            'bytes' => strlen($attachment->content),
        ]);

        return $attachment;
    }

    /**
     * The mailbox as the registry holds it (its canonical address), once the app, the
     * grant and the person's scope all allow the read.
     */
    private function authorise(string $application, string $mailbox, ?int $actorUserId): FinanceMailbox
    {
        if (!array_key_exists($application, Module::APPS)) {
            throw new FinanceMailInvalid('Unknown finance application.');
        }
        $address = trim($mailbox);
        $box = $address === '' ? null : $this->registry->find($address);
        if ($box === null || !$box->canRead($application)) {
            throw FinanceMailRefused::forReading($application, $address);
        }
        if ($actorUserId !== null && !$this->scopes->forUser($actorUserId, $application)->allowsMailbox($box->address)) {
            throw FinanceMailRefused::forReading($application, $box->address);
        }

        return $box;
    }

    private static function id(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_ID_LENGTH) {
            throw new FinanceMailInvalid($message);
        }

        return $value;
    }

    /**
     * @param array<string, scalar|null> $details
     */
    private function record(string $action, string $application, FinanceMailbox $box, ?int $actorUserId, array $details): void
    {
        $this->audit->record(new FinanceAuditEvent(
            $action,
            'mailbox',
            $box->id,
            $details + ['mailbox' => $box->address],
            $this->clock->now(),
            $actorUserId,
            $application,
        ));
    }
}
