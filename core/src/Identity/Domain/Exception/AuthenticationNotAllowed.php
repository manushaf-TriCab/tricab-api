<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class AuthenticationNotAllowed extends DomainException
{
    public static function forStatus(UserStatus $status): self
    {
        return new self(sprintf(
            'Authentication is not allowed for user status "%s".',
            $status->toString(),
        ));
    }
}
