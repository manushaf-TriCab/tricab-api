<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No product found for public id "%s".', $publicId));
    }

    public static function forSlug(string $slug): self
    {
        return new self(sprintf('No product found for slug "%s".', $slug));
    }

    public static function forProductCode(string $code): self
    {
        return new self(sprintf('No product found for product code "%s".', $code));
    }
}
