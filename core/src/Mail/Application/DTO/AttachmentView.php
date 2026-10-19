<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** `MailAttachment`: a file attachment's name, type and size. The bytes stay in Exchange. */
final readonly class AttachmentView
{
    public function __construct(
        public string $id,
        public string $messageId,
        public string $fileName,
        public string $mimeType,
        public int $sizeBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'messageId' => $this->messageId,
            'fileName' => $this->fileName,
            'mimeType' => $this->mimeType,
            'sizeBytes' => $this->sizeBytes,
        ];
    }
}
