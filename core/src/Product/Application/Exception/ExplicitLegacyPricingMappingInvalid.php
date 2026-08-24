<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

final class ExplicitLegacyPricingMappingInvalid extends \DomainException
{
    public static function forCode(string $legacyPriceCode, int $dbco): self
    {
        return new self(sprintf(
            'Explicit legacy pricing code "%s" does not exist under dbco %d.',
            $legacyPriceCode,
            $dbco,
        ));
    }
}
