<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Exception;

use DomainException;

final class InvalidCartValue extends DomainException
{
    public static function for(string $type, string $value): self
    {
        return new self(sprintf('Invalid %s value: "%s".', $type, $value));
    }
}
