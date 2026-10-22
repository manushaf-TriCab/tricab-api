<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

/**
 * A request to start acting as another user that policy refuses.
 */
final class ActAsNotAllowed extends RuntimeException
{
    public static function callerNotAdministrator(): self
    {
        return new self('Only a platform administrator can act as another user.');
    }

    public static function targetIsSelf(): self
    {
        return new self('You cannot act as yourself.');
    }

    public static function targetIsAdministrator(): self
    {
        return new self('You cannot act as another administrator.');
    }

    public static function targetNotActive(): self
    {
        return new self('You can only act as an active user.');
    }
}
