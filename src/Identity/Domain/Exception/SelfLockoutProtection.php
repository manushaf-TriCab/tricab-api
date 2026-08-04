<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class SelfLockoutProtection extends DomainException
{
    public static function cannotDisableOwnAccount(): self
    {
        return new self('Administrators cannot suspend or disable their own account.');
    }

    public static function cannotRemoveOwnLastAdministratorRole(): self
    {
        return new self('Administrators cannot remove their own last ADMINISTRATOR role.');
    }
}
