<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Azure SQL repository for specification definitions and product/variant values.
 * Tables: SpecificationDefinitions, SpecificationOptions, ProductSpecificationValues,
 * ProductVariantSpecificationValues, ProductSpecificationOptionValues,
 * ProductVariantSpecificationOptionValues.
 *
 * @phpstan-import-type SpecDefinition from ProductSpecificationRepository
 * @phpstan-import-type SpecValue from ProductSpecificationRepository
 */
final class AzureSqlProductSpecificationRepository implements ProductSpecificationRepository
{
    use AzureSqlTransaction;

    private readonly AzureSqlSpecificationDefinitionAdmin $admin;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
        $this->admin = new AzureSqlSpecificationDefinitionAdmin($connection);
    }

    public function listDefinitions(bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    sg.[GroupCode],
    d.[DefinitionCode],
    d.[DefinitionName],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[Unit],
    d.[IsRequired],
    d.[DisplayOrder]
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] sg ON sg.[SpecificationGroupId] = d.[SpecificationGroupId]
WHERE d.[DeletedAtUtc] IS NULL
SQL;
        if ($activeOnly) {
            $sql .= ' AND d.[IsActive] = 1';
        }
        $sql .= ' ORDER BY d.[DisplayOrder], d.[DefinitionName]';

        $definitions = $this->connection->fetchAll($sql);
        $optionsByDefinition = $this->loadOptionsGroupedByDefinition($activeOnly);

        $result = [];
        foreach ($definitions as $definitionRow) {
            $definitionPublicId = AzureSqlValueMapper::stringValue($definitionRow['PublicId'] ?? '');
            $result[] = [
                'publicId' => $definitionPublicId,
                'groupCode' => AzureSqlValueMapper::nullableString($definitionRow['GroupCode'] ?? null),
                'definitionCode' => AzureSqlValueMapper::stringValue($definitionRow['DefinitionCode'] ?? ''),
                'definitionName' => AzureSqlValueMapper::stringValue($definitionRow['DefinitionName'] ?? ''),
                'dataTypeCode' => AzureSqlValueMapper::stringValue($definitionRow['DataTypeCode'] ?? ''),
                'scopeCode' => AzureSqlValueMapper::stringValue($definitionRow['ScopeCode'] ?? ''),
                'unit' => AzureSqlValueMapper::nullableString($definitionRow['Unit'] ?? null),
                'isRequired' => AzureSqlValueMapper::boolValue($definitionRow['IsRequired'] ?? false),
                'displayOrder' => AzureSqlValueMapper::intValue($definitionRow['DisplayOrder'] ?? 0),
                'options' => $optionsByDefinition[$definitionPublicId] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, list<array{publicId: string, optionCode: string, optionLabel: string, displayOrder: int}>>
     */
    private function loadOptionsGroupedByDefinition(bool $activeOnly): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [PublicId],
    o.[OptionCode],
    o.[OptionLabel],
    o.[DisplayOrder]
FROM [product].[SpecificationOptions] o
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
WHERE o.[DeletedAtUtc] IS NULL
  AND d.[DeletedAtUtc] IS NULL
SQL;
        if ($activeOnly) {
            $sql .= ' AND d.[IsActive] = 1';
        }
        $sql .= ' ORDER BY d.[DisplayOrder], o.[DisplayOrder], o.[OptionLabel]';

        $grouped = [];
        foreach ($this->connection->fetchAll($sql) as $row) {
            $definitionPublicId = AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? '');
            $grouped[$definitionPublicId][] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'optionCode' => AzureSqlValueMapper::stringValue($row['OptionCode'] ?? ''),
                'optionLabel' => AzureSqlValueMapper::stringValue($row['OptionLabel'] ?? ''),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            ];
        }

        return $grouped;
    }

    public function listProductValues(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    v.[ProductSpecificationValueId],
    v.[ValueText],
    v.[ValueLongText],
    v.[ValueInteger],
    CONVERT(NVARCHAR(50), v.[ValueDecimal]) AS [ValueDecimal],
    v.[ValueBoolean],
    CONVERT(VARCHAR(33), v.[ValueDate], 127) AS [ValueDate],
    v.[ValueUrl],
    CONVERT(NVARCHAR(50), v.[ValueMinDecimal]) AS [ValueMinDecimal],
    CONVERT(NVARCHAR(50), v.[ValueMaxDecimal]) AS [ValueMaxDecimal],
    CONVERT(NVARCHAR(36), so.[PublicId]) AS [OptionPublicId]
FROM [product].[ProductSpecificationValues] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = v.[SpecificationOptionId]
WHERE p.[PublicId] = :productPublicId
  AND v.[DeletedAtUtc] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return $this->hydrateValueRows($rows, false);
    }

    public function listVariantValues(ProductVariantPublicId $variantPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    v.[ProductVariantSpecificationValueId],
    v.[ValueText],
    v.[ValueLongText],
    v.[ValueInteger],
    CONVERT(NVARCHAR(50), v.[ValueDecimal]) AS [ValueDecimal],
    v.[ValueBoolean],
    CONVERT(VARCHAR(33), v.[ValueDate], 127) AS [ValueDate],
    v.[ValueUrl],
    CONVERT(NVARCHAR(50), v.[ValueMinDecimal]) AS [ValueMinDecimal],
    CONVERT(NVARCHAR(50), v.[ValueMaxDecimal]) AS [ValueMaxDecimal],
    CONVERT(NVARCHAR(36), so.[PublicId]) AS [OptionPublicId]
FROM [product].[ProductVariantSpecificationValues] v
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductVariantId] = v.[ProductVariantId]
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = v.[SpecificationOptionId]
WHERE pv.[PublicId] = :variantPublicId
  AND v.[DeletedAtUtc] IS NULL
