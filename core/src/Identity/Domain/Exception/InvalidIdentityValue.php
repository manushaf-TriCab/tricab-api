<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class InvalidIdentityValue extends DomainException
{
    public static function for(string $type, string $value): self
    {
        return new self(sprintf('Invalid %s value: "%s".', $type, $value));
    }
}
