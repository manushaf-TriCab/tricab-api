<?php

declare(strict_types=1);

/**
 * Seed Construction / Jacket Options / Approvals definitions + BH product values
 * from the TriCab BFOI/BFCI catalogue datasheet.
 *
 * Safe to re-run (upserts by DefinitionCode / product+definition).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$c = ContainerFactory::create(Environment::settings(dirname(__DIR__, 3)));
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$actorUserId = 1;
$constructionGroupId = 3;
$complianceGroupId = 4;
$productCodes = ['BHA', 'BHC', 'BHX'];

$publicRules = json_encode([
    'isPublicVisible' => true,
    'isAdminOnly' => false,
], JSON_THROW_ON_ERROR);

/** @var list<array{code:string,name:string,groupId:int,dataType:string,scope:string,order:int,description:?string}> $definitions */
$definitions = [
    [
        'code' => 'CONDUCTORS',
        'name' => 'Conductors',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 10,
        'description' => 'Catalogue construction — conductors',
    ],
    [
        'code' => 'FLAME-BARRIER',
        'name' => 'Flame Barrier',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 12,
        'description' => 'Catalogue construction — flame barrier',
    ],
    [
        'code' => 'CORE-INSULATION',
        'name' => 'Core Insulation',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 14,
        'description' => 'Catalogue construction — core insulation',
    ],
    [
        'code' => 'SEPARATOR',
        'name' => 'Separator',
        'groupId' => $constructionGroupId,
        'dataType' => 'text',
        'scope' => 'product',
        'order' => 16,
        'description' => 'Catalogue construction — inner separator',
    ],
    [
        'code' => 'BRAID',
        'name' => 'Braid',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 18,
        'description' => 'Catalogue construction — braid',
    ],
    [
        'code' => 'SEPARATOR-OUTER',
        'name' => 'Separator',
        'groupId' => $constructionGroupId,
        'dataType' => 'text',
        'scope' => 'product',
        'order' => 20,
        'description' => 'Catalogue construction — outer separator',
    ],
    [
        'code' => 'JACKET',
        'name' => 'Jacket',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 22,
        'description' => 'Catalogue construction — jacket',
    ],
    [
        'code' => 'JACKET-OPTION-A',
        'name' => 'A Type',
        'groupId' => $constructionGroupId,
        'dataType' => 'text',
        'scope' => 'product',
        'order' => 80,
        'description' => 'Jacket option A',
    ],
    [
        'code' => 'JACKET-OPTION-C',
        'name' => 'C Type',
        'groupId' => $constructionGroupId,
        'dataType' => 'text',
        'scope' => 'product',
        'order' => 81,
        'description' => 'Jacket option C',
    ],
    [
        'code' => 'JACKET-OPTION-M',
        'name' => 'M Type',
        'groupId' => $constructionGroupId,
        'dataType' => 'text',
        'scope' => 'product',
        'order' => 82,
        'description' => 'Jacket option M',
    ],
    [
        'code' => 'APPROVALS',
        'name' => 'Approvals',
        'groupId' => $complianceGroupId,
        'dataType' => 'multi-select',
        'scope' => 'product',
        'order' => 70,
        'description' => 'Type approvals / class societies',
    ],
    [
        'code' => 'APPROVAL-NOTE',
        'name' => 'Approval note',
        'groupId' => $complianceGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 71,
        'description' => 'Additional approval / test notes',
    ],
];

$constructionValues = [
    'CONDUCTORS' => "Finely stranded annealed copper to IEC 60228 & AS/NZS 1125 as standard. Available in plain or tinned.",
    'FLAME-BARRIER' => 'Fire resistant, halogen free, glass mica tape.',
    'CORE-INSULATION' => '‘Easy Strip’ LSZH HEPR to IEC 60092-360.',
    'SEPARATOR' => 'Polypropylene or Polyester Tape.',
    'BRAID' => 'Tinned Copper Wire (90%) or Galvanized Steel Wire (90%).',
    'SEPARATOR-OUTER' => 'Polypropylene or Polyester Tape.',
    'JACKET' => "Flame Retardant ‘Easy Strip’ Low Friction LSZH. Splash resistant to oil, skydrol, petrol, acid, sea water. Resists ozone and UV.",
    'JACKET-OPTION-A' => 'SHF 1 to IEC 60092-360 Thermoplastic.',
    'JACKET-OPTION-C' => 'SHF 2 to IEC 60092-360 Thermoset, Oil Resistant.',
    'JACKET-OPTION-M' => 'SHF MUD to NEK TS 606 Thermoset, Oil & Mud Resistant.',
    'APPROVAL-NOTE' => 'Cable constructions, Jackets, and Inner components tested to UL 1309 - CSA C22.2 No. 245 for Cold Bend (-40°C) and Cold Impact (-35°C).',
];