SQL,
            [':variantPublicId' => $variantPublicId->toString()],
        );

        return $this->hydrateValueRows($rows, true);
    }

    public function replaceProductValues(
        ProductPublicId $productPublicId,
        array $values,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $values, $actorUserId): void {
                $productId = $this->requireProductId($productPublicId);
                $definitionPublicIds = array_map(
                    static fn (array $value): string => $value['definitionPublicId'],
                    $values,
                );

                $this->softDeleteProductValuesNotInSet($productId, $definitionPublicIds, $actorUserId);

                foreach ($values as $value) {
                    $this->upsertProductValue($productId, $value, $actorUserId);
                }
            },
        );
    }

    public function replaceVariantValues(
        ProductVariantPublicId $variantPublicId,
        array $values,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($variantPublicId, $values, $actorUserId): void {
                $variantId = $this->requireVariantId($variantPublicId);
                $definitionPublicIds = array_map(
                    static fn (array $value): string => $value['definitionPublicId'],
                    $values,
                );

                $this->softDeleteVariantValuesNotInSet($variantId, $definitionPublicIds, $actorUserId);

                foreach ($values as $value) {
                    $this->upsertVariantValue($variantId, $value, $actorUserId);
                }
            },
        );
    }

    /**
     * @param list<string> $definitionPublicIds
     */
    private function softDeleteProductValuesNotInSet(int $productId, array $definitionPublicIds, UserId $actorUserId): void
    {
        if ($definitionPublicIds === []) {
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET
    [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductId] = :productId
  AND [DeletedAtUtc] IS NULL
SQL,
                [
                    ':productId' => $productId,
                    ':deletedByUserId' => $actorUserId->toInt(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );

            return;
        }

        $placeholders = [];
        $params = [
            ':productId' => $productId,
            ':deletedByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
        foreach ($definitionPublicIds as $index => $publicId) {
            $key = ':def' . $index;
            $placeholders[] = $key;
            $params[$key] = $publicId;
        }

        $this->connection->execute(
            sprintf(
                <<<'SQL'
UPDATE v
SET
    v.[DeletedAtUtc] = SYSUTCDATETIME(),
    v.[DeletedByUserId] = :deletedByUserId,
    v.[UpdatedAtUtc] = SYSUTCDATETIME(),
    v.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductSpecificationValues] v
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
WHERE v.[ProductId] = :productId
  AND v.[DeletedAtUtc] IS NULL
  AND CONVERT(NVARCHAR(36), d.[PublicId]) NOT IN (%s)
SQL,
                implode(', ', $placeholders),
            ),
            $params,
        );
    }

    /**
     * @param list<string> $definitionPublicIds
     */
    private function softDeleteVariantValuesNotInSet(int $variantId, array $definitionPublicIds, UserId $actorUserId): void
    {
        if ($definitionPublicIds === []) {
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductVariantSpecificationValues]
SET
    [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductVariantId] = :variantId
  AND [DeletedAtUtc] IS NULL
SQL,
                [
                    ':variantId' => $variantId,
                    ':deletedByUserId' => $actorUserId->toInt(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );

            return;
        }

        $placeholders = [];
        $params = [
            ':variantId' => $variantId,
            ':deletedByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
        foreach ($definitionPublicIds as $index => $publicId) {
            $key = ':def' . $index;
            $placeholders[] = $key;
            $params[$key] = $publicId;
        }

        $this->connection->execute(
            sprintf(
                <<<'SQL'
UPDATE v
SET
    v.[DeletedAtUtc] = SYSUTCDATETIME(),
    v.[DeletedByUserId] = :deletedByUserId,
    v.[UpdatedAtUtc] = SYSUTCDATETIME(),
    v.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductVariantSpecificationValues] v
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
WHERE v.[ProductVariantId] = :variantId
  AND v.[DeletedAtUtc] IS NULL
  AND CONVERT(NVARCHAR(36), d.[PublicId]) NOT IN (%s)
SQL,
                implode(', ', $placeholders),
            ),
            $params,
        );
    }

    /**
     * @param SpecValue $value
     */
    private function upsertProductValue(int $productId, array $value, UserId $actorUserId): void
    {
        $definitionId = $this->requireDefinitionId($value['definitionPublicId']);
        $optionId = $this->resolveOptionId($value);

        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
ORDER BY CASE WHEN [DeletedAtUtc] IS NULL THEN 0 ELSE 1 END, [ProductSpecificationValueId]
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
            ],
        );

        $bind = $this->bindSpecValueParams($value, $optionId, $actorUserId);

        if ($existing === []) {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues] (
    [ProductId],
    [SpecificationDefinitionId],
    [ValueText],
    [ValueLongText],
    [ValueInteger],
    [ValueDecimal],
    [ValueBoolean],
    [ValueDate],
    [ValueUrl],
    [ValueMinDecimal],
    [ValueMaxDecimal],
    [SpecificationOptionId],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT INSERTED.[ProductSpecificationValueId] AS [ValueId]
VALUES (
    :productId,
    :definitionId,
    :valueText,
    :valueLongText,
    :valueInteger,
    :valueDecimal,
    :valueBoolean,
    :valueDate,
    :valueUrl,
    :valueMinDecimal,
    :valueMaxDecimal,
    :optionId,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                [
                    ':productId' => $productId,
                    ':definitionId' => $definitionId,
                    ...$bind,
                ],
            );
            $valueId = AzureSqlValueMapper::intValue($rows[0]['ValueId'] ?? 0);
        } else {
            $valueId = AzureSqlValueMapper::intValue($existing[0]['ProductSpecificationValueId'] ?? 0);
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET
    [ValueText] = :valueText,
    [ValueLongText] = :valueLongText,
    [ValueInteger] = :valueInteger,
    [ValueDecimal] = :valueDecimal,
    [ValueBoolean] = :valueBoolean,
    [ValueDate] = :valueDate,
    [ValueUrl] = :valueUrl,
    [ValueMinDecimal] = :valueMinDecimal,
    [ValueMaxDecimal] = :valueMaxDecimal,
    [SpecificationOptionId] = :optionId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId,
    [DeletedAtUtc] = NULL,
    [DeletedByUserId] = NULL
WHERE [ProductSpecificationValueId] = :valueId
SQL,
                [
                    ':valueId' => $valueId,
                    ...$this->withoutCreatedByParam($bind),
                ],
            );
        }

        $this->replaceProductOptionValues($valueId, $value, $actorUserId);
    }

    /**
     * @param SpecValue $value
     */
    private function upsertVariantValue(int $variantId, array $value, UserId $actorUserId): void
    {
        $definitionId = $this->requireDefinitionId($value['definitionPublicId']);
        $optionId = $this->resolveOptionId($value);

        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) [ProductVariantSpecificationValueId]
