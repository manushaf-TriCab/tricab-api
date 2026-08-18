<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductClassificationNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Product classification "%s" was not found.', $publicId));
    }
}
