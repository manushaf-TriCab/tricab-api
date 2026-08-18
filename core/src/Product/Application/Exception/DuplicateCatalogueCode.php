<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use DomainException;

final class DuplicateCatalogueCode extends DomainException
{
    public static function forTab(string $code): self
    {
        return new self(sprintf('Catalogue tab code "%s" is already in use.', $code));
    }

    public static function forNode(string $code): self
    {
        return new self(sprintf('Catalogue node code "%s" is already in use within this tab.', $code));
    }
}
