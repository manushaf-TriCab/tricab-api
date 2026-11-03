<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailLink`: the RFQ draft made from a thread, or a follow-up message joined to it. */
final readonly class LinkView
{
    /** Where tricab-web shows an RFQ. */
    public const RFQ_HREF = '/admin/quotegen/';

    public function __construct(
        public string $publicId,
        public string $reference,
        public string $title,
        public DateTimeImmutable $createdAt,
        /** `rfq_draft`: the draft made from the thread. `rfq_followup`: a message joined to that draft. */
        public string $kind = 'rfq_draft',
        /** The message a follow-up link is for; null for the draft itself. */
        public ?string $messageId = null,
        /**
         * A follow-up's proposal on the draft (RFQ M6.2): `pending` (waiting for a rep), `accepted`, `dismissed`, or
         * `none` (the email held no lines). Null for the draft itself and for a follow-up with no proposal.
         */
        public ?string $proposal = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'messageId' => $this->messageId,
            'publicId' => $this->publicId,
            'reference' => $this->reference,
            'title' => $this->title,
            'href' => self::RFQ_HREF . rawurlencode($this->publicId),
            'createdAt' => MailTime::iso($this->createdAt),
            'proposal' => $this->proposal,
        ];
    }
}
