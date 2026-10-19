<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** `MailLink`: the RFQ draft made from a thread. */
final readonly class LinkView
{
    /** Where tricab-web shows an RFQ. */
    public const RFQ_HREF = '/admin/rfqs/';

    public function __construct(
        public string $publicId,
        public string $reference,
        public string $title,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => 'rfq_draft',
            'publicId' => $this->publicId,
            'reference' => $this->reference,
            'title' => $this->title,
            'href' => self::RFQ_HREF . rawurlencode($this->publicId),
            'createdAt' => MailTime::iso($this->createdAt),
        ];
    }
}
