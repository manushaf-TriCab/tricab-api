<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use DomainException;

final class CatalogueInUse extends DomainException
{
    public static function cannotArchive(string $summary): self
    {
        return new self(sprintf('Cannot archive this catalogue category while it still has children or product placements. %s', $summary));
    }
}
