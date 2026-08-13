<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\SpecificationDefinitionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\DTO\SpecificationValidationRules;
use TriCab\Core\Product\Application\Exception\SpecificationMasterConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Azure SQL admin persistence for specification groups, units, definitions, and options.
 */
final class AzureSqlSpecificationDefinitionAdmin
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    /** @param array<string, mixed> $filters @return list<SpecificationGroupRecord> */
    public function listGroups(array $filters = []): array
    {
        return array_map(fn (array $row): SpecificationGroupRecord => $this->hydrateGroup($row), $this->listSimple('group', $filters));
    }

    public function findGroup(string $publicId, bool $includeDeleted = false): ?SpecificationGroupRecord
    {
        $rows = $this->listSimple('group', ['publicId' => $publicId, 'includeDeleted' => $includeDeleted]);

        return $rows === [] ? null : $this->hydrateGroup($rows[0]);
    }

    public function findGroupByCode(string $code): ?SpecificationGroupRecord
    {
        $rows = $this->listSimple('group', ['code' => $code, 'includeDeleted' => false]);

        return $rows === [] ? null : $this->hydrateGroup($rows[0]);
    }

    /** @param array<string, mixed> $data */
    public function createGroup(array $data, UserId $actorUserId): SpecificationGroupRecord
    {
        $publicId = $this->insertSimple('group', $data, $actorUserId);
        $found = $this->findGroup($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('group', $publicId);
        }

        return $found;
    }

    /** @param array<string, mixed> $data */
    public function updateGroup(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        $this->updateSimple('group', $publicId, $data, $expectedRowVersion, $actorUserId);
        return $this->requireGroup($publicId);
    }

    public function setGroupActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        return $this->updateGroup($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        $this->softDeleteSimple('group', $publicId, $expectedRowVersion, $actorUserId);
        return $this->requireGroup($publicId, true);
    }

    public function restoreGroup(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationGroupRecord
    {
        $this->restoreSimple('group', $publicId, $expectedRowVersion, $actorUserId);
        return $this->requireGroup($publicId);
    }

    /** @param list<string> $orderedPublicIds */
    public function reorderGroups(array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorderSimple('group', $orderedPublicIds, $actorUserId);
    }

    public function groupUsage(string $publicId): SpecificationUsage
    {
        $rows = $this->connection->fetchAll(
            'SELECT
                SUM(CASE WHEN d.[DeletedAtUtc] IS NULL THEN 1 ELSE 0 END) AS [DefinitionCount],
                SUM(CASE WHEN d.[DeletedAtUtc] IS NULL AND d.[IsActive] = 1 THEN 1 ELSE 0 END) AS [ActiveDefinitionCount]
             FROM [product].[SpecificationGroups] g
             LEFT JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationGroupId] = g.[SpecificationGroupId]
             WHERE g.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        $row = $rows[0] ?? [];

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'group',
            definitionCount: AzureSqlValueMapper::intValue($row['DefinitionCount'] ?? 0),
            activeDefinitionCount: AzureSqlValueMapper::intValue($row['ActiveDefinitionCount'] ?? 0),
        );
    }

    public function groupCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExistsSimple('group', $code, $excludePublicId);
    }

    /** @param array<string, mixed> $filters @return list<SpecificationUnitRecord> */
    public function listUnits(array $filters = []): array
    {
        return array_map(fn (array $row): SpecificationUnitRecord => $this->hydrateUnit($row), $this->listSimple('unit', $filters));
    }

    public function findUnit(string $publicId, bool $includeDeleted = false): ?SpecificationUnitRecord
    {
        $rows = $this->listSimple('unit', ['publicId' => $publicId, 'includeDeleted' => $includeDeleted]);

        return $rows === [] ? null : $this->hydrateUnit($rows[0]);
    }

    /** @param array<string, mixed> $data */
    public function createUnit(array $data, UserId $actorUserId): SpecificationUnitRecord
    {
        $publicId = $this->insertSimple('unit', $data, $actorUserId);
        $found = $this->findUnit($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('unit', $publicId);
        }

        return $found;
    }

    /** @param array<string, mixed> $data */
    public function updateUnit(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        $this->updateSimple('unit', $publicId, $data, $expectedRowVersion, $actorUserId);
        $found = $this->findUnit($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('unit', $publicId);
        }

        return $found;
    }

    public function setUnitActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        return $this->updateUnit($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        $this->softDeleteSimple('unit', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findUnit($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('unit', $publicId);
        }

        return $found;
    }

    public function restoreUnit(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationUnitRecord
    {
        $this->restoreSimple('unit', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findUnit($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('unit', $publicId);
        }

        return $found;
    }

    /** @param list<string> $orderedPublicIds */
    public function reorderUnits(array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorderSimple('unit', $orderedPublicIds, $actorUserId);
    }

    public function unitUsage(string $publicId): SpecificationUsage
    {
        $unit = $this->findUnit($publicId, true);
        $symbol = $unit?->symbol;
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[SpecificationDefinitions] d
             WHERE d.[DeletedAtUtc] IS NULL
               AND (d.[Unit] = :symbol)',
            [':symbol' => $symbol ?? ''],
        );

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'unit',
            definitionCount: AzureSqlValueMapper::intValue($rows[0]['Cnt'] ?? 0),
        );
    }

    public function unitCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExistsSimple('unit', $code, $excludePublicId);
    }

    /** @param array<string, mixed> $filters @return list<SpecificationDefinitionRecord> */
    public function listDefinitions(array $filters = []): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    d.[DefinitionCode] AS [Code],
    d.[DefinitionName] AS [Name],
    d.[Description],
    CONVERT(NVARCHAR(36), g.[PublicId]) AS [GroupPublicId],
    g.[GroupCode],
    g.[GroupName],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[Unit],
    d.[DisplayOrder],
    d.[IsRequired],
    d.[IsFilterable],
    d.[IsSearchable],
    d.[IsComparable],
    CONVERT(NVARCHAR(50), d.[MinimumValue]) AS [MinimumValue],
    CONVERT(NVARCHAR(50), d.[MaximumValue]) AS [MaximumValue],
    d.[DecimalPrecision],
    d.[ValidationRulesJson],
    d.[IsActive],
    CASE WHEN d.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    CONVERT(VARBINARY(8), d.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), d.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), d.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] g ON g.[SpecificationGroupId] = d.[SpecificationGroupId]
