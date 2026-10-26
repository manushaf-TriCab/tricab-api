<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** What an Email approval is bound to: the draft, the supplier, the reply-to, the CC and the attachments. */
final readonly class Ap2MailDraft
{
    /**
     * @param list<string> $attachmentIds
     */
    public function __construct(
        public string $id,
        public ?string $supplierId,
        public string $subject,
        public string $draft,
        public string $replyTo,
        public string $cc,
        public array $attachmentIds,
    ) {
    }

    /** The prototype's `[draft, supplierId, replyTo, cc, attachments]`, hashed so it fits the snapshot column. */
    public function snapshot(): string
    {
        return hash('sha256', json_encode([$this->draft, $this->supplierId, $this->replyTo, $this->cc, $this->attachmentIds], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
