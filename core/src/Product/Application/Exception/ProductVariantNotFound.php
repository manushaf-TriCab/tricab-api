<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductVariantNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No product variant found for public id "%s".', $publicId));
    }
}
