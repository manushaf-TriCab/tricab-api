<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductPricingRepository implements ProductPricingRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $items = [];
        foreach ($this->store->prices() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString()) {
                continue;
            }
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        $matchKey = implode('|', [
            $productPublicId->toString(),
            $data['variantPublicId'] ?? '',
            $data['currencyCode'],
            $data['priceListPublicId'] ?? '',
            $data['regionPublicId'] ?? '',
            $data['marketPublicId'] ?? '',
            $data['minimumQuantity'],
        ]);

        $publicId = $this->store->newPublicId();
        foreach ($this->store->prices() as $existing) {
            if (($existing['matchKey'] ?? '') === $matchKey) {
                $publicId = AzureSqlValueMapper::stringValue($existing['publicId'] ?? '');
                break;
            }
        }

        $row = [
            'publicId' => $publicId,
            'productPublicId' => $productPublicId->toString(),
            'matchKey' => $matchKey,
            'currencyCode' => $data['currencyCode'],
            'unitPrice' => $data['unitPrice'],
            'minimumQuantity' => $data['minimumQuantity'],
            'priceListCode' => $data['priceListPublicId'] ?? null,
            'effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            'effectiveToUtc' => $data['effectiveToUtc'] ?? null,
        ];
        $this->store->upsertPrice($row);

        return $this->toSummary($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductPricingSummary
    {
        return new ProductPricingSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            currencyCode: AzureSqlValueMapper::stringValue($row['currencyCode'] ?? ''),
            unitPrice: AzureSqlValueMapper::stringValue($row['unitPrice'] ?? ''),
            minimumQuantity: AzureSqlValueMapper::stringValue($row['minimumQuantity'] ?? ''),
            priceListCode: AzureSqlValueMapper::nullableString($row['priceListCode'] ?? null),
            effectiveFromUtc: AzureSqlValueMapper::nullableString($row['effectiveFromUtc'] ?? null),
            effectiveToUtc: AzureSqlValueMapper::nullableString($row['effectiveToUtc'] ?? null),
        );
    }
}
