<?php

declare(strict_types=1);

/**
 * Seed TriCab Flexible Control (BVA) catalogue datasheet values:
 * Typical Applications (full description), Core Colours, Construction,
 * Jacket Options, Voltage / Temp / Jacket Colour, Standards, Approvals.
 *
 * Safe to re-run (upserts by definition code / product+definition).
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
$internalCode = 'BVA';

$publicRules = json_encode([
    'isPublicVisible' => true,
    'isAdminOnly' => false,
], JSON_THROW_ON_ERROR);

$fullDescription = 'Flexible Power and Control cables suitable for all shipboard and marine applications.';
$shortDescription = 'Shipboard Power & Control';

$coreColours = <<<'TEXT'
CENELEC HD 308S2 and IEC 60445
1C: BK
2C: BR BL
3C: BR BK GY
4C: BR BK GY BL
5C: BR BK GY BL BK
>5C: Black numbers on white insulation

AS/NZS 5000.1
1C: WH
2C: RE BK
2C+E: RE BK EA
3C+E: RE WH BL EA
4C+E: RE WH BL BK EA
>5C: Black numbers on white insulation + E

Other core colours available on request.
TEXT;

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
        'code' => 'CORE-INSULATION',
        'name' => 'Core Insulation',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 14,
        'description' => 'Catalogue construction — core insulation',
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
        'code' => 'CORE-COLOURS',
        'name' => 'Core Colours',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 40,
        'description' => 'Catalogue core colour chart',
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

$textValues = [
    'CONDUCTORS' => 'Finely stranded annealed copper to IEC 60228 & AS/NZS 1125 as standard. Available in plain and tinned.',
    'CORE-INSULATION' => '‘Easy Strip’ LSZH HEPR to IEC 60092-360.',
    'JACKET' => "Flame Retardant ‘Easy Strip’ Low Friction LSZH. Splash resistant to oil, skydrol, petrol, acid, sea water. Resists ozone and UV.",
    'JACKET-OPTION-A' => 'SHF 1 to IEC 60092-360 Thermoplastic.',
    'JACKET-OPTION-C' => 'SHF 2 to IEC 60092-360 Thermoset, Oil Resistant.',
    'JACKET-OPTION-M' => 'SHF MUD to NEK TS 606 Thermoset, Oil & Mud Resistant.',
    'CORE-COLOURS' => $coreColours,
    'APPROVAL-NOTE' => 'Cable constructions, Jackets, and Inner components tested to UL 1309 - CSA C22.2 No. 245 for Cold Bend (-40°C) and Cold Impact (-35°C).',
    'VOLTAGE' => '600/1000 Volts',
];

$approvalOptions = [
    ['ABS', 'ABS', 10],
    ['BV', 'BV', 20],
    ['DNV-GL', 'DNV-GL', 30],
    ['USCG', 'USCG', 40],
    ['TC-MARINE', 'TC Transport Canada Marine Safety', 50],
];

$extraStandardOptions = [
    ['IEC-60332-1', 'IEC 60332-1', 180],
    ['IEEE-45', 'IEEE 45', 200],
    ['IEEE-1580', 'IEEE 1580', 210],
    ['IEEE-1202', 'IEEE 1202', 220],
    ['AS-NZS-1125', 'AS/NZS 1125', 225],
    ['AS-NZS-1660-5-1', 'AS/NZS 1660.5.1', 230],
    ['AS-NZS-1660-5-2', 'AS/NZS 1660.5.2', 240],
    ['AS-NZS-1660-5-3', 'AS/NZS 1660.5.3', 250],
    ['AS-NZS-1660-5-6', 'AS/NZS 1660.5.6', 260],
];

$standardLabelsToAssign = [
    'IEC 60228',
    'IEC 60092-350',
    'IEC 60092-353',
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

function requireDefinitionId(SqlConnection $db, string $code): int
{
    $rows = $db->fetchAll(
        'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL',
        [':code' => $code],
    );
    if ($rows === []) {
        throw new RuntimeException("Missing definition {$code}");
    }

    return (int) $rows[0]['SpecificationDefinitionId'];
}

// --- product ---
$products = $db->fetchAll(
    <<<'SQL'
SELECT [ProductId], [InternalCode], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[Products]
WHERE [InternalCode] = :code
  AND [DeletedAtUtc] IS NULL
SQL,
    [':code' => $internalCode],
);
if ($products === []) {
    fwrite(STDERR, "Product InternalCode {$internalCode} not found.\n");
    exit(1);
}
$productId = (int) $products[0]['ProductId'];
$publicId = (string) $products[0]['PublicId'];
echo "Seeding {$internalCode} productId={$productId} publicId={$publicId}\n";

$db->execute(
    <<<'SQL'
UPDATE [product].[Products]
SET
    [ShortDescription] = :shortDescription,
    [FullDescription] = :fullDescription,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductId] = :productId
SQL,
    [
        ':shortDescription' => $shortDescription,
        ':fullDescription' => $fullDescription,
        ':actor' => $actorUserId,
        ':productId' => $productId,
    ],
);
echo "Updated product descriptions\n";

// --- definitions ---
$definitionIds = [];
foreach ($definitions as $def) {
    $definitionIds[$def['code']] = ensureDefinition($db, $def, $publicRules, $actorUserId);
}
$definitionIds['VOLTAGE'] = requireDefinitionId($db, 'VOLTAGE');
$definitionIds['TEMP-RANGE'] = requireDefinitionId($db, 'TEMP-RANGE');
$definitionIds['DEFAULT-COLOUR'] = requireDefinitionId($db, 'DEFAULT-COLOUR');
$definitionIds['STANDARD'] = requireDefinitionId($db, 'STANDARD');

// --- approvals options ---
$approvalOptionIds = [];
foreach ($approvalOptions as [$code, $label, $order]) {
    $approvalOptionIds[] = ensureOption($db, $definitionIds['APPROVALS'], $code, $label, $order, $actorUserId);
}

foreach ($extraStandardOptions as [$code, $label, $order]) {
    ensureOption($db, $definitionIds['STANDARD'], $code, $label, $order, $actorUserId);
}

$standardOptionRows = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationOptionId], [OptionLabel]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL,
    [':definitionId' => $definitionIds['STANDARD']],
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

$blackOption = $db->fetchAll(
    <<<'SQL'
SELECT o.[SpecificationOptionId]
FROM [product].[SpecificationOptions] o
WHERE o.[SpecificationDefinitionId] = :definitionId
  AND o.[OptionCode] = N'BK'
  AND o.[DeletedAtUtc] IS NULL
SQL,
    [':definitionId' => $definitionIds['DEFAULT-COLOUR']],
);

foreach ($textValues as $defCode => $value) {
    $dataType = 'text';
    foreach ($definitions as $def) {
        if ($def['code'] === $defCode) {
            $dataType = $def['dataType'];
            break;
        }
    }
    if ($defCode === 'VOLTAGE') {
        $dataType = 'text';
    }
    upsertTextValue($db, $productId, $definitionIds[$defCode], $value, $dataType, $actorUserId);
    echo "Upserted {$defCode}\n";
}

upsertMultiSelectValue($db, $productId, $definitionIds['APPROVALS'], $approvalOptionIds, $actorUserId);
echo 'Upserted APPROVALS (' . count($approvalOptionIds) . " options)\n";
upsertMultiSelectValue($db, $productId, $definitionIds['STANDARD'], $standardOptionIds, $actorUserId);
echo 'Upserted STANDARD (' . count($standardOptionIds) . " options)\n";

$existingTemp = $db->fetchAll(
    'SELECT ProductSpecificationValueId FROM product.ProductSpecificationValues WHERE ProductId=:pid AND SpecificationDefinitionId=:did AND DeletedAtUtc IS NULL',
    [':pid' => $productId, ':did' => $definitionIds['TEMP-RANGE']],
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
            ':definitionId' => $definitionIds['TEMP-RANGE'],
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
echo "Upserted TEMP-RANGE -40..110\n";

if ($blackOption !== []) {
    $colourOptionId = (int) $blackOption[0]['SpecificationOptionId'];
    $existingColour = $db->fetchAll(
        'SELECT ProductSpecificationValueId FROM product.ProductSpecificationValues WHERE ProductId=:pid AND SpecificationDefinitionId=:did AND DeletedAtUtc IS NULL',
        [':pid' => $productId, ':did' => $definitionIds['DEFAULT-COLOUR']],
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
                ':definitionId' => $definitionIds['DEFAULT-COLOUR'],
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
    echo "Upserted DEFAULT-COLOUR Black\n";
} else {
    echo "WARN: DEFAULT-COLOUR option BK missing\n";
}

echo "Done.\n";
