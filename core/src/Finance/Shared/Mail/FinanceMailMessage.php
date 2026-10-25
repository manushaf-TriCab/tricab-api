<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * What a finance app asks to send. The service validates and normalises it; the
 * transport only ever sees a message that passed.
 *
 * `application` is the finance app key (`TASK`, `CREDIT`, `COLLECT`, `AR`, `AP`).
 * `actorUserId` is an `identity.Users.UserId`, or null for a scheduled send or a
 * job: there is no actor. Whether the actor may send is the calling route's
 * permission check, not this service's.
 *
 * `replyToMessageId` (optional) is the Graph immutable id of a message in `mailbox` to answer in its own
 * conversation. A transport that can thread ({@see FinanceReplyMailTransport}) does; any other sends a
 * standalone message. It is not part of the ledger row.
 *
 * `calendar` (optional) is a calendar invite to send as the message's own `text/calendar` part, by a transport that can
 * ({@see FinanceMimeMailTransport}); any other sends the message as a normal one, the invite only as its attachment.
 *
 * `ownMailbox` marks a message sent from the acting person's own mailbox rather than a shared one in the
 * registry (Tri-Task's reminders, 13 Oct 2026). The calling app has checked that `mailbox` is that person's
 * own address; the service then needs no registry grant for it.
 */
final readonly class FinanceMailMessage
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     * @param list<FinanceMailAttachment> $attachments
     */
    public function __construct(
        public string $application,
        public string $mailbox,
        public array $to,
        public string $subject,
        public string $body,
        public array $cc = [],
        public array $bcc = [],
        public string $contentType = FinanceMailRules::CONTENT_HTML,
        public array $attachments = [],
        public ?string $idempotencyKey = null,
        public ?int $actorUserId = null,
        public ?string $replyToMessageId = null,
        public bool $ownMailbox = false,
        public ?FinanceMailCalendar $calendar = null,
    ) {
    }

    /**
     * The same message after validation: normalised addresses, trimmed subject
     * and key, upper-case content type.
     *
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     */
    public function normalised(string $mailbox, array $to, array $cc, array $bcc, string $subject, string $contentType, ?string $idempotencyKey): self
    {
        return new self(
            $this->application,
            $mailbox,
            $to,
            $subject,
            $this->body,
            $cc,
            $bcc,
            $contentType,
            $this->attachments,
            $idempotencyKey,
            $this->actorUserId,
            $this->replyToMessageId,
            $this->ownMailbox,
            $this->calendar,
        );
    }
}
