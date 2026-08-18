<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidProductClassification extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No active product classification found for public id "%s".', $publicId));
    }

    public static function invalidHierarchy(string $message): self
    {
        return new self($message);
    }
}
