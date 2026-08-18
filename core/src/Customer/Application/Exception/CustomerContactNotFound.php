<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Exception;

use RuntimeException;

final class CustomerContactNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No customer contact found for public id "%s".', $publicId));
    }
}
