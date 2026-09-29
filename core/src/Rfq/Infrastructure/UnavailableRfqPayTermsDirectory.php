<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqPayTermsDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;

/** No legacy database configured: the pay-terms lookup says so. */
final class UnavailableRfqPayTermsDirectory implements RfqPayTermsDirectory
{
    public function all(): array
    {
        throw new RfqLookupUnavailable('pay terms');
    }
}
