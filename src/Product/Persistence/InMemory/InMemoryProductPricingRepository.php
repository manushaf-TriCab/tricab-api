<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\LegacyBaseCostComparison;
use TriCab\Core\Product\Application\DTO\ProductBaseCostHistoryEntry;
use TriCab\Core\Product\Application\DTO\ProductPricingMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\Exception\InvalidPricingUnit;
use TriCab\Core\Product\Application\Exception\OverlappingBaseCostRecord;
use TriCab\Core\Product\Application\Exception\ProductPricingConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\PricingUnitDefaults;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Support\PricingRecordStatus;

final class InMemoryProductPricingRepository implements ProductPricingRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $items = [];
        foreach ($this->rowsForProduct($productPublicId->toString()) as $row) {
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function getOverview(ProductPublicId $productPublicId): ProductPricingOverview
    {
        $matrix = $this->listMatrixByProduct($productPublicId);
        $withPricing = 0;
        $missing = 0;
        $current = 0;
        $scheduled = 0;
        $expired = 0;
        $inactive = 0;
        foreach ($matrix as $row) {
            if ($row->status === PricingRecordStatus::MISSING) {
                ++$missing;
                continue;
            }
            ++$withPricing;
            match ($row->status) {
                PricingRecordStatus::CURRENT => ++$current,
                PricingRecordStatus::SCHEDULED => ++$scheduled,
                PricingRecordStatus::EXPIRED => ++$expired,
                PricingRecordStatus::INACTIVE => ++$inactive,
                default => null,
            };
        }

        $defaultUnit = $this->loadProductDefaultUnitContext($productPublicId);

        return new ProductPricingOverview(
            variantCount: count($matrix),
            variantsWithPricing: $withPricing,
            missingBaseCostCount: $missing,
            currentRecordCount: $current,
            scheduledRecordCount: $scheduled,
            expiredRecordCount: $expired,
            inactiveRecordCount: $inactive,
            defaultUnitOfMeasurePublicId: $defaultUnit['defaultUnitOfMeasurePublicId'],
            defaultUnitOfMeasureCode: $defaultUnit['defaultUnitOfMeasureCode'],
            defaultUnitOfMeasureSymbol: $defaultUnit['defaultUnitOfMeasureSymbol'],
            defaultUnitSource: $defaultUnit['defaultUnitSource'],
        );
    }

    public function setProductDefaultPricingUnit(
        ProductPublicId $productPublicId,
        ?string $pricingUnitPublicId,
        UserId $actorUserId,
    ): ProductPricingOverview {
        unset($actorUserId);
        if ($pricingUnitPublicId !== null && $this->store->pricingUnitByPublicId($pricingUnitPublicId) === null) {
            throw InvalidPricingUnit::notFound($pricingUnitPublicId);
        }
        $this->store->setProductPricingUnitPublicId($productPublicId->toString(), $pricingUnitPublicId);

        return $this->getOverview($productPublicId);
    }

    public function listMatrixByProduct(ProductPublicId $productPublicId): array
    {
        $variants = [];
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            $parent = $this->store->productRecordById($variant->productId());
            if ($parent === null || !$parent['product']->publicId()->equals($productPublicId)) {
                continue;
            }
            $variants[] = [
                'publicId' => $variant->publicId()->toString(),
                'variantName' => $variant->variantName(),
                'variantCode' => $variant->variantCode(),
                'sku' => $variant->sku(),
                'displayOrder' => $variant->displayOrder(),
            ];
        }
        $rows = [];
        foreach ($variants as $variant) {
            $current = $this->currentVariantPrice($productPublicId->toString(), $variant['publicId']);
            $status = $current === null ? PricingRecordStatus::MISSING : $current['status'];
            $unit = $this->resolveUnit($productPublicId->toString(), $current);
            $rows[] = new ProductPricingMatrixRow(
                variantPublicId: AzureSqlValueMapper::stringValue($variant['publicId'] ?? ''),
                variantName: AzureSqlValueMapper::stringValue($variant['variantName'] ?? ''),
                variantCode: AzureSqlValueMapper::nullableString($variant['variantCode'] ?? null),
                sku: AzureSqlValueMapper::nullableString($variant['sku'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($variant['displayOrder'] ?? 0),
                legacyItemCode: AzureSqlValueMapper::nullableString($variant['variantCode'] ?? null),
                currentPricingPublicId: $current['publicId'] ?? null,
                currencyCode: $current['currencyCode'] ?? null,
                baseCost: $current['baseCost'] ?? null,
                unitOfMeasureCode: $unit['code'],
                unitOfMeasureSymbol: $unit['symbol'],
                priceListCode: $current['priceListCode'] ?? null,
                priceSourceCode: $current['priceSourceCode'] ?? null,
                effectiveFromUtc: $current['effectiveFromUtc'] ?? null,
                effectiveToUtc: $current['effectiveToUtc'] ?? null,
                status: $status,
                isActive: (bool) ($current['isActive'] ?? false),
                rowVersion: $current['rowVersion'] ?? null,
            );
        }

        return $rows;
    }

    public function listHistory(
        ProductPublicId $productPublicId,
        ?string $variantPublicId = null,
        ?string $priceListPublicId = null,
    ): array {
        $entries = [];
        foreach ($this->rowsForProduct($productPublicId->toString()) as $row) {
            if ($variantPublicId !== null && ($row['variantPublicId'] ?? null) !== $variantPublicId) {
                continue;
            }
            if ($priceListPublicId !== null && ($row['priceListPublicId'] ?? null) !== $priceListPublicId) {
                continue;
            }
            $unit = $this->resolveUnit($productPublicId->toString(), $row);
            $entries[] = new ProductBaseCostHistoryEntry(
                publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                recordType: AzureSqlValueMapper::stringValue($row['recordType'] ?? 'product'),
                variantPublicId: AzureSqlValueMapper::nullableString($row['variantPublicId'] ?? null),
                variantName: AzureSqlValueMapper::nullableString($row['variantName'] ?? null),
                currencyCode: AzureSqlValueMapper::stringValue($row['currencyCode'] ?? ''),
                baseCost: AzureSqlValueMapper::stringValue($row['baseCost'] ?? ''),
                unitOfMeasureCode: $unit['code'],
                priceListCode: AzureSqlValueMapper::nullableString($row['priceListCode'] ?? null),
                priceSourceCode: AzureSqlValueMapper::nullableString($row['priceSourceCode'] ?? 'manual'),
                effectiveFromUtc: AzureSqlValueMapper::nullableString($row['effectiveFromUtc'] ?? null),
                effectiveToUtc: AzureSqlValueMapper::nullableString($row['effectiveToUtc'] ?? null),
                status: AzureSqlValueMapper::stringValue($row['status'] ?? PricingRecordStatus::CURRENT),
                isActive: (bool) ($row['isActive'] ?? true),
                rowVersion: AzureSqlValueMapper::nullableString($row['rowVersion'] ?? null),
            );
        }

        return $entries;
    }

    public function findByPublicId(string $publicId): ?ProductPricingSummary
    {
        foreach ($this->store->prices() as $row) {
            if (($row['publicId'] ?? '') === $publicId) {
                return $this->toSummary($row);
            }
        }

        return null;
    }

    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        unset($actorUserId);
        $baseCost = $data['baseCost'] ?? $data['unitPrice'] ?? '';
        if (($data['unitOfMeasurePublicId'] ?? null) !== null
            && $this->store->pricingUnitByPublicId((string) $data['unitOfMeasurePublicId']) === null) {
            throw InvalidPricingUnit::notFound((string) $data['unitOfMeasurePublicId']);
        }
        if (($data['pricingPublicId'] ?? null) !== null) {
            return $this->updateRow((string) $data['pricingPublicId'], $data, $baseCost);
        }

        $matchKey = $this->matchKey($productPublicId->toString(), $data);
        if (($data['createRevision'] ?? false) === true) {
            $matchKey .= '|' . $this->store->newPublicId();
        }
        if (($data['createRevision'] ?? false) !== true) {
            foreach ($this->store->allPrices() as $existing) {
                if (($existing['matchKey'] ?? '') === $matchKey && ($existing['isActive'] ?? true)) {
                    throw OverlappingBaseCostRecord::forContext(
                        ($data['variantPublicId'] ?? null) !== null ? 'this variant' : 'this product',
                    );
                }
            }
        }

        $row = [
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'matchKey' => $matchKey,
            'recordType' => ($data['variantPublicId'] ?? null) !== null ? 'variant' : 'product',
            'variantPublicId' => $data['variantPublicId'] ?? null,
            'variantName' => null,
            'variantCode' => null,
            'currencyCode' => $data['currencyCode'],
            'baseCost' => $baseCost,
            'minimumQuantity' => $data['minimumQuantity'],
            'priceListCode' => $data['priceListPublicId'] ?? null,
            'priceListPublicId' => $data['priceListPublicId'] ?? null,
            'unitOfMeasurePublicId' => $data['unitOfMeasurePublicId'] ?? null,
            'priceSourceCode' => $data['priceSourceCode'] ?? 'manual',
            'effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            'effectiveToUtc' => $data['effectiveToUtc'] ?? null,
            'isActive' => true,
            'rowVersion' => RowVersionToken::fromBinary(random_bytes(8))->toBase64(),
            'status' => PricingRecordStatus::resolve(true, $data['effectiveFromUtc'] ?? null, $data['effectiveToUtc'] ?? null),
        ];
        $this->store->upsertPrice($row);

        return $this->toSummary($row);
    }

    public function setActive(
        string $pricingPublicId,
        bool $isActive,
        UserId $actorUserId,
        ?string $expectedRowVersion = null,
    ): ProductPricingSummary {
        unset($actorUserId);
        foreach ($this->store->prices() as $row) {
            if (($row['publicId'] ?? '') !== $pricingPublicId) {
                continue;
            }
            if ($expectedRowVersion !== null && ($row['rowVersion'] ?? null) !== $expectedRowVersion) {
                throw ProductPricingConcurrencyConflict::forPublicId($pricingPublicId);
            }
            $row['isActive'] = $isActive;
            $row['status'] = PricingRecordStatus::resolve($isActive, $row['effectiveFromUtc'] ?? null, $row['effectiveToUtc'] ?? null);
            $row['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
            $this->store->upsertPrice($row);

            return $this->toSummary($row);
        }

        throw ProductPricingConcurrencyConflict::forPublicId($pricingPublicId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForProduct(string $productPublicId): array
    {
        $rows = [];
        foreach ($this->store->prices() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function matchKey(string $productPublicId, array $data): string
    {
        return implode('|', [
            $productPublicId,
            $data['variantPublicId'] ?? '',
            $data['currencyCode'],
            $data['priceListPublicId'] ?? '',
            $data['regionPublicId'] ?? '',
            $data['marketPublicId'] ?? '',
            $data['minimumQuantity'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateRow(string $publicId, array $data, string $baseCost): ProductPricingSummary
    {
        foreach ($this->store->prices() as $row) {
            if (($row['publicId'] ?? '') !== $publicId) {
                continue;
            }
            if (($data['expectedRowVersion'] ?? null) !== null && ($row['rowVersion'] ?? null) !== $data['expectedRowVersion']) {
                throw ProductPricingConcurrencyConflict::forPublicId($publicId);
            }
            $row['baseCost'] = $baseCost;
            $row['currencyCode'] = $data['currencyCode'];
            $row['minimumQuantity'] = $data['minimumQuantity'];
            $row['effectiveFromUtc'] = $data['effectiveFromUtc'] ?? null;
            $row['effectiveToUtc'] = $data['effectiveToUtc'] ?? null;
            $row['priceSourceCode'] = $data['priceSourceCode'] ?? 'manual';
            if (array_key_exists('unitOfMeasurePublicId', $data)) {
                $unitPublicId = $data['unitOfMeasurePublicId'];
                if ($unitPublicId !== null && $this->store->pricingUnitByPublicId($unitPublicId) === null) {
                    throw InvalidPricingUnit::notFound($unitPublicId);
                }
                $row['unitOfMeasurePublicId'] = $unitPublicId;
            }
            $row['rowVersion'] = RowVersionToken::fromBinary(random_bytes(8))->toBase64();
            $row['status'] = PricingRecordStatus::resolve((bool) ($row['isActive'] ?? true), $row['effectiveFromUtc'], $row['effectiveToUtc']);
            $this->store->upsertPrice($row);

            return $this->toSummary($row);
        }

        throw ProductPricingConcurrencyConflict::forPublicId($publicId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentVariantPrice(string $productPublicId, string $variantPublicId): ?array
    {
        $latest = null;
        foreach ($this->rowsForProduct($productPublicId) as $row) {
            if (($row['variantPublicId'] ?? null) !== $variantPublicId || !($row['isActive'] ?? true)) {
                continue;
            }
            $latest = $row;
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductPricingSummary
    {
        $unit = $this->resolveUnit((string) ($row['productPublicId'] ?? ''), $row);

        return new ProductPricingSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            recordType: AzureSqlValueMapper::stringValue($row['recordType'] ?? 'product'),
            variantPublicId: AzureSqlValueMapper::nullableString($row['variantPublicId'] ?? null),
            variantName: AzureSqlValueMapper::nullableString($row['variantName'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['variantCode'] ?? null),
            currencyCode: AzureSqlValueMapper::stringValue($row['currencyCode'] ?? ''),
            baseCost: AzureSqlValueMapper::stringValue($row['baseCost'] ?? $row['unitPrice'] ?? ''),
            minimumQuantity: AzureSqlValueMapper::stringValue($row['minimumQuantity'] ?? '1'),
            priceListCode: AzureSqlValueMapper::nullableString($row['priceListCode'] ?? null),
            priceListPublicId: AzureSqlValueMapper::nullableString($row['priceListPublicId'] ?? null),
            regionPublicId: AzureSqlValueMapper::nullableString($row['regionPublicId'] ?? null),
            marketPublicId: AzureSqlValueMapper::nullableString($row['marketPublicId'] ?? null),
            unitOfMeasureCode: $unit['code'],
            unitOfMeasureSymbol: $unit['symbol'],
            unitOfMeasurePublicId: $unit['publicId'],
            priceSourceCode: AzureSqlValueMapper::nullableString($row['priceSourceCode'] ?? 'manual'),
            effectiveFromUtc: AzureSqlValueMapper::nullableString($row['effectiveFromUtc'] ?? null),
            effectiveToUtc: AzureSqlValueMapper::nullableString($row['effectiveToUtc'] ?? null),
            status: AzureSqlValueMapper::stringValue($row['status'] ?? PricingRecordStatus::CURRENT),
            isActive: (bool) ($row['isActive'] ?? true),
            rowVersion: AzureSqlValueMapper::nullableString($row['rowVersion'] ?? null),
            legacyItemCode: AzureSqlValueMapper::nullableString($row['legacyItemCode'] ?? null),
            erpSourceReference: null,
            lastSourceUpdatedAtUtc: null,
        );
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array{publicId: ?string, code: string, symbol: string}
     */
    private function resolveUnit(string $productPublicId, ?array $row): array
    {
        $unitPublicId = $row['unitOfMeasurePublicId'] ?? null;
        if (is_string($unitPublicId) && $unitPublicId !== '') {
            $unit = $this->store->pricingUnitByPublicId($unitPublicId);
            if ($unit !== null) {
                return ['publicId' => $unit['publicId'], 'code' => $unit['code'], 'symbol' => $unit['symbol']];
            }
        }

        $productUnitPublicId = $this->store->productPricingUnitPublicId($productPublicId);
        if ($productUnitPublicId !== null) {
            $unit = $this->store->pricingUnitByPublicId($productUnitPublicId);
            if ($unit !== null) {
                return ['publicId' => $unit['publicId'], 'code' => $unit['code'], 'symbol' => $unit['symbol']];
            }
        }

        return [
            'publicId' => null,
            'code' => PricingUnitDefaults::CODE,
            'symbol' => PricingUnitDefaults::SYMBOL,
        ];
    }

    /**
     * @return array{
     *   defaultUnitOfMeasurePublicId: ?string,
     *   defaultUnitOfMeasureCode: string,
     *   defaultUnitOfMeasureSymbol: string,
     *   defaultUnitSource: 'product'|'system-default'
     * }
     */
    private function loadProductDefaultUnitContext(ProductPublicId $productPublicId): array
    {
        $publicId = $this->store->productPricingUnitPublicId($productPublicId->toString());
        if ($publicId === null) {
            return [
                'defaultUnitOfMeasurePublicId' => null,
                'defaultUnitOfMeasureCode' => PricingUnitDefaults::CODE,
                'defaultUnitOfMeasureSymbol' => PricingUnitDefaults::SYMBOL,
                'defaultUnitSource' => 'system-default',
            ];
        }

        $unit = $this->store->pricingUnitByPublicId($publicId);
        if ($unit === null) {
            return [
                'defaultUnitOfMeasurePublicId' => null,
                'defaultUnitOfMeasureCode' => PricingUnitDefaults::CODE,
                'defaultUnitOfMeasureSymbol' => PricingUnitDefaults::SYMBOL,
                'defaultUnitSource' => 'system-default',
            ];
        }

        return [
            'defaultUnitOfMeasurePublicId' => $unit['publicId'],
            'defaultUnitOfMeasureCode' => $unit['code'],
            'defaultUnitOfMeasureSymbol' => $unit['symbol'],
            'defaultUnitSource' => 'product',
        ];
    }
}
