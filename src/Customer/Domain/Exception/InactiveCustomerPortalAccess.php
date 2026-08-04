<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Exception;

use DomainException;

final class InactiveCustomerPortalAccess extends DomainException
{
    public static function forCustomer(string $customerPublicId): self
    {
        return new self(sprintf(
            'Customer "%s" is inactive and cannot grant portal access.',
            $customerPublicId,
        ));
    }
}
