<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule\Application\Exception;

use RuntimeException;

/**
 * The store file is missing, unreadable, of another schema version, or holds no run. The message may name a cause for
 * the log; the API never sends it to the client.
 */
final class ConductorScheduleStoreUnavailable extends RuntimeException
{
}
