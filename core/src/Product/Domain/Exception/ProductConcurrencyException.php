<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

/**
 * Raised when an optimistic concurrency (RowVersion) check fails.
 * Mapped to an API conflict response in a later phase.
 */
final class ProductConcurrencyException extends DomainException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf(
            'Product "%s" was modified by another user. Reload and retry.',
            $publicId,
        ));
    }
}
