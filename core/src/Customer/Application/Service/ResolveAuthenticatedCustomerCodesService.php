<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

/**
 * Resolves distinct CustomerCode values for linked Azure customer public IDs.
 * CustomerCode is the authoritative bridge to legacy MySQL tc.cust.code.
 */
final class ResolveAuthenticatedCustomerCodesService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    /**
     * @param list<string> $customerPublicIds
     * @return list<string>
     */
    public function execute(array $customerPublicIds): array
    {
        $codes = [];
        foreach ($customerPublicIds as $customerPublicId) {
            if (!is_string($customerPublicId) || trim($customerPublicId) === '') {
                continue;
            }

            try {
                $publicId = CustomerPublicId::fromString($customerPublicId);
            } catch (InvalidCustomerValue) {
                continue;
            }

            $details = $this->customers->findDetailsByPublicId($publicId);
            if ($details === null) {
                continue;
            }

            $customerCode = $details->customerCode;
            if ($customerCode === null) {
                continue;
            }

            $normalized = strtoupper(trim($customerCode));
            if ($normalized === '') {
                continue;
            }

            $codes[$normalized] = $normalized;
        }

        $list = array_values($codes);
        sort($list);

        return $list;
    }
}
