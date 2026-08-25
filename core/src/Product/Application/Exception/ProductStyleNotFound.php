<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class ProductStyleNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Product style "%s" was not found.', $publicId));
    }

    public static function forStyleCode(string $styleCode): self
    {
        return new self(sprintf('Product style code "%s" was not found.', $styleCode));
    }
}
