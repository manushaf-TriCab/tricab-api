<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class InactivePermissionAssignment extends DomainException
{
    public static function forPermissionCode(string $permissionCode): self
    {
        return new self(sprintf('Inactive permission "%s" cannot be newly assigned.', $permissionCode));
    }
}
