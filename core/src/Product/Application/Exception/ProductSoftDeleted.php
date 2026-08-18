<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductSoftDeleted extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Product "%s" is soft-deleted. Restore it first.', $publicId));
    }
}