WHERE 1 = 1
SQL;
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND d.[DeletedAtUtc] IS NULL';
        }
        if (isset($filters['publicId'])) {
            $sql .= ' AND d.[PublicId] = :publicId';
            $params[':publicId'] = $filters['publicId'];
        }
        if (isset($filters['code'])) {
            $sql .= ' AND d.[DefinitionCode] = :code';
            $params[':code'] = $filters['code'];
        }
        if (isset($filters['groupPublicId'])) {
            $sql .= ' AND g.[PublicId] = :groupPublicId';
            $params[':groupPublicId'] = $filters['groupPublicId'];
        }
        if (isset($filters['scopeCode'])) {
            $sql .= ' AND d.[ScopeCode] = :scopeCode';
            $params[':scopeCode'] = $filters['scopeCode'];
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND d.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $sql .= ' AND (d.[DefinitionCode] LIKE :search OR d.[DefinitionName] LIKE :search)';
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }
        $sql .= ' ORDER BY d.[DisplayOrder], d.[DefinitionName]';

        $rows = $this->connection->fetchAll($sql, $params);
        $publicIds = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            if ($publicId !== '') {
                $publicIds[] = $publicId;
            }
        }
        $usageById = $this->loadDefinitionUsageByPublicIds($publicIds);
        $optionsById = $this->loadOptionsGroupedByDefinitionPublicIds($publicIds);

        $result = [];
        foreach ($rows as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            $usage = $usageById[$publicId] ?? ['product' => 0, 'variant' => 0];
            $result[] = $this->hydrateDefinition(
                $row,
                $usage['product'],
                $usage['variant'],
                $optionsById[$publicId] ?? [],
            );
        }

        return $result;
    }

    public function findDefinition(string $publicId, bool $includeDeleted = false): ?SpecificationDefinitionRecord
    {
        $rows = $this->listDefinitions(['publicId' => $publicId, 'includeDeleted' => $includeDeleted]);

        return $rows[0] ?? null;
    }

    public function findDefinitionByCode(string $code): ?SpecificationDefinitionRecord
    {
        $rows = $this->listDefinitions(['code' => $code, 'includeDeleted' => false]);

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $data */
    public function createDefinition(array $data, UserId $actorUserId): SpecificationDefinitionRecord
    {
        $groupId = $this->resolveGroupId($data['groupPublicId'] ?? null);
        $rows = $this->connection->fetchAll(
            'INSERT INTO [product].[SpecificationDefinitions]
                ([DefinitionCode], [DefinitionName], [Description], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit],
                 [DisplayOrder], [IsRequired], [IsFilterable], [IsSearchable], [IsComparable], [MinimumValue], [MaximumValue],
                 [DecimalPrecision], [ValidationRulesJson], [IsActive], [CreatedByUserId], [UpdatedByUserId])
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             VALUES
                (:code, :name, :description, :groupId, :dataTypeCode, :scopeCode, :unit,
                 :displayOrder, :isRequired, :isFilterable, :isSearchable, :isComparable, :minimumValue, :maximumValue,
                 :decimalPrecision, :validationRulesJson, :isActive, :actor, :actor)',
            [
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':groupId' => $groupId,
                ':dataTypeCode' => $data['dataTypeCode'],
                ':scopeCode' => $data['scopeCode'] ?? 'product',
                ':unit' => $data['unit'] ?? null,
                ':displayOrder' => $data['displayOrder'] ?? 0,
                ':isRequired' => ($data['isRequired'] ?? false) ? 1 : 0,
                ':isFilterable' => ($data['isFilterable'] ?? false) ? 1 : 0,
                ':isSearchable' => ($data['isSearchable'] ?? false) ? 1 : 0,
                ':isComparable' => ($data['isComparable'] ?? false) ? 1 : 0,
                ':minimumValue' => $data['minimumValue'] ?? null,
                ':maximumValue' => $data['maximumValue'] ?? null,
                ':decimalPrecision' => $data['decimalPrecision'] ?? null,
                ':validationRulesJson' => $data['validationRulesJson'] ?? null,
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':actor' => $actorUserId->toInt(),
            ],
        );
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $found = $this->findDefinition($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('definition', $publicId);
        }

        return $found;
    }

    /** @param array<string, mixed> $data */
    public function updateDefinition(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        $groupId = array_key_exists('groupPublicId', $data) ? $this->resolveGroupId($data['groupPublicId']) : null;
        $sets = [
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :actor',
        ];
        $params = [
            ':publicId' => $publicId,
            ':actor' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        $map = [
            'code' => '[DefinitionCode] = :code',
            'name' => '[DefinitionName] = :name',
            'description' => '[Description] = :description',
            'dataTypeCode' => '[DataTypeCode] = :dataTypeCode',
            'scopeCode' => '[ScopeCode] = :scopeCode',
            'unit' => '[Unit] = :unit',
            'displayOrder' => '[DisplayOrder] = :displayOrder',
            'isRequired' => '[IsRequired] = :isRequired',
            'isFilterable' => '[IsFilterable] = :isFilterable',
            'isSearchable' => '[IsSearchable] = :isSearchable',
            'isComparable' => '[IsComparable] = :isComparable',
            'minimumValue' => '[MinimumValue] = :minimumValue',
            'maximumValue' => '[MaximumValue] = :maximumValue',
            'decimalPrecision' => '[DecimalPrecision] = :decimalPrecision',
            'validationRulesJson' => '[ValidationRulesJson] = :validationRulesJson',
            'isActive' => '[IsActive] = :isActive',
        ];
        foreach ($map as $key => $sqlSet) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = $sqlSet;
            $value = $data[$key];
            if (in_array($key, ['isRequired', 'isFilterable', 'isSearchable', 'isComparable', 'isActive'], true)) {
                $value = $value ? 1 : 0;
            }
            $params[':' . $key] = $value;
        }
        if (array_key_exists('groupPublicId', $data)) {
            $sets[] = '[SpecificationGroupId] = :groupId';
            $params[':groupId'] = $groupId;
        }
        $sql = 'UPDATE [product].[SpecificationDefinitions] SET ' . implode(', ', $sets)
            . ' OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]'
            . ' WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL';
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw SpecificationMasterConcurrencyConflict::for('definition', $publicId);
        }
        $found = $this->findDefinition($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('definition', $publicId);
        }

        return $found;
    }

    public function setDefinitionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        return $this->updateDefinition($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        $this->softDeleteSimple('definition', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findDefinition($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('definition', $publicId);
        }

        return $found;
    }

    public function restoreDefinition(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationDefinitionRecord
    {
        $this->restoreSimple('definition', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findDefinition($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('definition', $publicId);
        }

        return $found;
    }

    /** @param list<string> $orderedPublicIds */
    public function reorderDefinitions(?string $groupPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $current = $this->findDefinition($publicId);
            if ($current === null) {
                continue;
            }
            if ($groupPublicId !== null && $current->groupPublicId !== $groupPublicId) {
                continue;
            }
            $this->updateDefinition($publicId, ['displayOrder' => $order], $current->rowVersion, $actorUserId);
            $order += 10;
        }
    }

    public function definitionUsage(string $publicId): SpecificationUsage
    {
        $product = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductSpecificationValues] v
             INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
             WHERE d.[PublicId] = :publicId AND v.[DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        $variant = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductVariantSpecificationValues] v
             INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
             WHERE d.[PublicId] = :publicId AND v.[DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        $options = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[SpecificationOptions] o
             INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
             WHERE d.[PublicId] = :publicId AND o.[DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'definition',
            productValueCount: AzureSqlValueMapper::intValue($product[0]['Cnt'] ?? 0),
            variantValueCount: AzureSqlValueMapper::intValue($variant[0]['Cnt'] ?? 0),
            optionCount: AzureSqlValueMapper::intValue($options[0]['Cnt'] ?? 0),
        );
    }

    public function definitionCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExistsSimple('definition', $code, $excludePublicId);
    }

    /** @param array<string, mixed> $filters @return list<SpecificationOptionRecord> */
    public function listOptions(array $filters = []): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    o.[OptionCode] AS [Code],
    o.[OptionLabel] AS [Name],
    o.[OptionValue] AS [Value],
    o.[DisplayOrder],
    o.[IsActive],
    CASE WHEN o.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    CONVERT(VARBINARY(8), o.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), o.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), o.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
