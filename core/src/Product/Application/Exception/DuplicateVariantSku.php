<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class DuplicateVariantSku extends RuntimeException
{
    public static function forSku(string $sku): self
    {
        return new self(sprintf('Variant SKU "%s" is already in use.', $sku));
    }
}
