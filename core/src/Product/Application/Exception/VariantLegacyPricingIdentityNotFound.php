<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

final class VariantLegacyPricingIdentityNotFound extends \DomainException
{
    public static function forVariantPublicId(string $publicId): self
    {
        return new self(sprintf('No explicit legacy pricing identity mapping for variant %s.', $publicId));
    }
}
