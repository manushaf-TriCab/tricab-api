<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

/**
 * One Customer Contact email. Field values are plain text. The mail adapter
 * escapes them. Storage URLs and ICAR signature fields are never placed here.
 */
final readonly class CustomerContactReportNotice
{
    /**
     * @param list<string> $recipients
     * @param array<string, string> $fields
     */
    public function __construct(
        public string $event,
        public string $publicId,
        public array $recipients,
        public string $subject,
        public string $heading,
        public string $intro,
        public array $fields,
        public ?string $linkUrl,
        public string $linkLabel,
        public ?string $queueUrl = null,
    ) {
    }
}
