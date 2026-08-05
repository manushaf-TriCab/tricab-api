<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use RuntimeException;

final class ProductPersistenceException extends RuntimeException
{
    public static function productNotFound(string $publicId): self
    {
        return new self(sprintf('Product "%s" was not found.', $publicId));
    }

    public static function variantNotFound(string $publicId): self
    {
        return new self(sprintf('Product variant "%s" was not found.', $publicId));
    }

    public static function insertFailed(string $entity): self
    {
        return new self(sprintf('%s insert did not return an identity.', $entity));
    }

    public static function statusNotFound(string $statusCode): self
    {
        return new self(sprintf('Product status code "%s" was not found.', $statusCode));
    }

    public static function lifecycleNotFound(string $lifecycleCode): self
    {
        return new self(sprintf('Product lifecycle status code "%s" was not found.', $lifecycleCode));
    }

    public static function parentProductInvalid(string $message): self
    {
        return new self($message);
    }

    public static function expectedRowVersionRequired(): self
    {
        return new self('Expected RowVersion is required when updating an existing product aggregate.');
    }

    public static function lookupNotFound(string $entity, string $code): self
    {
        return new self(sprintf('%s lookup "%s" was not found.', $entity, $code));
    }

    public static function referenceNotFound(string $entity, string $publicId): self
    {
        return new self(sprintf('%s "%s" was not found.', $entity, $publicId));
    }

    public static function operationFailed(string $message): self
    {
        return new self($message);
    }

    public static function invalidRelationship(string $message): self
    {
        return new self($message);
    }
}