$approvalOptions = [
    ['ABS', 'ABS', 10],
    ['BV', 'BV', 20],
    ['DNV-GL', 'DNV-GL', 30],
    ['USCG', 'USCG', 40],
    ['TC-MARINE', 'TC Transport Canada Marine Safety', 50],
];

$extraStandardOptions = [
    ['IEEE-45', 'IEEE 45', 200],
    ['IEEE-1580', 'IEEE 1580', 210],
    ['IEEE-1202', 'IEEE 1202', 220],
    ['AS-NZS-1660-5-1', 'AS/NZS 1660.5.1', 230],
    ['AS-NZS-1660-5-2', 'AS/NZS 1660.5.2', 240],
    ['AS-NZS-1660-5-3', 'AS/NZS 1660.5.3', 250],
    ['AS-NZS-1660-5-6', 'AS/NZS 1660.5.6', 260],
];

$standardLabelsToAssign = [
    'IEC 60228',
    'IEC 60092-350',
    'IEC 60092-353',
    'IEC 60331-1',
    'IEC 60331-21',
    'IEC 60332-1',
    'IEC 60332-3-22',
    'IEC 60754-1',
    'IEC 60754-2',
    'IEC 61034-1/2',
    'IEEE 45',
    'IEEE 1580',
    'IEEE 1202',
    'AS/NZS 1125',
    'AS/NZS 1660.5.1',
    'AS/NZS 1660.5.2',
    'AS/NZS 1660.5.3',
    'AS/NZS 1660.5.6',
];

function ensureDefinition(SqlConnection $db, array $def, string $publicRules, int $actorUserId): int
{
    $existing = $db->fetchAll(
        'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL',
        [':code' => $def['code']],
    );
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
    [DisplayOrder] = :displayOrder,
    [ValidationRulesJson] = :rules,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [SpecificationDefinitionId] = :id
SQL,
            [
                ':name' => $def['name'],
                ':description' => $def['description'],
                ':groupId' => $def['groupId'],
                ':dataType' => $def['dataType'],
                ':scope' => $def['scope'],
                ':displayOrder' => $def['order'],
                ':rules' => $publicRules,
                ':actor' => $actorUserId,
                ':id' => $id,
            ],
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
    (:code, :name, :description, :groupId, :dataType, :scope, NULL,
     :displayOrder, 0, 0, 1, 0, NULL, NULL,
     NULL, :rules, 1, :createdBy, :updatedBy)
SQL,
        [
            ':code' => $def['code'],
            ':name' => $def['name'],
            ':description' => $def['description'],
            ':groupId' => $def['groupId'],
            ':dataType' => $def['dataType'],
            ':scope' => $def['scope'],
            ':displayOrder' => $def['order'],
            ':rules' => $publicRules,
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );
    $id = (int) $rows[0]['SpecificationDefinitionId'];
    echo "Created definition {$def['code']}\n";

    return $id;
}

function ensureOption(
    SqlConnection $db,
    int $definitionId,
    string $code,
    string $label,
    int $displayOrder,
    int $actorUserId,
): int {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationOptionId]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [OptionCode] = :code
  AND [DeletedAtUtc] IS NULL
SQL,
        [
            ':definitionId' => $definitionId,
            ':code' => $code,
        ],
    );
    if ($existing !== []) {
        $id = (int) $existing[0]['SpecificationOptionId'];
        $db->execute(
            <<<'SQL'
UPDATE [product].[SpecificationOptions]
SET [OptionLabel] = :label, [DisplayOrder] = :displayOrder, [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
WHERE [SpecificationOptionId] = :id
SQL,
            [
                ':label' => $label,
                ':displayOrder' => $displayOrder,
                ':actor' => $actorUserId,
                ':id' => $id,
            ],
        );

        return $id;
    }

    $rows = $db->fetchAll(
        <<<'SQL'
INSERT INTO [product].[SpecificationOptions]
    ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationOptionId]
VALUES (:definitionId, :code, :label, :optionValue, :displayOrder, 1, :createdBy, :updatedBy)
SQL,
        [
            ':definitionId' => $definitionId,
            ':code' => $code,
            ':label' => $label,
            ':optionValue' => $label,
            ':displayOrder' => $displayOrder,
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );

    return (int) $rows[0]['SpecificationOptionId'];
}

function upsertTextValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    string $value,
    string $dataType,
    int $actorUserId,
): int {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [
            ':productId' => $productId,
            ':definitionId' => $definitionId,
        ],
    );

    $valueText = in_array($dataType, ['text'], true) ? $value : null;
    $valueLongText = in_array($dataType, ['long-text'], true) ? $value : null;
    if ($dataType === 'text' && strlen($value) > 400) {
        $valueText = null;
        $valueLongText = $value;
    }

    if ($existing === []) {
        $rows = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueText], [ValueLongText], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[ProductSpecificationValueId]
VALUES (:productId, :definitionId, :valueText, :valueLongText, :createdBy, :updatedBy)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':valueText' => $valueText,
                ':valueLongText' => $valueLongText,
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );

        return (int) $rows[0]['ProductSpecificationValueId'];
    }

    $valueId = (int) $existing[0]['ProductSpecificationValueId'];
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET
    [ValueText] = :valueText,
    [ValueLongText] = :valueLongText,
    [SpecificationOptionId] = NULL,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor,
    [DeletedAtUtc] = NULL
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':valueText' => $valueText,
            ':valueLongText' => $valueLongText,
            ':actor' => $actorUserId,
            ':valueId' => $valueId,
        ],
    );

    return $valueId;
}

function upsertMultiSelectValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    array $optionIds,
    int $actorUserId,
): void {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [
            ':productId' => $productId,
            ':definitionId' => $definitionId,
        ],
    );

    if ($existing === []) {
        $rows = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[ProductSpecificationValueId]
VALUES (:productId, :definitionId, :createdBy, :updatedBy)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );
        $valueId = (int) $rows[0]['ProductSpecificationValueId'];
    } else {
        $valueId = (int) $existing[0]['ProductSpecificationValueId'];
        $db->execute(
            'UPDATE [product].[ProductSpecificationValues] SET [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor WHERE [ProductSpecificationValueId] = :valueId',
            [':actor' => $actorUserId, ':valueId' => $valueId],
        );
        $db->execute(
            'DELETE FROM [product].[ProductSpecificationOptionValues] WHERE [ProductSpecificationValueId] = :valueId',
            [':valueId' => $valueId],
        );
    }

    foreach ($optionIds as $optionId) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationOptionValues]
    ([ProductSpecificationValueId], [SpecificationOptionId])
VALUES (:valueId, :optionId)
SQL,
            [
                ':valueId' => $valueId,
                ':optionId' => $optionId,
            ],
        );
    }
}

// --- definitions ---
$definitionIds = [];
foreach ($definitions as $def) {
    $definitionIds[$def['code']] = ensureDefinition($db, $def, $publicRules, $actorUserId);
}

// --- approvals options ---
$approvalOptionIds = [];
foreach ($approvalOptions as [$code, $label, $order]) {
    $approvalOptionIds[] = ensureOption($db, $definitionIds['APPROVALS'], $code, $label, $order, $actorUserId);
}
echo 'Approvals options: ' . count($approvalOptionIds) . "\n";

// --- extra standard options ---
$standardDef = $db->fetchAll(
    'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = N\'STANDARD\' AND [DeletedAtUtc] IS NULL',
);
if ($standardDef === []) {
    fwrite(STDERR, "STANDARD definition missing\n");
    exit(1);
}
$standardDefinitionId = (int) $standardDef[0]['SpecificationDefinitionId'];
foreach ($extraStandardOptions as [$code, $label, $order]) {
    ensureOption($db, $standardDefinitionId, $code, $label, $order, $actorUserId);
}

$standardOptionRows = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationOptionId], [OptionLabel]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL,
    [':definitionId' => $standardDefinitionId],
);
$standardOptionByLabel = [];
foreach ($standardOptionRows as $row) {
    $standardOptionByLabel[(string) $row['OptionLabel']] = (int) $row['SpecificationOptionId'];
}
$standardOptionIds = [];
foreach ($standardLabelsToAssign as $label) {
    if (!isset($standardOptionByLabel[$label])) {
        echo "WARN missing STANDARD option: {$label}\n";
        continue;
    }
    $standardOptionIds[] = $standardOptionByLabel[$label];
}

