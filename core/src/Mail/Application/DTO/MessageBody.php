<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailMessageBody`: already cleaned on the server; the client sanitises again. */
final readonly class MessageBody
{
    public function __construct(
        public string $messageId,
        public string $html,
        public bool $remoteImagesBlocked,
        public ?string $quotedHtml,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'html' => $this->html,
            'remoteImagesBlocked' => $this->remoteImagesBlocked,
            'quotedHtml' => $this->quotedHtml,
        ];
    }
}
