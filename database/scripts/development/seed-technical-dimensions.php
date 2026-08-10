<?php

declare(strict_types=1);

/**
 * Seed catalogue Technical Dimensions definitions and BVA Size values.
 *
 * Columns (Customer View order):
 *   Size (mm²), Min. Bend (Radius mm), Nom. Cond. (OD mm), Nom. Ins. (OD mm),
 *   Nom. Cable (OD mm), Current Rating (amp), Approx. Wt. (kg/km)
 *
 * Size is populated for BVA from coding fields (core count × cross-section).
 * Other dimension cells stay empty until datasheet numbers are loaded.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$c = ContainerFactory::create(Environment::settings(dirname(__DIR__, 3)));
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$actorUserId = 1;
$mechanicalGroupId = 2;
$electricalGroupId = 1;

$publicRules = json_encode([
    'isPublicVisible' => true,
    'isAdminOnly' => false,
], JSON_THROW_ON_ERROR);

$definitions = [
    [
        'code' => 'SIZE',
        'name' => 'Size',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'text',
        'scope' => 'variant',
        'unit' => 'mm²',
        'order' => 10,
        'description' => 'Catalogue technical dimensions — conductor size',
    ],
    [
        'code' => 'MIN-BEND-RADIUS',
        'name' => 'Min. Bend',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'Radius mm',
        'order' => 20,
        'description' => 'Catalogue technical dimensions — minimum bend radius',
    ],
    [
        'code' => 'NOM-COND-OD',
        'name' => 'Nom. Cond.',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'OD mm',
        'order' => 30,
        'description' => 'Catalogue technical dimensions — nominal conductor OD',
    ],
    [
        'code' => 'NOM-INS-OD',
        'name' => 'Nom. Ins.',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'OD mm',
        'order' => 40,
        'description' => 'Catalogue technical dimensions — nominal insulation OD',
    ],
    [
        'code' => 'NOM-CABLE-OD',
        'name' => 'Nom. Cable',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'OD mm',
        'order' => 50,
        'description' => 'Catalogue technical dimensions — nominal cable OD',
    ],
    [
        'code' => 'CURRENT',
        'name' => 'Current Rating',
        'groupId' => $electricalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'amp',
        'order' => 60,
        'description' => 'Catalogue technical dimensions — current rating',
    ],
    [
        'code' => 'APPROX-WEIGHT',
        'name' => 'Approx. Wt.',
        'groupId' => $mechanicalGroupId,
        'dataType' => 'decimal',
        'scope' => 'variant',
        'unit' => 'kg/km',
        'order' => 70,
        'description' => 'Catalogue technical dimensions — approximate weight',
    ],
];

function ensureDimensionDefinition(SqlConnection $db, array $def, string $publicRules, int $actorUserId): int
{
    $existing = $db->fetchAll(
        'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL',
        [':code' => $def['code']],
    );

    $params = [
        ':name' => $def['name'],
        ':description' => $def['description'],
        ':groupId' => $def['groupId'],
        ':dataType' => $def['dataType'],
        ':scope' => $def['scope'],
        ':unit' => $def['unit'],
        ':displayOrder' => $def['order'],
        ':rules' => $publicRules,
        ':actor' => $actorUserId,
    ];

    if ($existing !== []) {
        $id = (int) $existing[0]['SpecificationDefinitionId'];
        $db->execute(
            <<<'SQL'
UPDATE [product].[SpecificationDefinitions]
SET
    [DefinitionName] = :name,
    [Description] = :description,
    [SpecificationGroupId] = :groupId,
    [DataTypeCode] = :dataType,
    [ScopeCode] = :scope,
    [Unit] = :unit,
    [DisplayOrder] = :displayOrder,
    [ValidationRulesJson] = :rules,
    [IsComparable] = 1,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [SpecificationDefinitionId] = :id
SQL,
            $params + [':id' => $id],
        );
        echo "Updated definition {$def['code']}\n";

        return $id;
    }

    $rows = $db->fetchAll(
        <<<'SQL'
INSERT INTO [product].[SpecificationDefinitions]
    ([DefinitionCode], [DefinitionName], [Description], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit],
     [DisplayOrder], [IsRequired], [IsFilterable], [IsSearchable], [IsComparable], [MinimumValue], [MaximumValue],
     [DecimalPrecision], [ValidationRulesJson], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationDefinitionId]
VALUES
    (:code, :name, :description, :groupId, :dataType, :scope, :unit,
     :displayOrder, 0, 0, 1, 1, NULL, NULL,
     NULL, :rules, 1, :createdBy, :updatedBy)
SQL,
        [
            ':code' => $def['code'],
            ':name' => $def['name'],
            ':description' => $def['description'],
            ':groupId' => $def['groupId'],
            ':dataType' => $def['dataType'],
            ':scope' => $def['scope'],
            ':unit' => $def['unit'],
            ':displayOrder' => $def['order'],
            ':rules' => $publicRules,
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );
    echo "Created definition {$def['code']}\n";

    return (int) $rows[0]['SpecificationDefinitionId'];
}

// Hide coding fields from customer-view comparable matrix.
$db->execute(
    <<<'SQL'
UPDATE [product].[SpecificationDefinitions]
SET [IsComparable] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [DefinitionCode] LIKE N'CODE_%'
  AND [DeletedAtUtc] IS NULL
  AND [IsComparable] = 1
SQL,
    [':actor' => $actorUserId],
);
echo "Marked CODE_* definitions as not comparable\n";

$definitionIds = [];
foreach ($definitions as $def) {
    $definitionIds[$def['code']] = ensureDimensionDefinition($db, $def, $publicRules, $actorUserId);
}

$sizeDefinitionId = $definitionIds['SIZE'];

// Populate SIZE for BVA variants from coding fields.
$variants = $db->fetchAll(
    <<<'SQL'
SELECT
    pv.[ProductVariantId],
    pv.[VariantName],
    coreCount.[ValueInteger] AS [CoreCount],
    COALESCE(crossOpt.[OptionCode], crossText.[ValueText], crossText.[ValueDecimal], CONVERT(NVARCHAR(50), crossText.[ValueInteger])) AS [CrossSection]
FROM [product].[ProductVariants] pv
INNER JOIN [product].[Products] p ON p.[ProductId] = pv.[ProductId]
LEFT JOIN [product].[ProductVariantSpecificationValues] coreCount
    ON coreCount.[ProductVariantId] = pv.[ProductVariantId]
   AND coreCount.[DeletedAtUtc] IS NULL
   AND coreCount.[SpecificationDefinitionId] = (
        SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'CODE_CORE_COUNT' AND [DeletedAtUtc] IS NULL
   )
LEFT JOIN [product].[ProductVariantSpecificationValues] crossText
    ON crossText.[ProductVariantId] = pv.[ProductVariantId]
   AND crossText.[DeletedAtUtc] IS NULL
   AND crossText.[SpecificationDefinitionId] = (
        SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions]
        WHERE [DefinitionCode] = N'CODE_CROSS_SECTION' AND [DeletedAtUtc] IS NULL
   )
LEFT JOIN [product].[SpecificationOptions] crossOpt
    ON crossOpt.[SpecificationOptionId] = crossText.[SpecificationOptionId]
WHERE p.[InternalCode] = N'BVA'
  AND pv.[DeletedAtUtc] IS NULL
SQL,
);

$upserted = 0;
foreach ($variants as $variant) {
    $variantId = (int) $variant['ProductVariantId'];
    $cross = trim((string) ($variant['CrossSection'] ?? ''));
    if ($cross === '') {
        // Fall back to parsing "1C 4mm² Black" style names.
        if (preg_match('/(\d+(?:\.\d+)?)\s*mm/ui', (string) $variant['VariantName'], $m) === 1) {
            $cross = $m[1];
        } else {
            continue;
        }
    }
    if (is_numeric($cross)) {
        $cross = rtrim(rtrim(sprintf('%.4F', (float) $cross), '0'), '.');
    }
    $cores = $variant['CoreCount'] !== null ? (int) $variant['CoreCount'] : 0;
    if ($cores <= 0 && preg_match('/(\d+)\s*C\b/ui', (string) $variant['VariantName'], $m) === 1) {
        $cores = (int) $m[1];
    }
    $sizeLabel = $cores > 0
        ? sprintf('%dC %smm²', $cores, $cross)
        : sprintf('%smm²', $cross);

    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductVariantSpecificationValueId]
FROM [product].[ProductVariantSpecificationValues]
WHERE [ProductVariantId] = :variantId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [
            ':variantId' => $variantId,
            ':definitionId' => $sizeDefinitionId,
        ],
    );

    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductVariantSpecificationValues]
    ([ProductVariantId], [SpecificationDefinitionId], [ValueText], [CreatedByUserId], [UpdatedByUserId])
VALUES (:variantId, :definitionId, :valueText, :actor, :actor2)
SQL,
            [
                ':variantId' => $variantId,
                ':definitionId' => $sizeDefinitionId,
                ':valueText' => $sizeLabel,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );
    } else {
        $db->execute(
            <<<'SQL'
UPDATE [product].[ProductVariantSpecificationValues]
SET [ValueText] = :valueText,
    [ValueDecimal] = NULL,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductVariantSpecificationValueId] = :valueId
SQL,
            [
                ':valueText' => $sizeLabel,
                ':actor' => $actorUserId,
                ':valueId' => (int) $existing[0]['ProductVariantSpecificationValueId'],
            ],
        );
    }
    $upserted++;
}

echo "Upserted SIZE for {$upserted} BVA variants\n";
echo "Done.\n";
