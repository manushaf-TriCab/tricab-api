<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class SpecificationMasterConcurrencyConflict extends RuntimeException
{
    public static function for(string $level, string $publicId): self
    {
        return new self(sprintf(
            'Specification %s "%s" was changed by another user. Reload the latest version before saving again.',
            $level,
            $publicId,
        ));
    }
}
