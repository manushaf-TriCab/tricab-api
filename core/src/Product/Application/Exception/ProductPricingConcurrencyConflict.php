<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductPricingConcurrencyConflict extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf(
            'Pricing changed since it was loaded. Reload the record and try again. (id: %s)',
            $publicId,
        ));
    }
}
