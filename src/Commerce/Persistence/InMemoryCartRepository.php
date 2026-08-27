<?php

declare(strict_types=1);

namespace TriCab\Api\Commerce\Persistence;

use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Commerce\Domain\Exception\CartConcurrencyConflict;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLineId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * In-memory cart store for unit tests and memory-mode DI.
 */
final class InMemoryCartRepository implements CartRepository
{
    private int $nextCartId = 1;
    private int $nextLineId = 1;
    private int $rowVersionCounter = 1;

    /** @var array<int, array<string, mixed>> */
    private array $carts = [];

    /** @var array<int, array<string, mixed>> */
    private array $lines = [];

    /** @var array<string, array{id: int, code: ?string, displayName: ?string}> */
    private array $variantsByPublicId = [];

    /** @var array<int, string> */
    private array $variantPublicIdById = [];

    public function seedVariant(
        string $variantPublicId,
        ?string $code = null,
        ?string $displayName = null,
        ?int $productVariantId = null,
    ): int {
        $id = $productVariantId ?? (count($this->variantsByPublicId) + 1);
        $this->variantsByPublicId[$variantPublicId] = [
            'id' => $id,
            'code' => $code,
            'displayName' => $displayName,
        ];
        $this->variantPublicIdById[$id] = $variantPublicId;

        return $id;
    }

    public function findOpenCartForActor(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
    ): ?Cart {
        $currency = strtoupper($currencyCode);
        foreach ($this->carts as $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ($row['status'] !== CartStatus::OPEN) {
                continue;
            }
            if ((int) $row['ownerUserId'] !== $ownerUserId) {
                continue;
            }
            if ($row['effectiveCustomerId'] !== $effectiveCustomerId) {
                continue;
            }
            if ($row['pricingMode'] !== $pricingMode->toString()) {
                continue;
            }
            if (strtoupper((string) $row['currencyCode']) !== $currency) {
                continue;
            }

            return $this->hydrateCart($row);
        }

        return null;
    }

    public function getByPublicId(CartPublicId $cartPublicId, int $ownerUserId): ?Cart
    {
        foreach ($this->carts as $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ($row['publicId'] !== $cartPublicId->toString()) {
                continue;
            }
            if ((int) $row['ownerUserId'] !== $ownerUserId) {
                return null;
            }

            return $this->hydrateCart($row);
        }

        return null;
    }

    public function createCart(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
        ?int $actorUserId = null,
    ): Cart {
        unset($actorUserId);
        $id = $this->nextCartId++;
        $publicId = CartPublicId::generate()->toString();
        $rowVersion = $this->nextRowVersion();
        $this->carts[$id] = [
            'id' => $id,
            'publicId' => $publicId,
            'ownerUserId' => $ownerUserId,
            'effectiveCustomerId' => $effectiveCustomerId,
            'pricingMode' => $pricingMode->toString(),
            'currencyCode' => strtoupper($currencyCode),
            'status' => CartStatus::OPEN,
            'pricingFingerprint' => null,
            'rowVersion' => $rowVersion->toBase64(),
            'deletedAtUtc' => null,
        ];

        return $this->hydrateCart($this->carts[$id]);
    }

    public function listLines(CartId $cartId): array
    {
        $out = [];
        foreach ($this->lines as $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ((int) $row['cartId'] !== $cartId->toInt()) {
                continue;
            }
            $out[] = $this->hydrateLine($row);
        }

        return $out;
    }

