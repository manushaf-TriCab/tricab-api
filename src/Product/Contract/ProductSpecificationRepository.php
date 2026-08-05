<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Future Azure SQL: Specification* + Product(Variant)SpecificationValues.
 *
 * @phpstan-type SpecDefinition array{
 *   publicId: string,
 *   groupCode: ?string,
 *   definitionCode: string,
 *   definitionName: string,
 *   dataTypeCode: string,
 *   scopeCode: string,
 *   unit: ?string,
 *   isRequired: bool,
 *   displayOrder: int,
 *   options: list<array{publicId: string, optionCode: string, optionLabel: string, displayOrder: int}>
 * }
 * @phpstan-type SpecValue array{
 *   definitionPublicId: string,
 *   valueText: ?string,
 *   valueLongText: ?string,
 *   valueInteger: ?int,
 *   valueDecimal: ?string,
 *   valueBoolean: ?bool,
 *   valueDate: ?string,
 *   valueUrl: ?string,
 *   valueMinDecimal: ?string,
 *   valueMaxDecimal: ?string,
 *   optionPublicId: ?string,
 *   optionPublicIds: list<string>
 * }
 */
interface ProductSpecificationRepository
{
    /**
     * @return list<SpecDefinition>
     */
    public function listDefinitions(bool $activeOnly = true): array;

    /**
     * @return list<SpecValue>
     */
    public function listProductValues(ProductPublicId $productPublicId): array;

    /**
     * @return list<SpecValue>
     */
    public function listVariantValues(ProductVariantPublicId $variantPublicId): array;

    /**
     * @param list<SpecValue> $values
     */
    public function replaceProductValues(
        ProductPublicId $productPublicId,
        array $values,
        UserId $actorUserId,
    ): void;

    /**
     * @param list<SpecValue> $values
     */
    public function replaceVariantValues(
        ProductVariantPublicId $variantPublicId,
        array $values,
        UserId $actorUserId,
    ): void;
}
