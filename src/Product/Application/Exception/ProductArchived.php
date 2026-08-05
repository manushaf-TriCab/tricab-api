<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductArchived extends RuntimeException
{
    public static function forOperation(string $publicId, string $operation): self
    {
        return new self(sprintf(
            'Product "%s" is archived; operation "%s" is not allowed.',
            $publicId,
            $operation,
        ));
    }
}
