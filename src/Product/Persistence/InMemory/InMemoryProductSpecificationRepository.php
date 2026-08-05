<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Product\Persistence\ProductDecimalMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * @phpstan-import-type SpecDefinition from ProductSpecificationRepository
 * @phpstan-import-type SpecValue from ProductSpecificationRepository
 */
final class InMemoryProductSpecificationRepository implements ProductSpecificationRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listDefinitions(bool $activeOnly = true): array
    {
        $items = [];
        foreach ($this->store->specDefinitions() as $row) {
            if ($activeOnly && !($row['isActive'] ?? true)) {
                continue;
            }
            $items[] = $this->hydrateDefinition($row);
        }

        return $items;
    }

    public function listProductValues(ProductPublicId $productPublicId): array
    {
        $items = [];
        foreach ($this->store->productSpecValues() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString()) {
                continue;
            }
            $items[] = $this->hydrateValue($row);
        }

        return $items;
    }

    public function listVariantValues(ProductVariantPublicId $variantPublicId): array
    {
        $items = [];
        foreach ($this->store->variantSpecValues() as $row) {
            if (($row['variantPublicId'] ?? '') !== $variantPublicId->toString()) {
                continue;
            }
            $items[] = $this->hydrateValue($row);
        }

        return $items;
    }

    public function replaceProductValues(
        ProductPublicId $productPublicId,
        array $values,
        UserId $actorUserId,
    ): void {
        $this->store->setProductSpecValues($productPublicId->toString(), $values);
    }

    public function replaceVariantValues(
        ProductVariantPublicId $variantPublicId,
        array $values,
        UserId $actorUserId,
    ): void {
        $this->store->setVariantSpecValues($variantPublicId->toString(), $values);
    }

    /**
     * @param array<string, mixed> $row
     * @return SpecDefinition
     */
    private function hydrateDefinition(array $row): array
    {
        $options = [];
        $optionsRaw = $row['options'] ?? [];
        if (is_array($optionsRaw)) {
            foreach ($optionsRaw as $optionRow) {
                if (!is_array($optionRow)) {
                    continue;
                }
                $options[] = [
                    'publicId' => AzureSqlValueMapper::stringValue($optionRow['publicId'] ?? ''),
                    'optionCode' => AzureSqlValueMapper::stringValue($optionRow['optionCode'] ?? ''),
                    'optionLabel' => AzureSqlValueMapper::stringValue($optionRow['optionLabel'] ?? ''),
                    'displayOrder' => AzureSqlValueMapper::intValue($optionRow['displayOrder'] ?? 0),
                ];
            }
        }

        return [
            'publicId' => AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            'groupCode' => AzureSqlValueMapper::nullableString($row['groupCode'] ?? null),
            'definitionCode' => AzureSqlValueMapper::stringValue($row['definitionCode'] ?? ''),
            'definitionName' => AzureSqlValueMapper::stringValue($row['definitionName'] ?? ''),
            'dataTypeCode' => AzureSqlValueMapper::stringValue($row['dataTypeCode'] ?? ''),
            'scopeCode' => AzureSqlValueMapper::stringValue($row['scopeCode'] ?? ''),
            'unit' => AzureSqlValueMapper::nullableString($row['unit'] ?? null),
            'isRequired' => AzureSqlValueMapper::boolValue($row['isRequired'] ?? false),
            'displayOrder' => AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            'options' => $options,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return SpecValue
     */
    private function hydrateValue(array $row): array
    {
        $optionPublicIds = [];
        $rawIds = $row['optionPublicIds'] ?? [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (is_string($id)) {
                    $optionPublicIds[] = $id;
                }
            }
        }

        $rawBoolean = $row['valueBoolean'] ?? null;

        return [
            'definitionPublicId' => AzureSqlValueMapper::stringValue($row['definitionPublicId'] ?? ''),
            'valueText' => AzureSqlValueMapper::nullableString($row['valueText'] ?? null),
            'valueLongText' => AzureSqlValueMapper::nullableString($row['valueLongText'] ?? null),
            'valueInteger' => AzureSqlValueMapper::nullableInt($row['valueInteger'] ?? null),
            'valueDecimal' => ProductDecimalMapper::toNullableString($row['valueDecimal'] ?? null),
            'valueBoolean' => $rawBoolean === null ? null : AzureSqlValueMapper::boolValue($rawBoolean),
            'valueDate' => AzureSqlValueMapper::nullableString($row['valueDate'] ?? null),
            'valueUrl' => AzureSqlValueMapper::nullableString($row['valueUrl'] ?? null),
            'valueMinDecimal' => ProductDecimalMapper::toNullableString($row['valueMinDecimal'] ?? null),
            'valueMaxDecimal' => ProductDecimalMapper::toNullableString($row['valueMaxDecimal'] ?? null),
            'optionPublicId' => AzureSqlValueMapper::nullableString($row['optionPublicId'] ?? null),
            'optionPublicIds' => $optionPublicIds,
        ];
    }
}
