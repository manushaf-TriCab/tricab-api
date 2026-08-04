<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Exception;

use DomainException;

final class DuplicateContactUserLink extends DomainException
{
    public static function forContactAndUser(string $contactPublicId, string $userPublicId): self
    {
        return new self(sprintf(
            'User "%s" already has an active link to contact "%s".',
            $userPublicId,
            $contactPublicId,
        ));
    }

    public static function primaryAlreadyAssigned(string $contactPublicId): self
    {
        return new self(sprintf(
            'Contact "%s" already has an active primary portal user.',
            $contactPublicId,
        ));
    }
}
