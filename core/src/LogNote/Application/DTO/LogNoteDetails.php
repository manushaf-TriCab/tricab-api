<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\DTO;

final readonly class LogNoteDetails
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
        public string $body,
        public string $bodyPreview,
        public ?string $callResultCode,
        public bool $isAiGenerated,
        public ?string $followUpAt,
        public string $createdByUserPublicId,
        public string $createdByDisplayName,
        public string $createdAtUtc,
        public ?string $countryCode,
        public ?string $territoryCode,
    ) {
    }

    public function toSummary(): LogNoteSummary
    {
        return new LogNoteSummary(
            publicId: $this->publicId,
            customerPublicId: $this->customerPublicId,
            customerCode: $this->customerCode,
            customerName: $this->customerName,
            contactPublicId: $this->contactPublicId,
            contactName: $this->contactName,
            contactPosition: $this->contactPosition,
            interactionTypeCode: $this->interactionTypeCode,
            interactionTypeLabel: $this->interactionTypeLabel,
            categoryCode: $this->categoryCode,
            categoryLabel: $this->categoryLabel,
            bodyPreview: $this->bodyPreview,
            isAiGenerated: $this->isAiGenerated,
            followUpAt: $this->followUpAt,
            createdByUserPublicId: $this->createdByUserPublicId,
            createdByDisplayName: $this->createdByDisplayName,
            createdAtUtc: $this->createdAtUtc,
        );
    }
}
