<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes\Application\Exception;

use RuntimeException;

/**
 * The store file is missing, unreadable, empty or of another schema version. The message may name a cause for the log;
 * the API never sends it to the client.
 */
final class CableRoutesStoreUnavailable extends RuntimeException
{
}
