<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

interface CustomerPricingContextResolver
{
    public function fromAuthenticatedUser(?AuthenticatedUser $user): CustomerPricingContext;
}
