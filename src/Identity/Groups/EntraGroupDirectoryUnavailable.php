<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use RuntimeException;

/**
 * Entra cannot be read right now for a reason that is not the group's fault. Typed, so callers can
 * tell "needs a person" (permission, configuration) apart from a plain failure, and never retry in a loop.
 */
abstract class EntraGroupDirectoryUnavailable extends RuntimeException
{
    /** The outcome code stored on groups and printed by the sync job. */
    abstract public function outcome(): string;
}
