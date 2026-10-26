<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One row of `finance.ap2_mail_attachments`; the bytes are a `finance.attachments` file (`fileId`). */
final readonly class Ap2MailAttachment
{
    public function __construct(
        public string $id,
        public string $mailId,
        public ?string $fileId,
        public string $name,
        public int $size,
        public string $contentType,
        public DateTimeImmutable $createdAt,
        public ?string $graphAttachmentId = null,
    ) {
    }
}
