<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use RuntimeException;

/** The refresh will not run: a missing ledger, changed tables or rows that are no longer the import. Nothing was written. */
final class LegacyRfqSyncRefused extends RuntimeException
{
}
