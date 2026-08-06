<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Entity;

use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Customer\Domain\ValueObject\LegacyCustomerId;

final class Customer
{
    private readonly string $customerName;

    public function __construct(
        private readonly ?CustomerId $id,
        private readonly CustomerPublicId $publicId,
        private readonly ?CustomerCode $customerCode,
        string $customerName,
        private readonly CustomerStatus $status,
        private readonly ?string $addressLine1 = null,
        private readonly ?string $addressLine2 = null,
        private readonly ?string $suburb = null,
        private readonly ?string $postcode = null,
        private readonly ?string $countryCode = null,
        private readonly ?string $marketCode = null,
        private readonly ?string $salesRep = null,
        private readonly ?string $territory = null,
        private readonly ?string $phone = null,
        private readonly ?LegacyCustomerId $legacyCustomerId = null,
    ) {
        $normalized = trim($customerName);
        if ($normalized === '') {
            throw InvalidCustomerValue::for('CustomerName', $customerName);
        }

        $this->customerName = $normalized;
    }

    public function id(): ?CustomerId
    {
        return $this->id;
    }

    public function publicId(): CustomerPublicId
    {
        return $this->publicId;
    }

    public function customerCode(): ?CustomerCode
    {
        return $this->customerCode;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function status(): CustomerStatus
    {
        return $this->status;
    }

    public function addressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function addressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function suburb(): ?string
    {
        return $this->suburb;
    }

    public function postcode(): ?string
    {
        return $this->postcode;
    }

    public function countryCode(): ?string
    {
        return $this->countryCode;
    }

    public function marketCode(): ?string
    {
        return $this->marketCode;
    }

    public function salesRep(): ?string
    {
        return $this->salesRep;
    }

    public function territory(): ?string
    {
        return $this->territory;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function legacyCustomerId(): ?LegacyCustomerId
    {
        return $this->legacyCustomerId;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
