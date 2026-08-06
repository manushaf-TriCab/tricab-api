<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidProductRelationshipAssignment extends RuntimeException
{
    public static function relatedProductNotFound(string $publicId): self
    {
        return new self(sprintf('Related product "%s" was not found.', $publicId));
    }

    public static function relatedProductSoftDeleted(string $publicId): self
    {
        return new self(sprintf('Related product "%s" is soft-deleted and cannot be referenced.', $publicId));
    }

    public static function for(string $message): self
    {
        return new self($message);
    }
}
