<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Rfq\Application\DTO\RfqContactOption;
use TriCab\Core\Rfq\Application\DTO\RfqCustomerOption;
use TriCab\Core\Rfq\Application\DTO\RfqPayTerm;
use TriCab\Core\Rfq\Contract\RfqCustomerDirectory;
use TriCab\Core\Rfq\Contract\RfqPayTermsDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/**
 * The quote header's lookups (parity plan E1): customer search, a customer's
 * contacts, and the pay terms with the code the dropdown starts on.
 *
 * Limits are the source's: 10 customers a search, 50 contacts a customer.
 * The default pay term is the source's `MAIL_DEFAULT_PAYTERMS`. Until the
 * customer sync carries each customer's own default (`tcus.ptm`), every
 * quote starts on it.
 */
final class RfqLookupService
{
    public const CUSTOMER_LIMIT = 10;
    public const CONTACT_LIMIT = 50;
    public const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly RfqCustomerDirectory $customers,
        private readonly RfqPayTermsDirectory $payTerms,
        private readonly string $defaultPayTermsCode = 'EOM030ND',
    ) {
    }

    /**
     * An empty search finds nothing, as in the source, rather than the
     * first page of the whole customer book.
     *
     * @return list<RfqCustomerOption>
     *
     * @throws RfqLookupUnavailable
     */
    public function searchCustomers(string $query): array
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);

        return $query === '' ? [] : $this->customers->search($query, self::CUSTOMER_LIMIT);
    }

    /**
     * @return list<RfqContactOption>
     *
     * @throws RfqLookupUnavailable
     */
    public function contacts(int $customerId): array
    {
        return $customerId <= 0 ? [] : $this->customers->contacts($customerId, self::CONTACT_LIMIT);
    }

    /**
     * @return array{terms: list<RfqPayTerm>, defaultCode: ?string}
     *
     * @throws RfqLookupUnavailable
     */
    public function payTerms(): array
    {
        $terms = $this->payTerms->all();
        $default = strtoupper(trim($this->defaultPayTermsCode));
        $known = array_filter($terms, static fn (RfqPayTerm $term): bool => strtoupper($term->code) === $default);

        // A default 4js does not list would pre-select nothing a rep can see.
        return ['terms' => $terms, 'defaultCode' => $known === [] ? null : reset($known)->code];
    }
}
