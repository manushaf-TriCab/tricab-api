<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\RfqContactOption;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/**
 * Customers and contacts for the quote header's lookups: the source's
 * `search_customers` and `get_contacts`, over the platform's synced copy of
 * the 4js customer book rather than the ERP tables.
 *
 * Every customer is searchable whatever its status, as in the source: the
 * editor names the customer the RFQ came from, and eligibility is checked
 * when the quote is pushed.
 */
interface RfqCustomerDirectory
{
    /**
     * Customers whose code starts with, or whose name contains, `$query`;
     * ordered by code, then id (several customers can share a code).
     *
     * @return list<RfqCustomerOption>
     *
     * @throws RfqLookupUnavailable
     */
    public function search(string $query, int $limit): array;

    /**
     * The contacts of the customer with this 4js customer id, by name. Only
     * contacts that carry a 4js contact id: one without cannot go on a quote.
     *
     * @return list<RfqContactOption>
     *
     * @throws RfqLookupUnavailable
     */
    public function contacts(int $customerId, int $limit): array;

    /**
     * The customer with this 4js customer id, or null when the book has none.
     * What a saved header names the customer by (parity plan E2.3).
     *
     * @throws RfqLookupUnavailable
     */
    public function find(int $customerId): ?RfqCustomerOption;
}
