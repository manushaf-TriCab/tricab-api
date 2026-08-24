<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidPricingUnit extends RuntimeException
{
    public static function notFound(string $publicId): self
    {
        return new self(sprintf('Pricing unit "%s" was not found or is inactive.', $publicId));
    }
}
