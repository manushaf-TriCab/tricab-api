<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class CatalogueNotFound extends RuntimeException
{
    public static function forTab(string $publicIdOrSlug): self
    {
        return new self(sprintf('Catalogue tab "%s" was not found.', $publicIdOrSlug));
    }

    public static function forNode(string $publicIdOrSlug): self
    {
        return new self(sprintf('Catalogue node "%s" was not found.', $publicIdOrSlug));
    }
}
