<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Exception;

use RuntimeException;

final class CustomerNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No customer found for public id "%s".', $publicId));
    }
}
