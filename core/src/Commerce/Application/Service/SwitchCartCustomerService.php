<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Staff customer switch uses a different EffectiveCustomerId key → separate OPEN cart (isolation A).
 * This service is intentionally thin: GetCart / AddCartItem already isolate by actor context.
 */
final class SwitchCartCustomerService
{
    public function __construct(
        private readonly GetCartService $getCart,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(AuthenticatedUser $user, string $pricingContextToken): array
    {
        $cart = $this->getCart->execute($user, $pricingContextToken);

        return [
            'accepted' => true,
            'isolation' => 'SEPARATE_OPEN_CART_PER_EFFECTIVE_CUSTOMER',
            'cart' => $cart,
        ];
    }
}
