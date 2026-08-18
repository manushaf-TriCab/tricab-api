<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Entity;

use TriCab\Core\Customer\Domain\Exception\InactiveContactPortalAccess;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\LegacyContactId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class CustomerContact
{
    private readonly string $displayName;
    private readonly PortalAccessStatus $portalAccessStatus;

    public function __construct(
        private readonly ?CustomerContactId $id,
        private readonly CustomerContactPublicId $publicId,
        private readonly CustomerId $customerId,
        string $displayName,
        private readonly ?EmailAddress $email = null,
        private readonly ?string $position = null,
        private readonly ?string $phone = null,
        private readonly ?string $mobile = null,
        private readonly bool $isPrimary = false,
        private readonly bool $isActive = true,
        ?PortalAccessStatus $portalAccessStatus = null,
        private readonly ?LegacyContactId $legacyContactId = null,
    ) {
        $normalized = trim($displayName);
        if ($normalized === '') {
            throw InvalidCustomerValue::for('DisplayName', $displayName);
        }

        $this->displayName = $normalized;
        $this->portalAccessStatus = $portalAccessStatus ?? PortalAccessStatus::notInvited();
    }

    public function id(): ?CustomerContactId
    {
        return $this->id;
    }

    public function publicId(): CustomerContactPublicId
    {
        return $this->publicId;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function email(): ?EmailAddress
    {
        return $this->email;
    }

    public function normalizedEmail(): ?string
    {
        return $this->email?->toString();
    }

    public function position(): ?string
    {
        return $this->position;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function mobile(): ?string
    {
        return $this->mobile;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function portalAccessStatus(): PortalAccessStatus
    {
        return $this->portalAccessStatus;
    }

    public function legacyContactId(): ?LegacyContactId
    {
        return $this->legacyContactId;
    }

    /**
     * Asserts the contact is eligible to receive customer-portal access
     * (must be active and have a verified email address).
     */
    public function assertCanReceivePortalAccess(): void
    {
        if (!$this->isActive) {
            throw InactiveContactPortalAccess::forContact($this->publicId->toString());
        }

        if ($this->email === null) {
            throw InvalidCustomerValue::for('Email', '');
        }
    }
}