    public function addOrMergeLine(CartId $cartId, CartLine $line, ?int $actorUserId = null): CartLine
    {
        unset($actorUserId);
        foreach ($this->lines as $id => $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ((int) $row['cartId'] !== $cartId->toInt()) {
                continue;
            }
            $existing = $this->hydrateLine($row);
            if ($existing->matchesMergeKey(
                $line->productVariantId(),
                $line->purchaseOptionKey(),
                $line->length(),
                $line->pricingStatus(),
                $line->pricingFingerprint(),
            )) {
                $newQty = $existing->quantity() + $line->quantity();
                $lineTotal = $line->unitSellPrice() !== null && $line->length() !== null
                    ? bcmul($line->unitSellPrice(), bcmul((string) $newQty, $line->length(), 6), 2)
                    : null;
                $this->lines[$id]['quantity'] = $newQty;
                $this->lines[$id]['lineTotal'] = $lineTotal;
                $this->lines[$id]['unitSellPrice'] = $line->unitSellPrice();
                $this->lines[$id]['pricingStatus'] = $line->pricingStatus();
                $this->lines[$id]['marginSource'] = $line->marginSource();
                $this->lines[$id]['pricingFingerprint'] = $line->pricingFingerprint();
                $this->lines[$id]['resolvedAtUtc'] = $line->resolvedAtUtc();
                $this->lines[$id]['availability'] = $line->availability();
                $this->lines[$id]['uomCode'] = $line->uomCode();
                $this->lines[$id]['rowVersion'] = $this->nextRowVersion()->toBase64();

                return $this->hydrateLine($this->lines[$id]);
            }
        }

        $id = $this->nextLineId++;
        $this->lines[$id] = [
            'id' => $id,
            'publicId' => $line->publicId()->toString(),
            'cartId' => $cartId->toInt(),
            'productVariantId' => $line->productVariantId(),
            'variantPublicId' => $line->variantPublicId(),
            'purchaseOptionKey' => $line->purchaseOptionKey(),
            'quantity' => $line->quantity(),
            'length' => $line->length(),
            'uomCode' => $line->uomCode(),
            'availability' => $line->availability(),
            'pricingStatus' => $line->pricingStatus(),
            'unitSellPrice' => $line->unitSellPrice(),
            'lineTotal' => $line->lineTotal(),
            'currencyCode' => $line->currencyCode(),
            'marginSource' => $line->marginSource(),
            'pricingFingerprint' => $line->pricingFingerprint(),
            'resolvedAtUtc' => $line->resolvedAtUtc(),
            'rowVersion' => $this->nextRowVersion()->toBase64(),
            'variantCode' => $line->variantCode(),
            'displayName' => $line->displayName(),
            'deletedAtUtc' => null,
        ];

        return $this->hydrateLine($this->lines[$id]);
    }

