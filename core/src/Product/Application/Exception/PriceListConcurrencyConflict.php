<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class PriceListConcurrencyConflict extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Price list "%s" was modified by another user. Refresh and retry.', $publicId));
    }
}
