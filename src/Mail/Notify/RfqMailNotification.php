<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Notify;

use DateTimeImmutable;

/** One person's "a new email RFQ is waiting in Quotegen" notice. */
final readonly class RfqMailNotification
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $draftPublicId,
        public string $title,
        public string $body,
        public string $link,
        public DateTimeImmutable $at,
        public bool $read,
    ) {
    }

    /** @return array{id: string, title: string, body: string, link: string, at: string, read: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'link' => $this->link, 'at' => $this->at->format('c'), 'read' => $this->read];
    }
}