    public function updateLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        CartLine $line,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): CartLine {
        unset($actorUserId);
        foreach ($this->lines as $id => $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ((int) $row['cartId'] !== $cartId->toInt()) {
                continue;
            }
            if ($row['publicId'] !== $linePublicId->toString()) {
                continue;
            }
            if ($row['rowVersion'] !== $expectedRowVersion->toBase64()) {
                throw CartConcurrencyConflict::forLine();
            }

            $this->lines[$id]['quantity'] = $line->quantity();
            $this->lines[$id]['length'] = $line->length();
            $this->lines[$id]['uomCode'] = $line->uomCode();
            $this->lines[$id]['availability'] = $line->availability();
            $this->lines[$id]['pricingStatus'] = $line->pricingStatus();
            $this->lines[$id]['unitSellPrice'] = $line->unitSellPrice();
            $this->lines[$id]['lineTotal'] = $line->lineTotal();
            $this->lines[$id]['currencyCode'] = $line->currencyCode();
            $this->lines[$id]['marginSource'] = $line->marginSource();
            $this->lines[$id]['pricingFingerprint'] = $line->pricingFingerprint();
            $this->lines[$id]['resolvedAtUtc'] = $line->resolvedAtUtc();
            $this->lines[$id]['rowVersion'] = $this->nextRowVersion()->toBase64();

            return $this->hydrateLine($this->lines[$id]);
        }

        throw CartNotFound::forLinePublicId($linePublicId->toString());
    }

    public function softRemoveLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): void {
        unset($actorUserId);
        foreach ($this->lines as $id => $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ((int) $row['cartId'] !== $cartId->toInt()) {
                continue;
            }
            if ($row['publicId'] !== $linePublicId->toString()) {
                continue;
            }
            if ($row['rowVersion'] !== $expectedRowVersion->toBase64()) {
                throw CartConcurrencyConflict::forLine();
            }
            $this->lines[$id]['deletedAtUtc'] = gmdate('c');
            $this->lines[$id]['rowVersion'] = $this->nextRowVersion()->toBase64();

            return;
        }

        throw CartNotFound::forLinePublicId($linePublicId->toString());
    }

    public function touchCartUpdatedAt(CartId $cartId, ?int $actorUserId = null): void
    {
        unset($actorUserId);
        if (!isset($this->carts[$cartId->toInt()])) {
            return;
        }
        $this->carts[$cartId->toInt()]['rowVersion'] = $this->nextRowVersion()->toBase64();
    }

    public function markSubmitted(CartId $cartId, ?int $actorUserId = null): Cart
    {
        unset($actorUserId);
        $this->carts[$cartId->toInt()]['status'] = CartStatus::SUBMITTED;
        $this->carts[$cartId->toInt()]['rowVersion'] = $this->nextRowVersion()->toBase64();

        return $this->hydrateCart($this->carts[$cartId->toInt()]);
    }

    public function abandon(CartId $cartId, ?int $actorUserId = null): Cart
    {
        unset($actorUserId);
        $this->carts[$cartId->toInt()]['status'] = CartStatus::ABANDONED;
        $this->carts[$cartId->toInt()]['rowVersion'] = $this->nextRowVersion()->toBase64();

        return $this->hydrateCart($this->carts[$cartId->toInt()]);
    }

    public function resolveProductVariantId(string $variantPublicId): ?int
    {
        return $this->variantsByPublicId[$variantPublicId]['id'] ?? null;
    }

    public function resolveVariantDisplay(int $productVariantId): ?array
    {
        $publicId = $this->variantPublicIdById[$productVariantId] ?? null;
        if ($publicId === null) {
            return null;
        }
        $meta = $this->variantsByPublicId[$publicId];

        return [
            'publicId' => $publicId,
            'code' => $meta['code'],
            'displayName' => $meta['displayName'],
        ];
    }

    public function findLineByPublicId(CartLinePublicId $linePublicId, int $ownerUserId): ?CartLine
    {
        foreach ($this->lines as $row) {
            if ($row['deletedAtUtc'] !== null) {
                continue;
            }
            if ($row['publicId'] !== $linePublicId->toString()) {
                continue;
            }
            $cart = $this->carts[(int) $row['cartId']] ?? null;
            if ($cart === null || (int) $cart['ownerUserId'] !== $ownerUserId) {
                return null;
            }

            return $this->hydrateLine($row);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCart(array $row): Cart
    {
        $cartId = CartId::fromInt((int) $row['id']);

        return new Cart(
            id: $cartId,
            publicId: CartPublicId::fromString((string) $row['publicId']),
            ownerUserId: (int) $row['ownerUserId'],
            effectiveCustomerId: $row['effectiveCustomerId'] !== null ? (int) $row['effectiveCustomerId'] : null,
            pricingMode: CartPricingMode::fromString((string) $row['pricingMode']),
            currencyCode: (string) $row['currencyCode'],
            status: CartStatus::fromString((string) $row['status']),
            pricingFingerprint: $row['pricingFingerprint'] !== null ? (string) $row['pricingFingerprint'] : null,
            rowVersion: RowVersionToken::fromBase64((string) $row['rowVersion']),
            lines: $this->listLines($cartId),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLine(array $row): CartLine
    {
        return new CartLine(
            id: CartLineId::fromInt((int) $row['id']),
            publicId: CartLinePublicId::fromString((string) $row['publicId']),
            cartId: CartId::fromInt((int) $row['cartId']),
            productVariantId: (int) $row['productVariantId'],
            variantPublicId: (string) $row['variantPublicId'],
            purchaseOptionKey: $row['purchaseOptionKey'] !== null ? (string) $row['purchaseOptionKey'] : null,
            quantity: (int) $row['quantity'],
            length: $row['length'] !== null ? (string) $row['length'] : null,
            uomCode: $row['uomCode'] !== null ? (string) $row['uomCode'] : null,
            availability: $row['availability'] !== null ? (string) $row['availability'] : null,
            pricingStatus: (string) $row['pricingStatus'],
            unitSellPrice: $row['unitSellPrice'] !== null ? (string) $row['unitSellPrice'] : null,
            lineTotal: $row['lineTotal'] !== null ? (string) $row['lineTotal'] : null,
            currencyCode: (string) $row['currencyCode'],
            marginSource: $row['marginSource'] !== null ? (string) $row['marginSource'] : null,
            pricingFingerprint: $row['pricingFingerprint'] !== null ? (string) $row['pricingFingerprint'] : null,
            resolvedAtUtc: $row['resolvedAtUtc'] !== null ? (string) $row['resolvedAtUtc'] : null,
            rowVersion: RowVersionToken::fromBase64((string) $row['rowVersion']),
            variantCode: $row['variantCode'] !== null ? (string) $row['variantCode'] : null,
            displayName: $row['displayName'] !== null ? (string) $row['displayName'] : null,
        );
    }

    private function nextRowVersion(): RowVersionToken
    {
        $n = $this->rowVersionCounter++;
        $binary = pack('J', $n);

        return RowVersionToken::fromBinary($binary);
    }
}
