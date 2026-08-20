<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use DomainException;

final class OverlappingBaseCostRecord extends DomainException
{
    public static function forContext(string $contextLabel): self
    {
        return new self(sprintf(
            'An authoritative base-cost record already exists for %s in the requested effective period.',
            $contextLabel,
        ));
    }
}
