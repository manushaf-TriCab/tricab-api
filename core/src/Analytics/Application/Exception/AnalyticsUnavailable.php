<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\Exception;

use RuntimeException;

/** The activity store could not be read or written. The message is safe to log; it is never returned to clients. */
final class AnalyticsUnavailable extends RuntimeException
{
}
