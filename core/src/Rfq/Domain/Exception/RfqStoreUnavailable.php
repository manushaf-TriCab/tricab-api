<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Exception;

use RuntimeException;

/** There is no RFQ store behind this API (no Azure SQL). Maps to 503. */
final class RfqStoreUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('RFQ storage is not configured on this server.');
    }
}
