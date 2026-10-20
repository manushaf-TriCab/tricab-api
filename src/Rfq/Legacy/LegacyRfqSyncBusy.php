<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use RuntimeException;

/** Another refresh holds the application lock (a slot swap runs two WebJob hosts). Nothing was written. */
final class LegacyRfqSyncBusy extends RuntimeException
{
}
