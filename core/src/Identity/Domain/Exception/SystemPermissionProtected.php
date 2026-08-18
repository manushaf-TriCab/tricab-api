<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class SystemPermissionProtected extends DomainException
{
    public static function cannotDeactivateWhileInUse(string $permissionCode): self
    {
        return new self(sprintf(
            'Protected permission "%s" cannot be deactivated while active users depend on it.',
            $permissionCode,
        ));
    }
}
