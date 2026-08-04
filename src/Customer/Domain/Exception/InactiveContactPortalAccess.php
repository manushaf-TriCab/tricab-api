<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Exception;

use DomainException;

final class InactiveContactPortalAccess extends DomainException
{
    public static function forContact(string $contactPublicId): self
    {
        return new self(sprintf(
            'Contact "%s" is inactive and cannot receive portal access.',
            $contactPublicId,
        ));
    }
}
