<?php

declare(strict_types=1);

namespace TriCab\Api\Customer\Persistence;

use RuntimeException;

/**
 * Thrown when customer write persistence cannot complete safely.
 */
final class CustomerPersistenceException extends RuntimeException
{
    public static function insertFailed(string $entity): self
    {
        return new self(sprintf('Failed to insert %s.', $entity));
    }

    public static function updateFailed(string $entity): self
    {
        return new self(sprintf('Failed to update %s.', $entity));
    }

    public static function customerNotFound(string $identifier): self
    {
        return new self(sprintf('Customer "%s" was not found.', $identifier));
    }

    public static function contactNotFound(string $identifier): self
    {
        return new self(sprintf('Customer contact "%s" was not found.', $identifier));
    }
}
