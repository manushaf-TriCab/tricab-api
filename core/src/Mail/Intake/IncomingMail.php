<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;
use TriCab\Core\Mail\Application\DTO\MailParty;
use TriCab\Core\Mail\Application\DTO\NewAttachment;

/**
 * A message as the Graph delta query delivered it, before anything is recorded. `headers` is null when
 * Graph did not return the Internet headers with the delta row (the reader then asks for them); the keys
 * are lower-case header names.
 */
final readonly class IncomingMail
{
    /**
     * @param list<MailParty> $to
     * @param list<MailParty> $cc
     * @param array<string, string>|null $headers
     * @param list<NewAttachment> $attachments
     */
    public function __construct(
        public string $graphMessageId,
        public string $internetMessageId,
        public string $conversationId,
        public string $subject,
        public MailParty $from,
        public array $to,
        public array $cc,
        public DateTimeImmutable $receivedAt,
        public string $bodyPreview,
        public bool $isRead,
        public bool $hasAttachments,
        /** Graph's `@odata.type`: `#microsoft.graph.eventMessageRequest` and friends are meeting mail. */
        public string $odataType = '#microsoft.graph.message',
        public ?array $headers = null,
        public array $attachments = [],
    ) {
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->graphMessageId, $this->internetMessageId, $this->conversationId, $this->subject, $this->from, $this->to, $this->cc, $this->receivedAt, $this->bodyPreview, $this->isRead, $this->hasAttachments, $this->odataType, $headers, $this->attachments);
    }

    /** @param list<NewAttachment> $attachments */
    public function withAttachments(array $attachments): self
    {
        return new self($this->graphMessageId, $this->internetMessageId, $this->conversationId, $this->subject, $this->from, $this->to, $this->cc, $this->receivedAt, $this->bodyPreview, $this->isRead, $this->hasAttachments, $this->odataType, $this->headers, $attachments);
    }
}
