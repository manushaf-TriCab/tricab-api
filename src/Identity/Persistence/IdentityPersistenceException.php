<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use RuntimeException;

/**
 * Thrown when identity write persistence cannot complete safely.
 */
final class IdentityPersistenceException extends RuntimeException
{
    public static function roleNotFound(string $roleCode): self
    {
        return new self(sprintf('Active role "%s" was not found.', $roleCode));
    }

    public static function statusNotFound(string $statusCode): self
    {
        return new self(sprintf('Active user status "%s" was not found.', $statusCode));
    }

    public static function insertFailed(string $entity): self
    {
        return new self(sprintf('Failed to insert %s.', $entity));
    }

    public static function updateFailed(string $entity): self
    {
        return new self(sprintf('Failed to update %s.', $entity));
    }

    public static function userNotFound(string $identifier): self
    {
        return new self(sprintf('User "%s" was not found.', $identifier));
    }

    public static function permissionNotFound(string $permissionCode): self
    {
        return new self(sprintf('Active permission "%s" was not found.', $permissionCode));
    }
}
