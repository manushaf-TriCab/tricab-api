<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Contract;

use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Transaction-friendly cart persistence. Callers own ApplicationTransaction boundaries.
 */
interface CartRepository
{
    public function findOpenCartForActor(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
    ): ?Cart;

    public function getByPublicId(CartPublicId $cartPublicId, int $ownerUserId): ?Cart;

    public function createCart(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
        ?int $actorUserId = null,
    ): Cart;

    /**
     * @return list<CartLine>
     */
    public function listLines(CartId $cartId): array;

    public function addOrMergeLine(CartId $cartId, CartLine $line, ?int $actorUserId = null): CartLine;

    public function updateLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        CartLine $line,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): CartLine;

    public function softRemoveLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): void;

    public function touchCartUpdatedAt(CartId $cartId, ?int $actorUserId = null): void;

    public function markSubmitted(CartId $cartId, ?int $actorUserId = null): Cart;

    public function abandon(CartId $cartId, ?int $actorUserId = null): Cart;

    public function resolveProductVariantId(string $variantPublicId): ?int;

    /**
     * @return array{publicId: string, code: ?string, displayName: ?string}|null
     */
    public function resolveVariantDisplay(int $productVariantId): ?array;

    public function findLineByPublicId(CartLinePublicId $linePublicId, int $ownerUserId): ?CartLine;
}
