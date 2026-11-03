<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** The RFQ draft made from a thread. */
final readonly class NewLink
{
    public function __construct(
        public string $publicId,
        public string $reference,
        public string $title,
        public ?int $createdByUserId,
        public DateTimeImmutable $createdAt,
        /** The message the draft was made from, so that message arriving again is not joined to its own draft as a follow-up. */
        public ?string $messageId = null,
    ) {
    }
}
