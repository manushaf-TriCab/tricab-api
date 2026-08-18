<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class InactiveRoleAssignment extends DomainException
{
    public static function forRoleCode(string $roleCode): self
    {
        return new self(sprintf('Inactive role "%s" cannot be newly assigned.', $roleCode));
    }
}
