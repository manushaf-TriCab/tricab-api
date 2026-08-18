<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class LastAdminProtection extends DomainException
{
    public static function cannotDisableFinalAdministrator(): self
    {
        return new self('Cannot suspend or disable the final active administrator.');
    }

    public static function cannotRemoveFinalAdministratorRole(): self
    {
        return new self('Cannot remove the ADMINISTRATOR role from the final active administrator.');
    }
}
