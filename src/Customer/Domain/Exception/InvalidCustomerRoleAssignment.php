<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Exception;

use DomainException;

final class InvalidCustomerRoleAssignment extends DomainException
{
    public static function forRoleCode(string $roleCode): self
    {
        return new self(sprintf(
            'Role "%s" is not a valid customer-portal role.',
            $roleCode,
        ));
    }
}