FROM [product].[SpecificationOptions] o
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
WHERE 1 = 1
SQL;
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND o.[DeletedAtUtc] IS NULL';
        }
        if (isset($filters['publicId'])) {
            $sql .= ' AND o.[PublicId] = :publicId';
            $params[':publicId'] = $filters['publicId'];
        }
        if (isset($filters['definitionPublicId'])) {
            $sql .= ' AND d.[PublicId] = :definitionPublicId';
            $params[':definitionPublicId'] = $filters['definitionPublicId'];
        }
        if (isset($filters['code']) && isset($filters['definitionPublicId'])) {
            $sql .= ' AND o.[OptionCode] = :code';
            $params[':code'] = $filters['code'];
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND o.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        $sql .= ' ORDER BY o.[DisplayOrder], o.[OptionLabel]';

        return array_map(fn (array $row): SpecificationOptionRecord => $this->hydrateOption($row), $this->connection->fetchAll($sql, $params));
    }

    public function findOption(string $publicId, bool $includeDeleted = false): ?SpecificationOptionRecord
    {
        $rows = $this->listOptions(['publicId' => $publicId, 'includeDeleted' => $includeDeleted]);

        return $rows[0] ?? null;
    }

    public function findOptionByDefinitionAndCode(string $definitionPublicId, string $code): ?SpecificationOptionRecord
    {
        $rows = $this->listOptions(['definitionPublicId' => $definitionPublicId, 'code' => $code, 'includeDeleted' => false]);

        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $data */
    public function createOption(array $data, UserId $actorUserId): SpecificationOptionRecord
    {
        $definitionId = $this->resolveDefinitionId((string) $data['definitionPublicId']);
        $rows = $this->connection->fetchAll(
            'INSERT INTO [product].[SpecificationOptions]
                ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             VALUES (:definitionId, :code, :name, :value, :displayOrder, :isActive, :actor, :actor)',
            [
                ':definitionId' => $definitionId,
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':value' => $data['value'] ?? null,
                ':displayOrder' => $data['displayOrder'] ?? 0,
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':actor' => $actorUserId->toInt(),
            ],
        );
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $found = $this->findOption($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('option', $publicId);
        }

        return $found;
    }

    /** @param array<string, mixed> $data */
    public function updateOption(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        $sets = ['[UpdatedAtUtc] = SYSUTCDATETIME()', '[UpdatedByUserId] = :actor'];
        $params = [
            ':publicId' => $publicId,
            ':actor' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        foreach (['code' => '[OptionCode] = :code', 'name' => '[OptionLabel] = :name', 'value' => '[OptionValue] = :value', 'displayOrder' => '[DisplayOrder] = :displayOrder', 'isActive' => '[IsActive] = :isActive'] as $key => $sqlSet) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $sets[] = $sqlSet;
            $params[':' . $key] = $key === 'isActive' ? (($data[$key] ?? false) ? 1 : 0) : $data[$key];
        }
        $sql = 'UPDATE [product].[SpecificationOptions] SET ' . implode(', ', $sets)
            . ' OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]'
            . ' WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL';
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw SpecificationMasterConcurrencyConflict::for('option', $publicId);
        }
        $found = $this->findOption($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('option', $publicId);
        }

        return $found;
    }

    public function setOptionActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        return $this->updateOption($publicId, ['isActive' => $isActive], $expectedRowVersion, $actorUserId);
    }

    public function softDeleteOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        $this->softDeleteSimple('option', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findOption($publicId, true);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('option', $publicId);
        }

        return $found;
    }

    public function restoreOption(string $publicId, string $expectedRowVersion, UserId $actorUserId): SpecificationOptionRecord
    {
        $this->restoreSimple('option', $publicId, $expectedRowVersion, $actorUserId);
        $found = $this->findOption($publicId);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('option', $publicId);
        }

        return $found;
    }

    /** @param list<string> $orderedPublicIds */
    public function reorderOptions(string $definitionPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $current = $this->findOption($publicId);
            if ($current === null || $current->definitionPublicId !== $definitionPublicId) {
                continue;
            }
            $this->updateOption($publicId, ['displayOrder' => $order], $current->rowVersion, $actorUserId);
            $order += 10;
        }
    }

    public function optionUsage(string $publicId): SpecificationUsage
    {
        $product = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductSpecificationValues] v
             INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = v.[SpecificationOptionId]
             WHERE o.[PublicId] = :publicId AND v.[DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        $variant = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductVariantSpecificationValues] v
             INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = v.[SpecificationOptionId]
             WHERE o.[PublicId] = :publicId AND v.[DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        $multiProduct = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductSpecificationOptionValues] j
             INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = j.[SpecificationOptionId]
             WHERE o.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        $multiVariant = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt]
             FROM [product].[ProductVariantSpecificationOptionValues] j
             INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = j.[SpecificationOptionId]
             WHERE o.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return new SpecificationUsage(
            publicId: $publicId,
            level: 'option',
            productValueCount: AzureSqlValueMapper::intValue($product[0]['Cnt'] ?? 0) + AzureSqlValueMapper::intValue($multiProduct[0]['Cnt'] ?? 0),
            variantValueCount: AzureSqlValueMapper::intValue($variant[0]['Cnt'] ?? 0) + AzureSqlValueMapper::intValue($multiVariant[0]['Cnt'] ?? 0),
        );
    }

    public function optionCodeExists(string $definitionPublicId, string $code, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT TOP 1 1 AS [ExistsFlag]
                FROM [product].[SpecificationOptions] o
                INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
                WHERE d.[PublicId] = :definitionPublicId AND o.[OptionCode] = :code AND o.[DeletedAtUtc] IS NULL';
        $params = [':definitionPublicId' => $definitionPublicId, ':code' => $code];
        if ($excludePublicId !== null) {
            $sql .= ' AND o.[PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    /** @return array{table: string, code: string, name: string} */
    private function meta(string $level): array
    {
        return match ($level) {
            'group' => ['table' => '[product].[SpecificationGroups]', 'code' => 'GroupCode', 'name' => 'GroupName'],
            'unit' => ['table' => '[product].[SpecificationUnits]', 'code' => 'UnitCode', 'name' => 'UnitName'],
            'definition' => ['table' => '[product].[SpecificationDefinitions]', 'code' => 'DefinitionCode', 'name' => 'DefinitionName'],
            'option' => ['table' => '[product].[SpecificationOptions]', 'code' => 'OptionCode', 'name' => 'OptionLabel'],
            default => throw new \InvalidArgumentException('Unknown specification level'),
        };
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    private function listSimple(string $level, array $filters): array
    {
        $meta = $this->meta($level);
        $extra = $level === 'unit' ? ', t.[Symbol]' : '';
        $sql = sprintf(
            'SELECT CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId], t.[%s] AS [Code], t.[%s] AS [Name], t.[Description], t.[DisplayOrder], t.[IsActive],
                    CASE WHEN t.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
                    CONVERT(VARBINARY(8), t.[RowVersion]) AS [RowVersion],
                    CONVERT(NVARCHAR(33), t.[CreatedAtUtc], 126) AS [CreatedAtUtc],
                    CONVERT(NVARCHAR(33), t.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]%s
             FROM %s t WHERE 1 = 1',
            $meta['code'],
            $meta['name'],
            $extra,
            $meta['table'],
        );
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND t.[DeletedAtUtc] IS NULL';
        }
        if (isset($filters['publicId'])) {
            $sql .= ' AND t.[PublicId] = :publicId';
            $params[':publicId'] = $filters['publicId'];
        }
        if (isset($filters['code'])) {
            $sql .= sprintf(' AND t.[%s] = :code', $meta['code']);
            $params[':code'] = $filters['code'];
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND t.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $sql .= sprintf(' AND (t.[%s] LIKE :search OR t.[%s] LIKE :search)', $meta['code'], $meta['name']);
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }
        $sql .= sprintf(' ORDER BY t.[DisplayOrder], t.[%s]', $meta['name']);

        return $this->connection->fetchAll($sql, $params);
    }

    /** @param array<string, mixed> $data */
    private function insertSimple(string $level, array $data, UserId $actorUserId): string
    {
        $meta = $this->meta($level);
        if ($level === 'unit') {
            $rows = $this->connection->fetchAll(
                sprintf(
                    'INSERT INTO %s ([%s], [%s], [Symbol], [Description], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
                     OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                     VALUES (:code, :name, :symbol, :description, :displayOrder, :isActive, :actor, :actor)',
                    $meta['table'],
                    $meta['code'],
                    $meta['name'],
                ),
                [
                    ':code' => $data['code'],
                    ':name' => $data['name'],
                    ':symbol' => $data['symbol'],
                    ':description' => $data['description'] ?? null,
                    ':displayOrder' => $data['displayOrder'] ?? 0,
                    ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                    ':actor' => $actorUserId->toInt(),
                ],
            );
        } else {
            $rows = $this->connection->fetchAll(
                sprintf(
                    'INSERT INTO %s ([%s], [%s], [Description], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
                     OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                     VALUES (:code, :name, :description, :displayOrder, :isActive, :actor, :actor)',
                    $meta['table'],
                    $meta['code'],
                    $meta['name'],
                ),
                [
                    ':code' => $data['code'],
                    ':name' => $data['name'],
                    ':description' => $data['description'] ?? null,
                    ':displayOrder' => $data['displayOrder'] ?? 0,
                    ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                    ':actor' => $actorUserId->toInt(),
                ],
            );
        }

        return AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
    }

    /** @param array<string, mixed> $data */
    private function updateSimple(string $level, string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): void
    {
        $meta = $this->meta($level);
        $sets = ['[UpdatedAtUtc] = SYSUTCDATETIME()', '[UpdatedByUserId] = :actor'];
        $params = [
            ':publicId' => $publicId,
            ':actor' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        $fieldMap = [
            'code' => sprintf('[%s] = :code', $meta['code']),
            'name' => sprintf('[%s] = :name', $meta['name']),
            'description' => '[Description] = :description',
            'displayOrder' => '[DisplayOrder] = :displayOrder',
            'isActive' => '[IsActive] = :isActive',
            'symbol' => '[Symbol] = :symbol',
        ];
        foreach ($fieldMap as $key => $sqlSet) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'symbol' && $level !== 'unit') {
                continue;
            }
            $sets[] = $sqlSet;
            $params[':' . $key] = $key === 'isActive' ? (($data[$key] ?? false) ? 1 : 0) : $data[$key];
        }
        $sql = sprintf(
            'UPDATE %s SET %s OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL',
            $meta['table'],
            implode(', ', $sets),
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw SpecificationMasterConcurrencyConflict::for($level, $publicId);
        }
    }

    private function softDeleteSimple(string $level, string $publicId, string $expectedRowVersion, UserId $actorUserId): void
    {
        $meta = $this->meta($level);
        $rows = $this->connection->fetchAll(
            sprintf(
                'UPDATE %s SET [DeletedAtUtc] = SYSUTCDATETIME(), [DeletedByUserId] = :actor, [IsActive] = 0,
                    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
                 OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                 WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL',
                $meta['table'],
            ),
            [
                ':publicId' => $publicId,
                ':actor' => $actorUserId->toInt(),
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
        );
        if ($rows === []) {
            throw SpecificationMasterConcurrencyConflict::for($level, $publicId);
        }
    }

    private function restoreSimple(string $level, string $publicId, string $expectedRowVersion, UserId $actorUserId): void
    {
        $meta = $this->meta($level);
        $rows = $this->connection->fetchAll(
            sprintf(
                'UPDATE %s SET [DeletedAtUtc] = NULL, [DeletedByUserId] = NULL, [IsActive] = 1,
                    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
                 OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
                 WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NOT NULL',
                $meta['table'],
            ),
            [
                ':publicId' => $publicId,
                ':actor' => $actorUserId->toInt(),
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
        );
        if ($rows === []) {
            throw SpecificationMasterConcurrencyConflict::for($level, $publicId);
        }
    }

    /** @param list<string> $orderedPublicIds */
    private function reorderSimple(string $level, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $rows = $this->listSimple($level, ['publicId' => $publicId, 'includeDeleted' => false]);
            if ($rows === []) {
                continue;
            }
            $rowVersion = ProductRowVersionMapper::toToken($rows[0]['RowVersion'] ?? null)->toBase64();
            $this->updateSimple($level, $publicId, ['displayOrder' => $order], $rowVersion, $actorUserId);
            $order += 10;
        }
    }

    private function codeExistsSimple(string $level, string $code, ?string $excludePublicId): bool
    {
        $meta = $this->meta($level);
        $sql = sprintf('SELECT TOP 1 1 AS [ExistsFlag] FROM %s WHERE [%s] = :code AND [DeletedAtUtc] IS NULL', $meta['table'], $meta['code']);
        $params = [':code' => $code];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    private function requireGroup(string $publicId, bool $includeDeleted = false): SpecificationGroupRecord
    {
        $found = $this->findGroup($publicId, $includeDeleted);
        if ($found === null) {
            throw SpecificationMasterNotFound::for('group', $publicId);
        }

        return $found;
    }

    private function resolveGroupId(mixed $groupPublicId): ?int
    {
        if (!is_string($groupPublicId) || $groupPublicId === '') {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $groupPublicId],
        );
        if ($rows === []) {
            throw SpecificationMasterNotFound::for('group', $groupPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationGroupId'] ?? 0);
    }

    private function resolveDefinitionId(string $definitionPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $definitionPublicId],
        );
        if ($rows === []) {
            throw SpecificationMasterNotFound::for('definition', $definitionPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationDefinitionId'] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private function hydrateGroup(array $row): SpecificationGroupRecord
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $usage = $this->groupUsage($publicId);

        return new SpecificationGroupRecord(
            publicId: $publicId,
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            definitionCount: $usage->definitionCount,
            activeDefinitionCount: $usage->activeDefinitionCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateUnit(array $row): SpecificationUnitRecord
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $usage = $this->unitUsage($publicId);

        return new SpecificationUnitRecord(
            publicId: $publicId,
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            symbol: AzureSqlValueMapper::stringValue($row['Symbol'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            definitionCount: $usage->definitionCount,
        );
    }

    /**
     * @param list<string> $publicIds
     * @return array<string, array{product: int, variant: int}>
     */
    private function loadDefinitionUsageByPublicIds(array $publicIds): array
    {
        $usage = [];
        foreach ($publicIds as $publicId) {
            $usage[$publicId] = ['product' => 0, 'variant' => 0];
        }
        if ($publicIds === []) {
            return $usage;
        }

        [$placeholders, $params] = $this->bindPublicIdList($publicIds);
        $inList = implode(', ', $placeholders);

        foreach ($this->connection->fetchAll(
            'SELECT CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId], COUNT(1) AS [Cnt]
             FROM [product].[ProductSpecificationValues] v
             INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
             WHERE v.[DeletedAtUtc] IS NULL AND d.[PublicId] IN (' . $inList . ')
             GROUP BY d.[PublicId]',
            $params,
        ) as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            if ($publicId !== '') {
                $usage[$publicId]['product'] = AzureSqlValueMapper::intValue($row['Cnt'] ?? 0);
            }
        }

        foreach ($this->connection->fetchAll(
            'SELECT CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId], COUNT(1) AS [Cnt]
             FROM [product].[ProductVariantSpecificationValues] v
             INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
             WHERE v.[DeletedAtUtc] IS NULL AND d.[PublicId] IN (' . $inList . ')
             GROUP BY d.[PublicId]',
            $params,
        ) as $row) {
            $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            if ($publicId !== '') {
                $usage[$publicId]['variant'] = AzureSqlValueMapper::intValue($row['Cnt'] ?? 0);
            }
        }

        return $usage;
    }

    /**
     * @param list<string> $publicIds
     * @return array<string, list<SpecificationOptionRecord>>
     */
    private function loadOptionsGroupedByDefinitionPublicIds(array $publicIds): array
    {
        $grouped = [];
        foreach ($publicIds as $publicId) {
            $grouped[$publicId] = [];
        }
        if ($publicIds === []) {
            return $grouped;
        }

        [$placeholders, $params] = $this->bindPublicIdList($publicIds);
        $inList = implode(', ', $placeholders);
        $sql = <<<SQL
SELECT
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    o.[OptionCode] AS [Code],
    o.[OptionLabel] AS [Name],
    o.[OptionValue] AS [Value],
    o.[DisplayOrder],
    o.[IsActive],
    CASE WHEN o.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    CONVERT(VARBINARY(8), o.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), o.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), o.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
FROM [product].[SpecificationOptions] o
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
WHERE d.[PublicId] IN ({$inList})
ORDER BY o.[DisplayOrder], o.[OptionLabel]
SQL;

        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $definitionPublicId = AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? '');
            if ($definitionPublicId === '') {
                continue;
            }
            $grouped[$definitionPublicId][] = $this->hydrateOption($row);
        }

        return $grouped;
    }

    /**
     * @param list<string> $publicIds
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function bindPublicIdList(array $publicIds): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($publicIds) as $index => $publicId) {
            $key = ':pid' . $index;
            $placeholders[] = $key;
            $params[$key] = $publicId;
        }

        return [$placeholders, $params];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<SpecificationOptionRecord>|null $options
     */
    private function hydrateDefinition(
        array $row,
        ?int $productValueCount = null,
        ?int $variantValueCount = null,
        ?array $options = null,
    ): SpecificationDefinitionRecord {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        if ($productValueCount === null || $variantValueCount === null) {
            $usage = $this->definitionUsage($publicId);
            $productValueCount = $usage->productValueCount;
            $variantValueCount = $usage->variantValueCount;
        }
        $options ??= $this->listOptions(['definitionPublicId' => $publicId, 'includeDeleted' => true]);

        return new SpecificationDefinitionRecord(
            publicId: $publicId,
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            groupPublicId: AzureSqlValueMapper::nullableString($row['GroupPublicId'] ?? null),
            groupCode: AzureSqlValueMapper::nullableString($row['GroupCode'] ?? null),
            groupName: AzureSqlValueMapper::nullableString($row['GroupName'] ?? null),
            dataTypeCode: AzureSqlValueMapper::stringValue($row['DataTypeCode'] ?? 'text'),
            scopeCode: AzureSqlValueMapper::stringValue($row['ScopeCode'] ?? 'product'),
            unit: AzureSqlValueMapper::nullableString($row['Unit'] ?? null),
            unitPublicId: null,
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isRequired: AzureSqlValueMapper::boolValue($row['IsRequired'] ?? false),
            isFilterable: AzureSqlValueMapper::boolValue($row['IsFilterable'] ?? false),
            isSearchable: AzureSqlValueMapper::boolValue($row['IsSearchable'] ?? false),
            isComparable: AzureSqlValueMapper::boolValue($row['IsComparable'] ?? false),
            minimumValue: AzureSqlValueMapper::nullableString($row['MinimumValue'] ?? null),
            maximumValue: AzureSqlValueMapper::nullableString($row['MaximumValue'] ?? null),
            decimalPrecision: isset($row['DecimalPrecision']) && $row['DecimalPrecision'] !== null
                ? AzureSqlValueMapper::intValue($row['DecimalPrecision'])
                : null,
            validationRules: SpecificationValidationRules::fromJson(AzureSqlValueMapper::nullableString($row['ValidationRulesJson'] ?? null)),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            options: $options,
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            productValueCount: $productValueCount,
            variantValueCount: $variantValueCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateOption(array $row): SpecificationOptionRecord
    {
        return new SpecificationOptionRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            definitionPublicId: AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            value: AzureSqlValueMapper::nullableString($row['Value'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
        );
    }
}
