<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\RfqPayTerm;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/**
 * The 4js payment terms for the Pay Terms dropdown: the source's
 * `list_pay_terms` (`descript` desc_type 33), ordered by code.
 *
 * Read from the legacy database until the customer sync carries pay terms
 * into the platform (owner decision, 28 Sep 2026).
 */
interface RfqPayTermsDirectory
{
    /**
     * @return list<RfqPayTerm>
     *
     * @throws RfqLookupUnavailable
     */
    public function all(): array;
}
