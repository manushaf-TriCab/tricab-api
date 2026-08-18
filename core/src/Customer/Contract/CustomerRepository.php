<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Contract;

use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Application\DTO\CustomerSummaryCounts;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Customer\Domain\ValueObject\LegacyCustomerId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface CustomerRepository
{
    public function list(CustomerListCriteria $criteria): CustomerListResult;

    public function findDetailsByPublicId(CustomerPublicId $publicId): ?CustomerDetails;

    public function findIdByPublicId(CustomerPublicId $publicId): ?CustomerId;

    public function customerCodeExists(CustomerCode $customerCode, ?CustomerPublicId $exceptPublicId = null): bool;

    public function create(
        ?CustomerCode $customerCode,
        string $customerName,
        CustomerStatus $status,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        ?LegacyCustomerId $legacyCustomerId,
        UserId $actorUserId,
    ): CustomerDetails;

    public function update(
        CustomerPublicId $publicId,
        ?CustomerCode $customerCode,
        string $customerName,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $suburb,
        ?string $postcode,
        ?string $countryCode,
        ?string $marketCode,
        ?string $salesRep,
        ?string $territory,
        ?string $phone,
        UserId $actorUserId,
    ): CustomerDetails;

    public function changeStatus(
        CustomerPublicId $publicId,
        CustomerStatus $status,
        UserId $actorUserId,
    ): CustomerDetails;

    public function summarizeCounts(): CustomerSummaryCounts;
}
