<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailMessage`. Only real attachments are listed: inline images are part of the body. */
final readonly class MessageView
{
    /**
     * @param list<MailParty> $to
     * @param list<MailParty> $cc
     * @param list<AttachmentView> $attachments
     */
    public function __construct(
        public string $id,
        public string $internetMessageId,
        public MailParty $from,
        public array $to,
        public array $cc,
        public DateTimeImmutable $receivedAt,
        public string $preview,
        public array $attachments,
        public bool $isRead = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'internetMessageId' => $this->internetMessageId,
            'from' => $this->from->toArray(),
            'to' => array_map(static fn (MailParty $p): array => $p->toArray(), $this->to),
            'cc' => array_map(static fn (MailParty $p): array => $p->toArray(), $this->cc),
            'receivedAt' => MailTime::iso($this->receivedAt),
            'preview' => $this->preview,
            'attachments' => array_map(static fn (AttachmentView $a): array => $a->toArray(), $this->attachments),
        ];
    }
}
