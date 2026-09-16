<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

/**
 * Resolves distinct market codes for a set of linked customer public IDs
 * from the authoritative Customers.MarketCode field (via CustomerRepository).
 */
final class ResolveAuthenticatedMarketCodesService
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

            $details = $this->customers->findDetailsByPublicId(
                CustomerPublicId::fromString($customerPublicId),
            );
            if ($details === null) {
                continue;
            }

            $marketCode = $details->marketCode;
            if ($marketCode === null) {
                continue;
            }

            $normalized = strtoupper(trim($marketCode));
            if ($normalized === '') {
                continue;
            }

            $codes[$normalized] = true;
        }

        $list = array_keys($codes);
        sort($list);

        return $list;
    }
}
