<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;

/**
 * Thin application-layer wrapper around the domain ProductConcurrencyException,
 * used so HTTP error mappers only need to translate one exception type per
 * bounded context entry point while keeping the user-safe message stable.
 */
final class ProductConcurrencyConflict extends RuntimeException
{
    public static function fromDomainException(ProductConcurrencyException $exception): self
    {
        return new self($exception->getMessage(), 0, $exception);
    }

    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf(
            'This record was modified by another user. Reload and try again. (id: %s)',
            $publicId,
        ));
    }
}
