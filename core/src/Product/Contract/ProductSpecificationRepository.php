<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Specification master data + Product/Variant specification values.
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

    // --- Groups -----------------------------------------------------------

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<SpecificationGroupRecord>
     */
    public function listGroupsAdmin(array $filters = []): array;

    public function findGroupByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationGroupRecord;

    public function findGroupByCode(string $code): ?SpecificationGroupRecord;

    /** @param array{code: string, name: string, description?: ?string, displayOrder?: int, isActive?: bool} $data */
    public function createGroup(array $data, UserId $actorUserId): SpecificationGroupRecord;

    /** @param array{code?: string, name?: string, description?: ?string, displayOrder?: int} $data */
    public function updateGroup(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord;

    public function setGroupActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord;

    public function softDeleteGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord;

    public function restoreGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord;

    /** @param list<string> $orderedPublicIds */
    public function reorderGroups(array $orderedPublicIds, UserId $actorUserId): void;

    public function getGroupUsage(string $publicId): SpecificationUsage;

    public function groupCodeExists(string $code, ?string $excludePublicId = null): bool;

    // --- Units ------------------------------------------------------------

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<SpecificationUnitRecord>
     */
    public function listUnitsAdmin(array $filters = []): array;

    public function findUnitByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationUnitRecord;

    /** @param array{code: string, name: string, symbol: string, description?: ?string, displayOrder?: int, isActive?: bool} $data */
    public function createUnit(array $data, UserId $actorUserId): SpecificationUnitRecord;

    /** @param array{code?: string, name?: string, symbol?: string, description?: ?string, displayOrder?: int} $data */
    public function updateUnit(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord;

    public function setUnitActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord;

    public function softDeleteUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord;

    public function restoreUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord;

    /** @param list<string> $orderedPublicIds */
    public function reorderUnits(array $orderedPublicIds, UserId $actorUserId): void;

    public function getUnitUsage(string $publicId): SpecificationUsage;

    public function unitCodeExists(string $code, ?string $excludePublicId = null): bool;

    // --- Definitions ------------------------------------------------------

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, groupPublicId?: ?string, scopeCode?: ?string} $filters
     * @return list<SpecificationDefinitionRecord>
     */
    public function listDefinitionsAdmin(array $filters = []): array;

    public function findDefinitionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationDefinitionRecord;

    public function findDefinitionByCode(string $code): ?SpecificationDefinitionRecord;

    /** @param array<string, mixed> $data */
    public function createDefinition(array $data, UserId $actorUserId): SpecificationDefinitionRecord;

    /** @param array<string, mixed> $data */
    public function updateDefinition(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord;

    public function setDefinitionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord;

    public function softDeleteDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord;

    public function restoreDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord;

    /** @param list<string> $orderedPublicIds */
    public function reorderDefinitions(?string $groupPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    public function getDefinitionUsage(string $publicId): SpecificationUsage;

    public function definitionCodeExists(string $code, ?string $excludePublicId = null): bool;

    // --- Options ----------------------------------------------------------

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, definitionPublicId?: ?string} $filters
     * @return list<SpecificationOptionRecord>
     */
    public function listOptionsAdmin(array $filters = []): array;

    public function findOptionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationOptionRecord;

    public function findOptionByDefinitionAndCode(string $definitionPublicId, string $code): ?SpecificationOptionRecord;

    /** @param array{definitionPublicId: string, code: string, name: string, value?: ?string, displayOrder?: int, isActive?: bool} $data */
    public function createOption(array $data, UserId $actorUserId): SpecificationOptionRecord;

    /** @param array{code?: string, name?: string, value?: ?string, displayOrder?: int} $data */
    public function updateOption(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord;

    public function setOptionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord;

    public function softDeleteOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord;

    public function restoreOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord;

    /** @param list<string> $orderedPublicIds */
    public function reorderOptions(string $definitionPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    public function getOptionUsage(string $publicId): SpecificationUsage;

    public function optionCodeExists(string $definitionPublicId, string $code, ?string $excludePublicId = null): bool;
}