FROM [product].[ProductVariantSpecificationValues]
WHERE [ProductVariantId] = :variantId
  AND [SpecificationDefinitionId] = :definitionId
ORDER BY CASE WHEN [DeletedAtUtc] IS NULL THEN 0 ELSE 1 END, [ProductVariantSpecificationValueId]
SQL,
            [
                ':variantId' => $variantId,
                ':definitionId' => $definitionId,
            ],
        );

        $bind = $this->bindSpecValueParams($value, $optionId, $actorUserId);

        if ($existing === []) {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [product].[ProductVariantSpecificationValues] (
    [ProductVariantId],
    [SpecificationDefinitionId],
    [ValueText],
    [ValueLongText],
    [ValueInteger],
    [ValueDecimal],
    [ValueBoolean],
    [ValueDate],
    [ValueUrl],
    [ValueMinDecimal],
    [ValueMaxDecimal],
    [SpecificationOptionId],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT INSERTED.[ProductVariantSpecificationValueId] AS [ValueId]
VALUES (
    :variantId,
    :definitionId,
    :valueText,
    :valueLongText,
    :valueInteger,
    :valueDecimal,
    :valueBoolean,
    :valueDate,
    :valueUrl,
    :valueMinDecimal,
    :valueMaxDecimal,
    :optionId,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                [
                    ':variantId' => $variantId,
                    ':definitionId' => $definitionId,
                    ...$bind,
                ],
            );
            $valueId = AzureSqlValueMapper::intValue($rows[0]['ValueId'] ?? 0);
        } else {
            $valueId = AzureSqlValueMapper::intValue($existing[0]['ProductVariantSpecificationValueId'] ?? 0);
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductVariantSpecificationValues]
SET
    [ValueText] = :valueText,
    [ValueLongText] = :valueLongText,
    [ValueInteger] = :valueInteger,
    [ValueDecimal] = :valueDecimal,
    [ValueBoolean] = :valueBoolean,
    [ValueDate] = :valueDate,
    [ValueUrl] = :valueUrl,
    [ValueMinDecimal] = :valueMinDecimal,
    [ValueMaxDecimal] = :valueMaxDecimal,
    [SpecificationOptionId] = :optionId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId,
    [DeletedAtUtc] = NULL,
    [DeletedByUserId] = NULL
WHERE [ProductVariantSpecificationValueId] = :valueId
SQL,
                [
                    ':valueId' => $valueId,
                    ...$this->withoutCreatedByParam($bind),
                ],
            );
        }

        $this->replaceVariantOptionValues($valueId, $value, $actorUserId);
    }

    /**
     * @param SpecValue $value
     */
    private function replaceProductOptionValues(int $valueId, array $value, UserId $actorUserId): void
    {
        $optionPublicIds = $value['optionPublicIds'];
        if ($optionPublicIds === []) {
            return;
        }

        $this->connection->execute(
            'DELETE FROM [product].[ProductSpecificationOptionValues] WHERE [ProductSpecificationValueId] = :valueId',
            [':valueId' => $valueId],
        );

        foreach ($optionPublicIds as $optionPublicId) {
            $optionId = $this->requireOptionId($optionPublicId);
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationOptionValues] (
    [ProductSpecificationValueId],
    [SpecificationOptionId]
)
VALUES (
    :valueId,
    :optionId
)
SQL,
                [
                    ':valueId' => $valueId,
                    ':optionId' => $optionId,
                ],
            );
        }
    }

    /**
     * @param SpecValue $value
     */
    private function replaceVariantOptionValues(int $valueId, array $value, UserId $actorUserId): void
    {
        $optionPublicIds = $value['optionPublicIds'];
        if ($optionPublicIds === []) {
            return;
        }

        $this->connection->execute(
            'DELETE FROM [product].[ProductVariantSpecificationOptionValues] WHERE [ProductVariantSpecificationValueId] = :valueId',
            [':valueId' => $valueId],
        );

        foreach ($optionPublicIds as $optionPublicId) {
            $optionId = $this->requireOptionId($optionPublicId);
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[ProductVariantSpecificationOptionValues] (
    [ProductVariantSpecificationValueId],
    [SpecificationOptionId]
)
VALUES (
    :valueId,
    :optionId
)
SQL,
                [
                    ':valueId' => $valueId,
                    ':optionId' => $optionId,
                ],
            );
        }
    }

    /**
     * @param SpecValue $value
     * @return array<string, scalar|SqlBinary|null>
     */
    private function bindSpecValueParams(array $value, ?int $optionId, UserId $actorUserId): array
    {
        return [
            ':valueText' => $value['valueText'] ?? null,
            ':valueLongText' => $value['valueLongText'] ?? null,
            ':valueInteger' => $value['valueInteger'] ?? null,
            ':valueDecimal' => $value['valueDecimal'] ?? null,
            ':valueBoolean' => $value['valueBoolean'] !== null ? ($value['valueBoolean'] ? 1 : 0) : null,
            ':valueDate' => $value['valueDate'] ?? null,
            ':valueUrl' => $value['valueUrl'] ?? null,
            ':valueMinDecimal' => $value['valueMinDecimal'] ?? null,
            ':valueMaxDecimal' => $value['valueMaxDecimal'] ?? null,
            ':optionId' => $optionId,
            ':createdByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
    }

    /**
     * Update statements never set CreatedByUserId, and binding an unused
     * parameter fails with HY093.
     *
     * @param array<string, scalar|SqlBinary|null> $bind
     * @return array<string, scalar|SqlBinary|null>
     */
    private function withoutCreatedByParam(array $bind): array
    {
        unset($bind[':createdByUserId']);

        return $bind;
    }

    /**
     * @param SpecValue $value
     */
    private function resolveOptionId(array $value): ?int
    {
        $optionPublicId = $value['optionPublicId'];
        if ($optionPublicId === null) {
            return null;
        }

        return $this->requireOptionId($optionPublicId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<SpecValue>
     */
    private function hydrateValueRows(array $rows, bool $isVariant): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $definitionPublicId = AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? '');
            if (!isset($grouped[$definitionPublicId])) {
                $grouped[$definitionPublicId] = [
                    'definitionPublicId' => $definitionPublicId,
                    'valueText' => AzureSqlValueMapper::nullableString($row['ValueText'] ?? null),
                    'valueLongText' => AzureSqlValueMapper::nullableString($row['ValueLongText'] ?? null),
                    'valueInteger' => AzureSqlValueMapper::intValue($row['ValueInteger'] ?? 0) ?: null,
                    'valueDecimal' => ProductDecimalMapper::toNullableString($row['ValueDecimal'] ?? null),
                    'valueBoolean' => array_key_exists('ValueBoolean', $row) && $row['ValueBoolean'] !== null
                        ? AzureSqlValueMapper::boolValue($row['ValueBoolean'])
                        : null,
                    'valueDate' => AzureSqlValueMapper::nullableString($row['ValueDate'] ?? null),
                    'valueUrl' => AzureSqlValueMapper::nullableString($row['ValueUrl'] ?? null),
                    'valueMinDecimal' => ProductDecimalMapper::toNullableString($row['ValueMinDecimal'] ?? null),
                    'valueMaxDecimal' => ProductDecimalMapper::toNullableString($row['ValueMaxDecimal'] ?? null),
                    'optionPublicId' => AzureSqlValueMapper::nullableString($row['OptionPublicId'] ?? null),
                    'optionPublicIds' => [],
                    '_valueId' => AzureSqlValueMapper::intValue(
                        $row[$isVariant ? 'ProductVariantSpecificationValueId' : 'ProductSpecificationValueId'] ?? 0,
                    ),
                ];
            }

            $optionPublicId = AzureSqlValueMapper::nullableString($row['OptionPublicId'] ?? null);
            if ($optionPublicId !== null && !in_array($optionPublicId, $grouped[$definitionPublicId]['optionPublicIds'], true)) {
                $grouped[$definitionPublicId]['optionPublicIds'][] = $optionPublicId;
            }
        }

        $valueIds = [];
        foreach ($grouped as $item) {
            $valueId = $item['_valueId'];
            if ($valueId > 0) {
                $valueIds[] = $valueId;
            }
        }
        $optionsByValueId = $this->loadOptionPublicIdsByValueIds($valueIds, $isVariant);

        foreach ($grouped as &$item) {
            $valueId = $item['_valueId'];
            unset($item['_valueId']);

            foreach ($optionsByValueId[$valueId] ?? [] as $junctionOptionPublicId) {
                if ($junctionOptionPublicId !== '' && !in_array($junctionOptionPublicId, $item['optionPublicIds'], true)) {
                    $item['optionPublicIds'][] = $junctionOptionPublicId;
                }
            }
        }
        unset($item);

        return array_values($grouped);
    }

    /**
     * @param list<int> $valueIds
     * @return array<int, list<string>>
     */
    private function loadOptionPublicIdsByValueIds(array $valueIds, bool $isVariant): array
    {
        $grouped = [];
        foreach ($valueIds as $valueId) {
            $grouped[$valueId] = [];
        }
        if ($valueIds === []) {
            return $grouped;
        }

        [$placeholders, $params] = $this->bindIntIdList($valueIds);
        $inList = implode(', ', $placeholders);
        $valueIdColumn = $isVariant
            ? 'j.[ProductVariantSpecificationValueId]'
            : 'j.[ProductSpecificationValueId]';
        $junctionTable = $isVariant
            ? '[product].[ProductVariantSpecificationOptionValues]'
            : '[product].[ProductSpecificationOptionValues]';

        $sql = <<<SQL
SELECT
    {$valueIdColumn} AS [ValueId],
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [OptionPublicId]
FROM {$junctionTable} j
INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = j.[SpecificationOptionId]
WHERE {$valueIdColumn} IN ({$inList})
SQL;

        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $valueId = AzureSqlValueMapper::intValue($row['ValueId'] ?? 0);
            $optionPublicId = AzureSqlValueMapper::stringValue($row['OptionPublicId'] ?? '');
            if ($valueId <= 0 || $optionPublicId === '') {
                continue;
            }
            $grouped[$valueId][] = $optionPublicId;
        }

        return $grouped;
    }

    /**
     * @param list<int> $ids
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function bindIntIdList(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':vid' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        return [$placeholders, $params];
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function requireVariantId(ProductVariantPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::variantNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductVariantId'] ?? 0);
    }

    private function requireDefinitionId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Specification definition', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationDefinitionId'] ?? 0);
    }

    private function requireOptionId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationOptionId] FROM [product].[SpecificationOptions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Specification option', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationOptionId'] ?? 0);
    }

    public function listGroupsAdmin(array $filters = []): array { return $this->admin->listGroups($filters); }
    public function findGroupByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationGroupRecord { return $this->admin->findGroup($publicId, $includeDeleted); }
    public function findGroupByCode(string $code): ?SpecificationGroupRecord { return $this->admin->findGroupByCode($code); }
    public function createGroup(array $data, UserId $actorUserId): SpecificationGroupRecord { return $this->admin->createGroup($data, $actorUserId); }
    public function updateGroup(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord { return $this->admin->updateGroup($publicId, $data, $expectedRowVersion, $actorUserId); }
    public function setGroupActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord { return $this->admin->setGroupActive($publicId, $isActive, $expectedRowVersion, $actorUserId); }
    public function softDeleteGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord { return $this->admin->softDeleteGroup($publicId, $expectedRowVersion, $actorUserId); }
    public function restoreGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord { return $this->admin->restoreGroup($publicId, $expectedRowVersion, $actorUserId); }
    public function reorderGroups(array $orderedPublicIds, UserId $actorUserId): void { $this->admin->reorderGroups($orderedPublicIds, $actorUserId); }
    public function getGroupUsage(string $publicId): SpecificationUsage { return $this->admin->groupUsage($publicId); }
    public function groupCodeExists(string $code, ?string $excludePublicId = null): bool { return $this->admin->groupCodeExists($code, $excludePublicId); }

    public function listUnitsAdmin(array $filters = []): array { return $this->admin->listUnits($filters); }
    public function findUnitByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationUnitRecord { return $this->admin->findUnit($publicId, $includeDeleted); }
    public function createUnit(array $data, UserId $actorUserId): SpecificationUnitRecord { return $this->admin->createUnit($data, $actorUserId); }
    public function updateUnit(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord { return $this->admin->updateUnit($publicId, $data, $expectedRowVersion, $actorUserId); }
    public function setUnitActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord { return $this->admin->setUnitActive($publicId, $isActive, $expectedRowVersion, $actorUserId); }
    public function softDeleteUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord { return $this->admin->softDeleteUnit($publicId, $expectedRowVersion, $actorUserId); }
    public function restoreUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord { return $this->admin->restoreUnit($publicId, $expectedRowVersion, $actorUserId); }
    public function reorderUnits(array $orderedPublicIds, UserId $actorUserId): void { $this->admin->reorderUnits($orderedPublicIds, $actorUserId); }
    public function getUnitUsage(string $publicId): SpecificationUsage { return $this->admin->unitUsage($publicId); }
    public function unitCodeExists(string $code, ?string $excludePublicId = null): bool { return $this->admin->unitCodeExists($code, $excludePublicId); }

    public function listDefinitionsAdmin(array $filters = []): array { return $this->admin->listDefinitions($filters); }
    public function findDefinitionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationDefinitionRecord { return $this->admin->findDefinition($publicId, $includeDeleted); }
    public function findDefinitionByCode(string $code): ?SpecificationDefinitionRecord { return $this->admin->findDefinitionByCode($code); }
    public function createDefinition(array $data, UserId $actorUserId): SpecificationDefinitionRecord { return $this->admin->createDefinition($data, $actorUserId); }
    public function updateDefinition(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord { return $this->admin->updateDefinition($publicId, $data, $expectedRowVersion, $actorUserId); }
    public function setDefinitionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord { return $this->admin->setDefinitionActive($publicId, $isActive, $expectedRowVersion, $actorUserId); }
    public function softDeleteDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord { return $this->admin->softDeleteDefinition($publicId, $expectedRowVersion, $actorUserId); }
    public function restoreDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord { return $this->admin->restoreDefinition($publicId, $expectedRowVersion, $actorUserId); }
    public function reorderDefinitions(?string $groupPublicId, array $orderedPublicIds, UserId $actorUserId): void { $this->admin->reorderDefinitions($groupPublicId, $orderedPublicIds, $actorUserId); }
    public function getDefinitionUsage(string $publicId): SpecificationUsage { return $this->admin->definitionUsage($publicId); }
    public function definitionCodeExists(string $code, ?string $excludePublicId = null): bool { return $this->admin->definitionCodeExists($code, $excludePublicId); }

    public function listOptionsAdmin(array $filters = []): array { return $this->admin->listOptions($filters); }
    public function findOptionByPublicId(string $publicId, bool $includeDeleted = false): ?SpecificationOptionRecord { return $this->admin->findOption($publicId, $includeDeleted); }
    public function findOptionByDefinitionAndCode(string $definitionPublicId, string $code): ?SpecificationOptionRecord { return $this->admin->findOptionByDefinitionAndCode($definitionPublicId, $code); }
    public function createOption(array $data, UserId $actorUserId): SpecificationOptionRecord { return $this->admin->createOption($data, $actorUserId); }
    public function updateOption(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord { return $this->admin->updateOption($publicId, $data, $expectedRowVersion, $actorUserId); }
    public function setOptionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord { return $this->admin->setOptionActive($publicId, $isActive, $expectedRowVersion, $actorUserId); }
    public function softDeleteOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord { return $this->admin->softDeleteOption($publicId, $expectedRowVersion, $actorUserId); }
    public function restoreOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord { return $this->admin->restoreOption($publicId, $expectedRowVersion, $actorUserId); }
    public function reorderOptions(string $definitionPublicId, array $orderedPublicIds, UserId $actorUserId): void { $this->admin->reorderOptions($definitionPublicId, $orderedPublicIds, $actorUserId); }
    public function getOptionUsage(string $publicId): SpecificationUsage { return $this->admin->optionUsage($publicId); }
    public function optionCodeExists(string $definitionPublicId, string $code, ?string $excludePublicId = null): bool { return $this->admin->optionCodeExists($definitionPublicId, $code, $excludePublicId); }

}