// jacket colour black option
$blackOption = $db->fetchAll(
    <<<'SQL'
SELECT o.[SpecificationOptionId], d.[SpecificationDefinitionId]
FROM [product].[SpecificationOptions] o
JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
WHERE d.[DefinitionCode] = N'DEFAULT-COLOUR'
  AND o.[OptionCode] = N'BK'
  AND o.[DeletedAtUtc] IS NULL
SQL,
);

$products = $db->fetchAll(
    <<<'SQL'
SELECT [ProductId], [InternalCode]
FROM [product].[Products]
WHERE [InternalCode] IN ('BHA', 'BHC', 'BHX')
  AND [DeletedAtUtc] IS NULL
SQL,
);

foreach ($products as $product) {
    $productId = (int) $product['ProductId'];
    $code = (string) $product['InternalCode'];
    echo "Seeding product {$code} ({$productId})\n";

    foreach ($constructionValues as $defCode => $value) {
        $dataType = 'text';
        foreach ($definitions as $def) {
            if ($def['code'] === $defCode) {
                $dataType = $def['dataType'];
                break;
            }
        }
        upsertTextValue($db, $productId, $definitionIds[$defCode], $value, $dataType, $actorUserId);
    }

    upsertMultiSelectValue($db, $productId, $definitionIds['APPROVALS'], $approvalOptionIds, $actorUserId);
    upsertMultiSelectValue($db, $productId, $standardDefinitionId, $standardOptionIds, $actorUserId);

    // Voltage + temp for products missing complete values
    $voltageDef = $db->fetchAll("SELECT SpecificationDefinitionId FROM product.SpecificationDefinitions WHERE DefinitionCode=N'VOLTAGE' AND DeletedAtUtc IS NULL");
    if ($voltageDef !== []) {
        upsertTextValue($db, $productId, (int) $voltageDef[0]['SpecificationDefinitionId'], '600/1000 Volts', 'text', $actorUserId);
    }

    $tempDef = $db->fetchAll("SELECT SpecificationDefinitionId FROM product.SpecificationDefinitions WHERE DefinitionCode=N'TEMP-RANGE' AND DeletedAtUtc IS NULL");
    if ($tempDef !== []) {
        $tempDefinitionId = (int) $tempDef[0]['SpecificationDefinitionId'];
        $existingTemp = $db->fetchAll(
            'SELECT ProductSpecificationValueId FROM product.ProductSpecificationValues WHERE ProductId=:pid AND SpecificationDefinitionId=:did AND DeletedAtUtc IS NULL',
            [':pid' => $productId, ':did' => $tempDefinitionId],
        );
        if ($existingTemp === []) {
            $db->execute(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueMinDecimal], [ValueMaxDecimal], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, -40, 110, :actor, :actor2)
SQL,
                [
                    ':productId' => $productId,
                    ':definitionId' => $tempDefinitionId,
                    ':actor' => $actorUserId,
                    ':actor2' => $actorUserId,
                ],
            );
        } else {
            $db->execute(
                <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [ValueMinDecimal] = -40, [ValueMaxDecimal] = 110,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
                [
                    ':actor' => $actorUserId,
                    ':valueId' => (int) $existingTemp[0]['ProductSpecificationValueId'],
                ],
            );
        }
    }

    if ($blackOption !== []) {
        $colourDefinitionId = (int) $blackOption[0]['SpecificationDefinitionId'];
        $colourOptionId = (int) $blackOption[0]['SpecificationOptionId'];
        $existingColour = $db->fetchAll(
            'SELECT ProductSpecificationValueId FROM product.ProductSpecificationValues WHERE ProductId=:pid AND SpecificationDefinitionId=:did AND DeletedAtUtc IS NULL',
            [':pid' => $productId, ':did' => $colourDefinitionId],
        );
        if ($existingColour === []) {
            $db->execute(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [SpecificationOptionId], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :optionId, :actor, :actor2)
SQL,
                [
                    ':productId' => $productId,
                    ':definitionId' => $colourDefinitionId,
                    ':optionId' => $colourOptionId,
                    ':actor' => $actorUserId,
                    ':actor2' => $actorUserId,
                ],
            );
        } else {
            $db->execute(
                <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [SpecificationOptionId] = :optionId, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
                [
                    ':optionId' => $colourOptionId,
                    ':actor' => $actorUserId,
                    ':valueId' => (int) $existingColour[0]['ProductSpecificationValueId'],
                ],
            );
        }
    }
}

echo "Done.\n";
