<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Exception;

use DomainException;

final class CartNotFound extends DomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Cart not found: "%s".', $publicId));
    }

    public static function forLinePublicId(string $publicId): self
    {
        return new self(sprintf('Cart line not found: "%s".', $publicId));
    }
}
