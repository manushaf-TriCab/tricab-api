<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;

/**
 * One message Tri-AP asks {@see ApMailService::deliver()} to send: who from and to, what goes out (`sentBody`, in `contentType`)
 * and what the history keeps (`storedBody`: the plain text typed for vendor mail and replies, the HTML for approval mail).
 *
 * `replyToMessageId` is the Graph immutable id of a message in `from` to answer in its own conversation; give it only when the
 * reply goes to that message's sender from that message's mailbox (decision D56). `attachInvoice` is recorded, not acted on: the
 * invoice document is among `attachments` already.
 */
final readonly class ApOutboundMail
{
    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<FinanceMailAttachment> $attachments
     */
    public function __construct(
        public string $from,
        public array $to,
        public string $subject,
        public string $sentBody,
        public string $storedBody,
        public array $cc = [],
        public string $contentType = FinanceMailRules::CONTENT_HTML,
        public array $attachments = [],
        public bool $attachInvoice = false,
        public ?string $replyToMessageId = null,
    ) {
    }
}
