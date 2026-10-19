<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAssessment;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\DTO\NewMessage;
use TriCab\Core\Mail\Application\DTO\RecordedMessage;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\MailVocabulary;

/**
 * What the RFQ reader (M3) and the classifier (M4) call to put mail into the inbox (plan 2.5). Not
 * wired to either yet.
 *
 * The reader calls {@see recordMessage()} for every message Graph delivers, before anything else
 * happens to it; Graph can deliver one twice, and the second call changes nothing (`created` is
 * false). The classifier then calls {@see recordAssessment()} with the same mailbox address and
 * internetMessageId, once per result: a re-run appends another and the latest wins. A pre-filter
 * skip is recorded the same way with {@see NewAssessment::skipped()}. **Every message must end up
 * with an assessment** (a failed model call is recorded as `uncertain` with the failure in the
 * reason), because a thread nobody has assessed is in no list.
 *
 * Writing here never changes review state or decisions: those are people's.
 */
final class MailIntakeRecorder
{
    private const MAX_RECIPIENTS = 200;

    public function __construct(private readonly MailStore $store)
    {
    }

    public function recordMessage(NewMessage $message): RecordedMessage
    {
        $address = self::address($message->mailboxAddress);
        if (trim($message->graphMessageId) === '' || trim($message->internetMessageId) === '') {
            throw new MailInputInvalid('A message needs its Graph id and its internetMessageId.');
        }
        $mailboxId = $this->store->ensureMailbox($address, $message->mailboxDisplayName !== '' ? $message->mailboxDisplayName : $address);

        return $this->store->upsertMessage($mailboxId, new NewMessage(
            $address,
            $message->graphMessageId,
            $message->internetMessageId,
            trim($message->conversationId),
            self::clip($message->subject, 500),
            self::party($message->from),
            self::parties($message->to),
            self::parties($message->cc),
            $message->receivedAt,
            self::clip($message->bodyPreview, 1000),
            $message->isRead,
            array_map(static fn (NewAttachment $a): NewAttachment => new NewAttachment(
                $a->graphAttachmentId,
                self::clip($a->fileName, 500),
                self::clip($a->mimeType, 200),
                max(0, $a->sizeBytes),
                $a->isInline,
            ), $message->attachments),
            $message->mailboxDisplayName,
        ));
    }

    /** @throws MailNotFound when the message was never recorded: record it first */
    public function recordAssessment(string $mailboxAddress, string $internetMessageId, NewAssessment $assessment): void
    {
        $mailboxId = $this->store->ensureMailbox(self::address($mailboxAddress), self::address($mailboxAddress));
        $messageId = $this->store->findMessageId($mailboxId, $internetMessageId);
        if ($messageId === null) {
            throw new MailNotFound('That message has not been recorded, so it cannot be assessed.');
        }
        $this->store->appendAssessment($messageId, $assessment);
    }

    /** The reader's heartbeat: shown as the mailbox's connection and last sync. */
    public function recordSync(string $mailboxAddress, string $connection, DateTimeImmutable $at, string $displayName = ''): void
    {
        if (!in_array($connection, MailVocabulary::CONNECTIONS, true)) {
            throw new MailInputInvalid('connection must be one of: ' . implode(', ', MailVocabulary::CONNECTIONS) . '.');
        }
        $address = self::address($mailboxAddress);
        $this->store->recordSync($this->store->ensureMailbox($address, $displayName !== '' ? $displayName : $address), $connection, $at);
    }

    private static function address(string $value): string
    {
        $address = strtolower(trim($value));
        if ($address === '') {
            throw new MailInputInvalid('A mailbox address is required.');
        }

        return $address;
    }

    private static function party(MailParty $party): MailParty
    {
        return new MailParty(self::clip($party->name, 200), self::clip(strtolower(trim($party->address)), 320));
    }

    /**
     * @param list<MailParty> $parties
     * @return list<MailParty>
     */
    private static function parties(array $parties): array
    {
        return array_map(self::party(...), array_slice($parties, 0, self::MAX_RECIPIENTS));
    }

    private static function clip(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
