<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

final class InvalidSpecificationValueException extends DomainException
{
    public static function for(string $message): self
    {
        return new self($message);
    }
}
