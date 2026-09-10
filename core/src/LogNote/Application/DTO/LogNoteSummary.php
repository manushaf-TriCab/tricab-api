<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\DTO;

final readonly class LogNoteSummary
{
    public function __construct(
        public string $publicId,
        public string $customerPublicId,
        public ?string $customerCode,
        public string $customerName,
        public ?string $contactPublicId,
        public ?string $contactName,
        public ?string $contactPosition,
        public string $interactionTypeCode,
        public string $interactionTypeLabel,
        public string $categoryCode,
        public string $categoryLabel,
        public string $bodyPreview,
        public bool $isAiGenerated,
        public ?string $followUpAt,
        public string $createdByUserPublicId,
        public string $createdByDisplayName,
        public string $createdAtUtc,
    ) {
    }
}
