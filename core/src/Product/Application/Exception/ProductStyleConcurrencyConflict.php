<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductStyleConcurrencyConflict extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf(
            'Product style "%s" was changed by another user. Reload the latest version before saving again.',
            $publicId,
        ));
    }
}
