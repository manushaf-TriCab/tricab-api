<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use DomainException;

final class DuplicateCatalogueSlug extends DomainException
{
    public static function forTab(string $slug): self
    {
        return new self(sprintf('Catalogue tab slug "%s" is already in use.', $slug));
    }

    public static function forSibling(string $slug): self
    {
        return new self(sprintf('Catalogue slug "%s" is already used by a sibling category.', $slug));
    }
}
