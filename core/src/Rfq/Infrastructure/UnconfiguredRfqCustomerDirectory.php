<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/** No customer book behind this API (no Azure SQL): every lookup says so. */
final class UnconfiguredRfqCustomerDirectory implements RfqCustomerDirectory
{
    public function search(string $query, int $limit): array
    {
        throw new RfqLookupUnavailable('customer');
    }

    public function contacts(int $customerId, int $limit): array
    {
        throw new RfqLookupUnavailable('contact');
    }

    public function find(int $customerId): ?RfqCustomerOption
    {
        throw new RfqLookupUnavailable('customer');
    }
}
