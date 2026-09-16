<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteBody;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteCategory;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteId;
use TriCab\Core\LogNote\Domain\ValueObject\LogNoteInteractionType;
use TriCab\Core\LogNote\Domain\ValueObject\LogNotePublicId;

final class LogNote
{
    public function __construct(
        private readonly ?LogNoteId $id,
        private readonly LogNotePublicId $publicId,
        private readonly CustomerId $customerId,
        private readonly CustomerPublicId $customerPublicId,
        private readonly ?CustomerContactId $customerContactId,
        private readonly ?string $contactNameSnapshot,
        private readonly ?string $contactPositionSnapshot,
        private readonly LogNoteInteractionType $interactionType,
        private readonly LogNoteCategory $category,
        private readonly LogNoteBody $body,
        private readonly ?string $callResultCode,
        private readonly ?DateTimeImmutable $followUpAt,
        private readonly bool $isAiGenerated,
        private readonly UserId $createdByUserId,
        private readonly DateTimeImmutable $createdAtUtc,
        private readonly ?int $legacyCustCallId = null,
        private readonly ?string $legacySalesRepLogin = null,
    ) {
    }

    public static function create(
        LogNotePublicId $publicId,
        CustomerId $customerId,
        CustomerPublicId $customerPublicId,
        ?CustomerContactId $customerContactId,
        ?string $contactNameSnapshot,
        ?string $contactPositionSnapshot,
        LogNoteInteractionType $interactionType,
        LogNoteCategory $category,
        LogNoteBody $body,
        ?string $callResultCode,
        ?DateTimeImmutable $followUpAt,
        bool $isAiGenerated,
        UserId $createdByUserId,
        DateTimeImmutable $createdAtUtc,
    ): self {
        return new self(
            id: null,
            publicId: $publicId,
            customerId: $customerId,
            customerPublicId: $customerPublicId,
            customerContactId: $customerContactId,
            contactNameSnapshot: self::nullableSnapshot($contactNameSnapshot),
            contactPositionSnapshot: self::nullableSnapshot($contactPositionSnapshot),
            interactionType: $interactionType,
            category: $category,
            body: $body,
            callResultCode: self::nullableSnapshot($callResultCode),
            followUpAt: $followUpAt,
            isAiGenerated: $isAiGenerated,
            createdByUserId: $createdByUserId,
            createdAtUtc: $createdAtUtc,
        );
    }

    public function withId(LogNoteId $id): self
    {
        return new self(
            id: $id,
            publicId: $this->publicId,
            customerId: $this->customerId,
            customerPublicId: $this->customerPublicId,
            customerContactId: $this->customerContactId,
            contactNameSnapshot: $this->contactNameSnapshot,
            contactPositionSnapshot: $this->contactPositionSnapshot,
            interactionType: $this->interactionType,
            category: $this->category,
            body: $this->body,
            callResultCode: $this->callResultCode,
            followUpAt: $this->followUpAt,
            isAiGenerated: $this->isAiGenerated,
            createdByUserId: $this->createdByUserId,
            createdAtUtc: $this->createdAtUtc,
            legacyCustCallId: $this->legacyCustCallId,
            legacySalesRepLogin: $this->legacySalesRepLogin,
        );
    }

    public function id(): ?LogNoteId
    {
        return $this->id;
    }

    public function publicId(): LogNotePublicId
    {
        return $this->publicId;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function customerPublicId(): CustomerPublicId
    {
        return $this->customerPublicId;
    }

    public function customerContactId(): ?CustomerContactId
    {
        return $this->customerContactId;
    }

    public function contactNameSnapshot(): ?string
    {
        return $this->contactNameSnapshot;
    }

    public function contactPositionSnapshot(): ?string
    {
        return $this->contactPositionSnapshot;
    }

    public function interactionType(): LogNoteInteractionType
    {
        return $this->interactionType;
    }

    public function category(): LogNoteCategory
    {
        return $this->category;
    }

    public function body(): LogNoteBody
    {
        return $this->body;
    }

    public function callResultCode(): ?string
    {
        return $this->callResultCode;
    }

    public function followUpAt(): ?DateTimeImmutable
    {
        return $this->followUpAt;
    }

    public function isAiGenerated(): bool
    {
        return $this->isAiGenerated;
    }

    public function createdByUserId(): UserId
    {
        return $this->createdByUserId;
    }

    public function createdAtUtc(): DateTimeImmutable
    {
        return $this->createdAtUtc;
    }

    public function legacyCustCallId(): ?int
    {
        return $this->legacyCustCallId;
    }

    public function legacySalesRepLogin(): ?string
    {
        return $this->legacySalesRepLogin;
    }

    private static function nullableSnapshot(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
