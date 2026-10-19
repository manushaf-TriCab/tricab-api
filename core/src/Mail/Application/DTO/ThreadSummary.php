<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailThreadSummary`: one row in the list. */
final readonly class ThreadSummary
{
    public function __construct(
        public string $id,
        public string $mailboxId,
        public string $subject,
        public MailParty $from,
        public string $preview,
        public DateTimeImmutable $latestAt,
        public int $messageCount,
        public bool $hasAttachments,
        public bool $unread,
        public string $label,
        public float $confidence,
        public string $effectiveLabel,
        public bool $decided,
        public bool $skipped,
        public string $reviewState,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mailboxId' => $this->mailboxId,
            'subject' => $this->subject,
            'from' => $this->from->toArray(),
            'preview' => $this->preview,
            'latestAt' => MailTime::iso($this->latestAt),
            'messageCount' => $this->messageCount,
            'hasAttachments' => $this->hasAttachments,
            'unread' => $this->unread,
            'label' => $this->label,
            'confidence' => $this->confidence,
            'effectiveLabel' => $this->effectiveLabel,
            'decided' => $this->decided,
            'skipped' => $this->skipped,
            'reviewState' => $this->reviewState,
        ];
    }
}
