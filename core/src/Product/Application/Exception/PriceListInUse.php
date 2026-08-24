<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class PriceListInUse extends RuntimeException
{
    public static function cannotArchive(string $publicId, int $totalReferences): self
    {
        return new self(sprintf(
            'Price list "%s" is referenced by %d pricing record(s) and cannot be archived.',
            $publicId,
            $totalReferences,
        ));
    }
}
