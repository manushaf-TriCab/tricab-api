<?php

declare(strict_types=1);

/**
 * Enrich existing R2–R8 instrumentation products from datasheets:
 * Typical Applications, construction, temp, voltage, standards,
 * jacket colour, PAIR/TRIAD core colours.
 *
 * Does NOT create products, variants, or media.
 * Does NOT invent variant-matrix values.
 * Creates SpecificationDefinitions only when genuinely missing:
 *   INDIVIDUAL-SCREEN, ARMOUR
 * Creates SpecificationOptions only when genuinely missing (none expected).
 *
 * Default: dry-run. Pass --apply to write.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__, 3);
$settings = Environment::settings($root);
$apply = in_array('--apply', $argv, true);

echo "APP_ENV={$settings->appEnv}\n";
echo "DB_HOST={$settings->dbHost}\n";
echo "DB_DATABASE={$settings->dbDatabase}\n";
echo 'isProduction=' . ($settings->appEnv === 'production' ? 'YES' : 'NO') . "\n";
echo 'mode=' . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$host = strtolower((string) $settings->dbHost);
$database = strtolower((string) $settings->dbDatabase);
if (!str_contains($host, 'tricab-mel') || $database !== 'tc') {
    fwrite(STDERR, "STOP: unexpected non-production target host={$settings->dbHost} database={$settings->dbDatabase}\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$identity = $db->fetchAll('SELECT @@SERVERNAME AS [ServerName], DB_NAME() AS [DbName]');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
$dbName = (string) ($identity[0]['DbName'] ?? '');
echo "@@SERVERNAME={$serverName}\n";
echo "DB_NAME()={$dbName}\n";

if (!str_contains(strtolower($serverName), 'tricab-mel') || strtolower($dbName) !== 'tc') {
    fwrite(STDERR, "STOP: live identity is not tricab-mel / tc\n");
    exit(1);
}

$actorUserId = 1;
$constructionGroupId = 3;
$targetCodes = ['R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8'];

$typicalApplications = 'Flexible instrumentation cable suitable for wiring of monitoring, control and measuring equipment in fixed installation.';

$coreColours = <<<'TEXT'
PAIR: WH BK
TRIAD: WH BK RE

Other core colours available on request.
TEXT;

$conductor = 'Fine wire plain or tinned annealed copper to IEC 60228 and AS/NZS 1125.';
$screenTape = 'Aluminium Laminate Tape with Drain Wire';
$separator = 'Polypropylene Tape';
$bedding = 'S-20, Thermoplastic, Elastomeric';
$armour = 'Galvanised Steel Wire';

$jacketRe110 = 'Low-Friction S-20 Thermoplastic, Elastomeric, Flame Retardant (Cat A). Splash resistant to oil, Skydrol, petrol, acid and sea water. Resists ozone and UV. Excellent abrasion resistance. Anti-Termite/Rodent Jacket is Available.';
$jacketLszh = 'Low Friction LSFLEX T-10 (HFS-110-TP) Flame Retardant (Cat A), Low Smoke Zero Halogen. Splash resistant to oil, Skydrol, petrol, acid and sea water. Resists ozone and UV. Excellent abrasion resistance. Anti-Termite/Rodent Jacket is Available.';
$insulationRe110 = 'Elastomeric R-E-110';
$insulationLszh = 'LSFLEX X-125 (X-HF-110) Cross-Linked, Thermoset, Elastomeric, Low Smoke Zero Halogen';

$standardsRe110 = [
    'AS-NZS-1125',
    'AS-NZS-1660-5-1',
    'AS-NZS-1660-5-6',
    'AS-NZS-3808',
    'IEC-60228',
    'IEC-60332-1',
];
$standardsR4 = array_values(array_unique(array_merge($standardsRe110, ['IEC-60332-3-22'])));
$standardsLszh = [
    'AS-NZS-1125',
    'AS-NZS-1660-5-1',
    'AS-NZS-1660-5-2',
    'AS-NZS-1660-5-4',
    'AS-NZS-1660-5-6',
    'AS-NZS-3808',
    'IEC-60228',
    'IEC-60332-1',
    'IEC-60332-3-22',
    'IEC-60754-2',
    'IEC-61034-1_2', // datasheet IEC 61034-1&2
];

$textTypes = [
    'CONDUCTORS' => 'long-text',
    'CORE-INSULATION' => 'long-text',
    'INDIVIDUAL-SCREEN' => 'long-text',
    'SCREEN' => 'long-text',
    'SEPARATOR' => 'text',
    'SEPARATOR-OUTER' => 'text',
    'BEDDING' => 'long-text',
    'ARMOUR' => 'long-text',
    'JACKET' => 'long-text',
    'CORE-COLOURS' => 'long-text',
    'VOLTAGE' => 'text',
];

/**
 * Per-product datasheet plan.
 *
 * @var array<string, array{
 *   name:string,
 *   insulation:string,
 *   jacket:string,
 *   tempMin:float,
 *   tempMax:float,
 *   standards:list<string>,
 *   hasIndividualScreen:bool,
 *   hasOverallScreen:bool,
 *   hasInnerSeparator:bool,
 *   hasOuterSeparator:bool,
 *   hasBedding:bool,
 *   hasArmour:bool
 * }>
 */
$plans = [
    'R2' => [
        'name' => 'Flexible Instrumentation SWA Coll. Scr.',
        'insulation' => $insulationRe110,
        'jacket' => $jacketRe110,
        'tempMin' => -15.0,
        'tempMax' => 110.0,
        'standards' => $standardsRe110,
        'hasIndividualScreen' => false,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => true,
        'hasArmour' => true,
    ],
    'R3' => [
        'name' => 'Flexible Instrumentation Individual & Coll. Scr.',
        'insulation' => $insulationRe110,
        'jacket' => $jacketRe110,
        'tempMin' => -15.0,
        'tempMax' => 110.0,
        'standards' => $standardsRe110,
        'hasIndividualScreen' => true,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => false,
        'hasArmour' => false,
    ],
    'R4' => [
        'name' => 'Flexible Instrumentation SWA Individual & Coll. Scr.',
        'insulation' => $insulationRe110,
        'jacket' => $jacketRe110,
        'tempMin' => -15.0,
        'tempMax' => 110.0,
        'standards' => $standardsR4,
        'hasIndividualScreen' => true,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => true,
        'hasArmour' => true,
    ],
    'R5' => [
        'name' => 'Flexible LSZH Instrumentation Coll. Scr.',
        'insulation' => $insulationLszh,
        'jacket' => $jacketLszh,
        'tempMin' => -40.0,
        'tempMax' => 125.0,
        'standards' => $standardsLszh,
        'hasIndividualScreen' => false,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => false,
        'hasBedding' => false,
        'hasArmour' => false,
    ],
    'R6' => [
        'name' => 'Flexible LSZH Instrumentation SWA Coll. Scr.',
        'insulation' => $insulationLszh,
        'jacket' => $jacketLszh,
        'tempMin' => -40.0,
        'tempMax' => 125.0,
        'standards' => $standardsLszh,
        'hasIndividualScreen' => false,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => true,
        'hasArmour' => true,
    ],
    'R7' => [
        'name' => 'Flexible LSZH Instrumentation Individual & Coll. Scr.',
        'insulation' => $insulationLszh,
        'jacket' => $jacketLszh,
        'tempMin' => -40.0,
        'tempMax' => 125.0,
        'standards' => $standardsLszh,
        'hasIndividualScreen' => true,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => false,
        'hasArmour' => false,
    ],
    'R8' => [
        'name' => 'Flexible LSZH Instrumentation SWA Individual & Coll. Scr.',
        'insulation' => $insulationLszh,
        'jacket' => $jacketLszh,
        'tempMin' => -40.0,
        'tempMax' => 125.0,
        'standards' => $standardsLszh,
        'hasIndividualScreen' => true,
        'hasOverallScreen' => true,
        'hasInnerSeparator' => true,
        'hasOuterSeparator' => true,
        'hasBedding' => true,
        'hasArmour' => true,
    ],
];

$publicRules = json_encode([
    'isPublicVisible' => true,
    'isAdminOnly' => false,
], JSON_THROW_ON_ERROR);

$definitionsToCreate = [
    [
        'code' => 'INDIVIDUAL-SCREEN',
        'name' => 'Individual Screen',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 15,
        'description' => 'Catalogue construction — individual pair/triad or individual screen',
    ],
    [
        'code' => 'ARMOUR',
        'name' => 'Armour',
        'groupId' => $constructionGroupId,
        'dataType' => 'long-text',
        'scope' => 'product',
        'order' => 19,
        'description' => 'Catalogue construction — armour (e.g. galvanised steel wire)',
    ],
];

function findDefinition(SqlConnection $db, string $code): ?array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationDefinitionId], [DefinitionCode], [DefinitionName], [DataTypeCode], [ScopeCode]
FROM [product].[SpecificationDefinitions]
WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );

    return $rows[0] ?? null;
}

function requireDefinition(SqlConnection $db, string $code): array
{
    $row = findDefinition($db, $code);
    if ($row === null) {
        throw new RuntimeException("MISSING_DEFINITION {$code}");
    }

    return $row;
}

function ensureDefinition(SqlConnection $db, array $def, string $publicRules, int $actorUserId): array
{
    $existing = findDefinition($db, $def['code']);
    if ($existing !== null) {
        return ['id' => (int) $existing['SpecificationDefinitionId'], 'created' => false, 'row' => $existing];
    }

    $rows = $db->fetchAll(
        <<<'SQL'
INSERT INTO [product].[SpecificationDefinitions]
    ([DefinitionCode], [DefinitionName], [Description], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit],
     [DisplayOrder], [IsRequired], [IsFilterable], [IsSearchable], [IsComparable], [MinimumValue], [MaximumValue],
     [DecimalPrecision], [ValidationRulesJson], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationDefinitionId], INSERTED.[DefinitionCode], INSERTED.[DefinitionName],
       INSERTED.[DataTypeCode], INSERTED.[ScopeCode]
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

    return [
        'id' => (int) $rows[0]['SpecificationDefinitionId'],
        'created' => true,
        'row' => $rows[0],
    ];
}

function requireOptionByCode(SqlConnection $db, int $definitionId, string $code): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationOptionId], [OptionCode], [OptionLabel]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [OptionCode] = :code
  AND [DeletedAtUtc] IS NULL
SQL,
        [':definitionId' => $definitionId, ':code' => $code],
    );
    if ($rows === []) {
        throw new RuntimeException("MISSING_OPTION {$code}");
    }

    return $rows[0];
}

function currentTextValue(SqlConnection $db, int $productId, int $definitionId): ?string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [ValueText], [ValueLongText]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($rows === []) {
        return null;
    }

    return trim((string) ($rows[0]['ValueLongText'] ?? $rows[0]['ValueText'] ?? ''));
}

function currentRange(SqlConnection $db, int $productId, int $definitionId): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [ValueMinDecimal], [ValueMaxDecimal]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($rows === []) {
        return ['exists' => false, 'min' => null, 'max' => null];
    }
    $min = $rows[0]['ValueMinDecimal'];
    $max = $rows[0]['ValueMaxDecimal'];

    return [
        'exists' => true,
        'min' => $min !== null && $min !== '' ? (float) $min : null,
        'max' => $max !== null && $max !== '' ? (float) $max : null,
    ];
}

function currentSelectCode(SqlConnection $db, int $productId, int $definitionId): ?string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT o.[OptionCode]
FROM [product].[ProductSpecificationValues] psv
LEFT JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = psv.[SpecificationOptionId]
WHERE psv.[ProductId] = :productId
  AND psv.[SpecificationDefinitionId] = :definitionId
  AND psv.[DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($rows === []) {
        return null;
    }
    $code = trim((string) ($rows[0]['OptionCode'] ?? ''));

    return $code === '' ? '' : $code;
}

/** @return list<string> */
function currentMultiSelectCodes(SqlConnection $db, int $productId, int $definitionId): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT o.[OptionCode]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[ProductSpecificationOptionValues] pov
    ON pov.[ProductSpecificationValueId] = psv.[ProductSpecificationValueId]
INNER JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = pov.[SpecificationOptionId]
WHERE psv.[ProductId] = :productId
  AND psv.[SpecificationDefinitionId] = :definitionId
  AND psv.[DeletedAtUtc] IS NULL
  AND o.[DeletedAtUtc] IS NULL
ORDER BY o.[DisplayOrder], o.[OptionCode]
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    $codes = [];
    foreach ($rows as $row) {
        $codes[] = (string) $row['OptionCode'];
    }

    return $codes;
}

function upsertTextValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    string $value,
    string $dataType,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [ValueText], [ValueLongText]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );

    $valueText = in_array($dataType, ['text'], true) ? $value : null;
    $valueLongText = in_array($dataType, ['long-text'], true) ? $value : null;
    if ($dataType === 'text' && strlen($value) > 400) {
        $valueText = null;
        $valueLongText = $value;
    }

    if ($existing !== []) {
        $current = trim((string) ($existing[0]['ValueLongText'] ?? $existing[0]['ValueText'] ?? ''));
        if ($current === $value) {
            return 'KEEP';
        }
    }

    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueText], [ValueLongText], [CreatedByUserId], [UpdatedByUserId])
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

        return 'ADD';
    }

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
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

function upsertSelectOption(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    int $optionId,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [SpecificationOptionId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [SpecificationOptionId], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :optionId, :actor, :actor2)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':optionId' => $optionId,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    if ((int) $existing[0]['SpecificationOptionId'] === $optionId) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [SpecificationOptionId] = :optionId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':optionId' => $optionId,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

function upsertRange(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    float $min,
    float $max,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId], [ValueMinDecimal], [ValueMaxDecimal]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );
    if ($existing === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [ValueMinDecimal], [ValueMaxDecimal], [CreatedByUserId], [UpdatedByUserId])
VALUES (:productId, :definitionId, :minVal, :maxVal, :actor, :actor2)
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $definitionId,
                ':minVal' => $min,
                ':maxVal' => $max,
                ':actor' => $actorUserId,
                ':actor2' => $actorUserId,
            ],
        );

        return 'ADD';
    }
    $curMin = $existing[0]['ValueMinDecimal'] !== null && $existing[0]['ValueMinDecimal'] !== ''
        ? (float) $existing[0]['ValueMinDecimal']
        : null;
    $curMax = $existing[0]['ValueMaxDecimal'] !== null && $existing[0]['ValueMaxDecimal'] !== ''
        ? (float) $existing[0]['ValueMaxDecimal']
        : null;
    if ($curMin === $min && $curMax === $max) {
        return 'KEEP';
    }
    $db->execute(
        <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [ValueMinDecimal] = :minVal, [ValueMaxDecimal] = :maxVal,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor
WHERE [ProductSpecificationValueId] = :valueId
SQL,
        [
            ':minVal' => $min,
            ':maxVal' => $max,
            ':actor' => $actorUserId,
            ':valueId' => (int) $existing[0]['ProductSpecificationValueId'],
        ],
    );

    return 'UPDATE';
}

/** @param list<int> $optionIds */
function upsertMultiSelectValue(
    SqlConnection $db,
    int $productId,
    int $definitionId,
    array $optionIds,
    int $actorUserId,
): string {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
        [':productId' => $productId, ':definitionId' => $definitionId],
    );

    $action = 'KEEP';
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
        $action = 'ADD';
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
        $action = 'UPDATE';
    }

    foreach ($optionIds as $optionId) {
        $db->execute(
            <<<'SQL'
INSERT INTO [product].[ProductSpecificationOptionValues]
    ([ProductSpecificationValueId], [SpecificationOptionId])
VALUES (:valueId, :optionId)
SQL,
            [':valueId' => $valueId, ':optionId' => $optionId],
        );
    }

    return $action;
}

function productFingerprint(SqlConnection $db, int $productId): array
{
    $variant = $db->fetchAll(
        <<<'SQL'
SELECT COUNT(*) AS [N],
       CHECKSUM_AGG(CHECKSUM(CONVERT(NVARCHAR(36), [PublicId]), ISNULL([Sku], N''), ISNULL([VariantName], N''), [DisplayOrder])) AS [Hash]
FROM [product].[ProductVariants]
WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL
SQL,
        [':id' => $productId],
    );
    $media = $db->fetchAll(
        <<<'SQL'
SELECT COUNT(*) AS [N],
       CHECKSUM_AGG(CHECKSUM(CONVERT(NVARCHAR(36), [PublicId]), [ImageRoleCode], [PublicUrl], [DisplayOrder])) AS [Hash]
FROM [product].[ProductImages]
WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL
SQL,
        [':id' => $productId],
    );

    return [
        'variants' => (int) ($variant[0]['N'] ?? 0),
        'variantHash' => (string) ($variant[0]['Hash'] ?? ''),
        'media' => (int) ($media[0]['N'] ?? 0),
        'mediaHash' => (string) ($media[0]['Hash'] ?? ''),
    ];
}

function globalFingerprint(SqlConnection $db, array $excludeProductIds): array
{
    $products = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL');
    $defs = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[SpecificationDefinitions] WHERE [DeletedAtUtc] IS NULL');
    $opts = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[SpecificationOptions] WHERE [DeletedAtUtc] IS NULL');

    $otherSpecsSql = 'SELECT COUNT(*) AS [N] FROM [product].[ProductSpecificationValues] WHERE [DeletedAtUtc] IS NULL';
    $params = [];
    if ($excludeProductIds !== []) {
        $parts = [];
        foreach (array_values($excludeProductIds) as $i => $id) {
            $key = ':p' . $i;
            $parts[] = $key;
            $params[$key] = $id;
        }
        $otherSpecsSql .= ' AND [ProductId] NOT IN (' . implode(',', $parts) . ')';
    }
    $otherSpecs = $db->fetchAll($otherSpecsSql, $params);

    $r1 = $db->fetchAll(
        <<<'SQL'
SELECT COUNT(*) AS [N],
       CHECKSUM_AGG(CHECKSUM(sd.[DefinitionCode], ISNULL(psv.[ValueText], N''), ISNULL(psv.[ValueLongText], N''),
         ISNULL(CONVERT(NVARCHAR(50), psv.[ValueMinDecimal]), N''), ISNULL(CONVERT(NVARCHAR(50), psv.[ValueMaxDecimal]), N''),
         ISNULL(CONVERT(NVARCHAR(20), psv.[SpecificationOptionId]), N''))) AS [Hash]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[Products] p ON p.[ProductId] = psv.[ProductId]
INNER JOIN [product].[SpecificationDefinitions] sd ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
WHERE p.[InternalCode] = N'R1' AND psv.[DeletedAtUtc] IS NULL AND p.[DeletedAtUtc] IS NULL
SQL,
    );

    return [
        'products' => (int) ($products[0]['N'] ?? 0),
        'definitions' => (int) ($defs[0]['N'] ?? 0),
        'options' => (int) ($opts[0]['N'] ?? 0),
        'otherSpecs' => (int) ($otherSpecs[0]['N'] ?? 0),
        'r1Specs' => (int) ($r1[0]['N'] ?? 0),
        'r1Hash' => (string) ($r1[0]['Hash'] ?? ''),
    ];
}

// --- resolve products ---
$resolved = [];
echo "\n=== PRODUCT RESOLUTION ===\n";
foreach ($targetCodes as $code) {
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[InternalCode],
    p.[ProductName],
    p.[FullDescription],
    pls.[LifecycleStatusCode],
    ps.[StatusCode] AS [ProductStatusCode],
    st.[StyleCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
LEFT JOIN [product].[ProductStyleAssignments] psa ON psa.[ProductId] = p.[ProductId]
LEFT JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = psa.[ProductStyleId]
WHERE p.[DeletedAtUtc] IS NULL
  AND (p.[InternalCode] = :code OR st.[StyleCode] = :style)
SQL,
        [':code' => $code, ':style' => $code],
    );
    if (count($rows) !== 1) {
        fwrite(STDERR, "STOP: {$code} expected 1 product, found " . count($rows) . "\n");
        exit(1);
    }
    $row = $rows[0];
    if (strcasecmp((string) $row['InternalCode'], $code) !== 0) {
        fwrite(STDERR, "STOP: {$code} match is not InternalCode {$code}\n");
        exit(1);
    }
    $expectedName = $plans[$code]['name'];
    if (strcasecmp(trim((string) $row['ProductName']), $expectedName) !== 0) {
        fwrite(STDERR, "STOP: {$code} name mismatch got={$row['ProductName']} expected={$expectedName}\n");
        exit(1);
    }
    $fp = productFingerprint($db, (int) $row['ProductId']);
    $specCount = $db->fetchAll(
        'SELECT COUNT(*) AS [N] FROM [product].[ProductSpecificationValues] WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL',
        [':id' => (int) $row['ProductId']],
    );
    $resolved[$code] = [
        'product' => $row,
        'productId' => (int) $row['ProductId'],
        'publicId' => (string) $row['PublicId'],
        'before' => $fp,
        'specCount' => (int) ($specCount[0]['N'] ?? 0),
    ];
    echo sprintf(
        "%s publicId=%s name=%s status=%s/%s variants=%d media=%d specs=%d\n",
        $code,
        $row['PublicId'],
        $row['ProductName'],
        $row['LifecycleStatusCode'],
        $row['ProductStatusCode'],
        $fp['variants'],
        $fp['media'],
        $specCount[0]['N'] ?? 0,
    );
}

$excludeIds = array_map(static fn (array $r): int => $r['productId'], $resolved);
$globalBefore = globalFingerprint($db, $excludeIds);

// --- definitions ---
$reuseCodes = [
    'CONDUCTORS', 'CORE-INSULATION', 'SCREEN', 'SEPARATOR', 'SEPARATOR-OUTER',
    'BEDDING', 'JACKET', 'CORE-COLOURS', 'VOLTAGE', 'TEMP-RANGE', 'STANDARD', 'DEFAULT-COLOUR',
];
$definitions = [];
foreach ($reuseCodes as $code) {
    $definitions[$code] = requireDefinition($db, $code);
}

$defsCreated = 0;
$defsProposed = [];
foreach ($definitionsToCreate as $def) {
    $existing = findDefinition($db, $def['code']);
    if ($existing !== null) {
        $definitions[$def['code']] = $existing;
        echo "DEFINITION REUSE {$def['code']}\n";
    } else {
        $defsProposed[] = $def['code'];
        echo "DEFINITION CREATE_PROPOSED {$def['code']} ({$def['name']})\n";
        if ($apply) {
            $created = ensureDefinition($db, $def, $publicRules, $actorUserId);
            $definitions[$def['code']] = $created['row'];
            $definitions[$def['code']]['SpecificationDefinitionId'] = $created['id'];
            if ($created['created']) {
                $defsCreated++;
                echo "DEFINITION CREATED {$def['code']}\n";
            }
        }
    }
}

if (!$apply && $defsProposed !== []) {
    // dry-run placeholders so mapping can still describe proposed defs
    foreach ($definitionsToCreate as $def) {
        if (!isset($definitions[$def['code']])) {
            $definitions[$def['code']] = [
                'SpecificationDefinitionId' => 0,
                'DefinitionCode' => $def['code'],
                'DefinitionName' => $def['name'],
                'DataTypeCode' => $def['dataType'],
                'ScopeCode' => $def['scope'],
            ];
        }
    }
}

$standardDefId = (int) $definitions['STANDARD']['SpecificationDefinitionId'];
$allStandardCodes = array_values(array_unique(array_merge($standardsRe110, $standardsR4, $standardsLszh)));
$standardOptions = [];
foreach ($allStandardCodes as $optCode) {
    $standardOptions[$optCode] = requireOptionByCode($db, $standardDefId, $optCode);
}
$blackOption = requireOptionByCode(
    $db,
    (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId'],
    'BK',
);

echo "\nSTANDARD options: all " . count($allStandardCodes) . " REUSE (incl. IEC-60332-3-22, IEC-61034-1_2 for 1&2)\n";
echo "DEFAULT-COLOUR BK REUSE\n";
echo "CREATE_OPTION proposed: 0\n";

/**
 * @return array<string, string>
 */
function textValuesForPlan(
    array $plan,
    string $conductor,
    string $screenTape,
    string $separator,
    string $bedding,
    string $armour,
    string $coreColours,
): array {
    $values = [
        'CONDUCTORS' => $conductor,
        'CORE-INSULATION' => $plan['insulation'],
        'JACKET' => $plan['jacket'],
        'CORE-COLOURS' => $coreColours,
        'VOLTAGE' => '110 V',
    ];
    if ($plan['hasIndividualScreen']) {
        $values['INDIVIDUAL-SCREEN'] = $screenTape;
    }
    if ($plan['hasOverallScreen']) {
        $values['SCREEN'] = $screenTape;
    }
    if ($plan['hasInnerSeparator']) {
        $values['SEPARATOR'] = $separator;
    }
    if ($plan['hasOuterSeparator']) {
        $values['SEPARATOR-OUTER'] = $separator;
    }
    if ($plan['hasBedding']) {
        $values['BEDDING'] = $bedding;
    }
    if ($plan['hasArmour']) {
        $values['ARMOUR'] = $armour;
    }

    return $values;
}

function classifyTextAction(?string $current, string $proposed): string
{
    if ($current === null) {
        return 'ASSIGN';
    }
    if ($current === $proposed) {
        return 'NO_CHANGE';
    }

    return 'UPDATE';
}

echo "\n=== DRY-RUN SUMMARY ===\n";
printf("%-4s | %-36s | %-8s | %-8s | %-8s | %-8s\n", 'PROD', 'PUBLIC ID', 'DEF_REUSE', 'DEF_NEW', 'OPT_REUSE', 'OPT_NEW');
echo str_repeat('-', 100) . "\n";

$mappingRows = [];
$totalAssign = 0;
$totalUpdate = 0;
$totalNoChange = 0;
$productsModifiedExpected = 0;

foreach ($targetCodes as $code) {
    $plan = $plans[$code];
    $productId = $resolved[$code]['productId'];
    $publicId = $resolved[$code]['publicId'];
    $textValues = textValuesForPlan($plan, $conductor, $screenTape, $separator, $bedding, $armour, $coreColours);

    $defReuse = count($reuseCodes);
    $defNew = 0;
    if ($plan['hasIndividualScreen'] && in_array('INDIVIDUAL-SCREEN', $defsProposed, true)) {
        $defNew++;
    } elseif ($plan['hasIndividualScreen']) {
        $defReuse++;
    }
    if ($plan['hasArmour'] && in_array('ARMOUR', $defsProposed, true)) {
        $defNew++;
    } elseif ($plan['hasArmour']) {
        $defReuse++;
    }

    printf(
        "%-4s | %-36s | %-8d | %-8d | %-8d | %-8d\n",
        $code,
        $publicId,
        $defReuse,
        $defNew,
        count($plan['standards']) + 1,
        0,
    );

    $full = trim((string) ($resolved[$code]['product']['FullDescription'] ?? ''));
    $mappingRows[] = [
        'product' => $code,
        'spec' => 'Typical Applications',
        'definition' => 'Products.FullDescription',
        'value' => $typicalApplications,
        'action' => $full === $typicalApplications ? 'NO_CHANGE' : 'UPDATE',
    ];

    foreach ($textValues as $defCode => $value) {
        $defId = (int) $definitions[$defCode]['SpecificationDefinitionId'];
        $current = $defId > 0 ? currentTextValue($db, $productId, $defId) : null;
        $action = classifyTextAction($current, $value);
        $mappingRows[] = [
            'product' => $code,
            'spec' => $definitions[$defCode]['DefinitionName'],
            'definition' => $defCode,
            'value' => str_replace("\n", ' | ', $value),
            'action' => $action,
        ];
    }

    $temp = currentRange($db, $productId, (int) $definitions['TEMP-RANGE']['SpecificationDefinitionId']);
    $tempAction = 'ASSIGN';
    if ($temp['exists'] && $temp['min'] === $plan['tempMin'] && $temp['max'] === $plan['tempMax']) {
        $tempAction = 'NO_CHANGE';
    } elseif ($temp['exists']) {
        $tempAction = 'UPDATE';
    }
    $mappingRows[] = [
        'product' => $code,
        'spec' => 'Operating Temperature',
        'definition' => 'TEMP-RANGE',
        'value' => $plan['tempMin'] . ' to ' . $plan['tempMax'],
        'action' => $tempAction,
    ];

    $currentStandards = currentMultiSelectCodes($db, $productId, $standardDefId);
    sort($currentStandards);
    $proposed = $plan['standards'];
    $sortedProposed = $proposed;
    sort($sortedProposed);
    $stdAction = 'ASSIGN';
    if ($currentStandards === $sortedProposed) {
        $stdAction = 'NO_CHANGE';
    } elseif ($currentStandards !== []) {
        $stdAction = 'UPDATE';
    }
    $mappingRows[] = [
        'product' => $code,
        'spec' => 'Standards',
        'definition' => 'STANDARD',
        'value' => implode('; ', array_map(static fn (string $c): string => (string) $standardOptions[$c]['OptionLabel'], $plan['standards'])),
        'action' => $stdAction,
    ];

    $colour = currentSelectCode($db, $productId, (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId']);
    $colourAction = $colour === 'BK' ? 'NO_CHANGE' : ($colour === null ? 'ASSIGN' : 'UPDATE');
    $mappingRows[] = [
        'product' => $code,
        'spec' => 'Standard Jacket Colour',
        'definition' => 'DEFAULT-COLOUR',
        'value' => 'Black (BK)',
        'action' => $colourAction,
    ];
}

echo "\n=== SPECIFICATION MAPPING ===\n";
printf("%-4s | %-28s | %-22s | %-12s\n", 'PROD', 'SPECIFICATION', 'DEFINITION', 'ACTION');
echo str_repeat('-', 90) . "\n";
foreach ($mappingRows as $row) {
    if (in_array($row['action'], ['ASSIGN', 'UPDATE'], true)) {
        if ($row['action'] === 'ASSIGN') {
            $totalAssign++;
        } else {
            $totalUpdate++;
        }
    } else {
        $totalNoChange++;
    }
    printf(
        "%-4s | %-28s | %-22s | %-12s\n",
        $row['product'],
        substr($row['spec'], 0, 28),
        substr($row['definition'], 0, 22),
        $row['action'],
    );
}

$changedProducts = [];
foreach ($mappingRows as $row) {
    if (in_array($row['action'], ['ASSIGN', 'UPDATE'], true)) {
        $changedProducts[$row['product']] = true;
    }
}
$productsModifiedExpected = count($changedProducts);

echo "\n=== COUNTS (proposed) ===\n";
echo "Products created: 0\n";
echo "Products modified: {$productsModifiedExpected}\n";
echo "Variants created: 0\n";
echo "Media writes: 0\n";
echo 'Definitions reused: ' . count($reuseCodes) . " (+ INDIVIDUAL-SCREEN/ARMOUR if already exist)\n";
echo 'Definitions proposed CREATE: ' . count($defsProposed) . ' [' . implode(', ', $defsProposed) . "]\n";
echo 'Options reused: ' . count($allStandardCodes) . " STANDARD + BK\n";
echo "Options proposed CREATE: 0\n";
echo "Assignments ASSIGN: {$totalAssign}\n";
echo "Assignments UPDATE: {$totalUpdate}\n";
echo "NO_CHANGE: {$totalNoChange}\n";
echo "Legacy writes: 0\n";
echo "Duplicate check: R2–R8 unique by InternalCode; STANDARD options exact OptionCode match; BK unique\n";

if (!$apply) {
    echo "\nDRY-RUN complete. Re-run with --apply to write.\n";
    exit(0);
}

if ($defsProposed !== []) {
    foreach ($definitionsToCreate as $def) {
        if (!isset($definitions[$def['code']]) || (int) ($definitions[$def['code']]['SpecificationDefinitionId'] ?? 0) === 0) {
            $created = ensureDefinition($db, $def, $publicRules, $actorUserId);
            $definitions[$def['code']] = $created['row'];
            $definitions[$def['code']]['SpecificationDefinitionId'] = $created['id'];
            if ($created['created']) {
                $defsCreated++;
            }
        }
    }
}

$actionsByProduct = [];
$assignCreated = 0;
$assignUpdated = 0;
$colourAssignments = 0;

$db->beginTransaction();
try {
    foreach ($targetCodes as $code) {
        $plan = $plans[$code];
        $productId = $resolved[$code]['productId'];
        $textValues = textValuesForPlan($plan, $conductor, $screenTape, $separator, $bedding, $armour, $coreColours);
        $actions = [];

        $full = trim((string) ($resolved[$code]['product']['FullDescription'] ?? ''));
        if ($full === $typicalApplications) {
            $actions['FullDescription'] = 'KEEP';
        } else {
            $db->execute(
                <<<'SQL'
UPDATE [product].[Products]
SET [FullDescription] = :fullDescription,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [ProductId] = :productId
SQL,
                [
                    ':fullDescription' => $typicalApplications,
                    ':actor' => $actorUserId,
                    ':productId' => $productId,
                ],
            );
            $actions['FullDescription'] = $full === '' ? 'ADD' : 'UPDATE';
            if ($actions['FullDescription'] === 'ADD') {
                $assignCreated++;
            } else {
                $assignUpdated++;
            }
        }

        foreach ($textValues as $defCode => $value) {
            $result = upsertTextValue(
                $db,
                $productId,
                (int) $definitions[$defCode]['SpecificationDefinitionId'],
                $value,
                $textTypes[$defCode],
                $actorUserId,
            );
            $actions[$defCode] = $result;
            if ($result === 'ADD') {
                $assignCreated++;
            } elseif ($result === 'UPDATE') {
                $assignUpdated++;
            }
        }

        $tempResult = upsertRange(
            $db,
            $productId,
            (int) $definitions['TEMP-RANGE']['SpecificationDefinitionId'],
            $plan['tempMin'],
            $plan['tempMax'],
            $actorUserId,
        );
        $actions['TEMP-RANGE'] = $tempResult;
        if ($tempResult === 'ADD') {
            $assignCreated++;
        } elseif ($tempResult === 'UPDATE') {
            $assignUpdated++;
        }

        $optionIds = [];
        foreach ($plan['standards'] as $optCode) {
            $optionIds[] = (int) $standardOptions[$optCode]['SpecificationOptionId'];
        }
        $currentStd = currentMultiSelectCodes($db, $productId, $standardDefId);
        sort($currentStd);
        $want = $plan['standards'];
        sort($want);
        if ($currentStd === $want) {
            $actions['STANDARD'] = 'KEEP';
        } else {
            $stdResult = upsertMultiSelectValue($db, $productId, $standardDefId, $optionIds, $actorUserId);
            $actions['STANDARD'] = $stdResult;
            if ($stdResult === 'ADD') {
                $assignCreated++;
            } elseif ($stdResult === 'UPDATE') {
                $assignUpdated++;
            }
        }

        $colourResult = upsertSelectOption(
            $db,
            $productId,
            (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId'],
            (int) $blackOption['SpecificationOptionId'],
            $actorUserId,
        );
        $actions['DEFAULT-COLOUR'] = $colourResult;
        if ($colourResult === 'ADD') {
            $assignCreated++;
            $colourAssignments++;
        } elseif ($colourResult === 'UPDATE') {
            $assignUpdated++;
            $colourAssignments++;
        }

        $actionsByProduct[$code] = $actions;
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'APPLY FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\n=== APPLY ACTIONS ===\n";
foreach ($actionsByProduct as $code => $actions) {
    echo "--- {$code} ---\n";
    foreach ($actions as $k => $v) {
        echo "  {$k}: {$v}\n";
    }
}

echo "\n=== REGRESSION ===\n";
$fail = false;
foreach ($targetCodes as $code) {
    $after = productFingerprint($db, $resolved[$code]['productId']);
    $before = $resolved[$code]['before'];
    foreach (['variants', 'variantHash', 'media', 'mediaHash'] as $key) {
        $ok = $before[$key] === $after[$key];
        echo "{$code} {$key}: {$before[$key]} -> {$after[$key]} " . ($ok ? 'UNCHANGED' : 'CHANGED') . "\n";
        if (!$ok) {
            $fail = true;
        }
    }
}
$globalAfter = globalFingerprint($db, $excludeIds);
$expectedDefDelta = $defsCreated;
foreach (['products', 'otherSpecs', 'options', 'r1Specs', 'r1Hash'] as $key) {
    $ok = $globalBefore[$key] === $globalAfter[$key];
    echo "global {$key}: {$globalBefore[$key]} -> {$globalAfter[$key]} " . ($ok ? 'UNCHANGED' : 'CHANGED') . "\n";
    if (!$ok) {
        $fail = true;
    }
}
$defOk = $globalAfter['definitions'] === $globalBefore['definitions'] + $expectedDefDelta;
echo "global definitions: {$globalBefore['definitions']} -> {$globalAfter['definitions']} expected_delta={$expectedDefDelta} " . ($defOk ? 'OK' : 'CHANGED') . "\n";
if (!$defOk) {
    $fail = true;
}

if ($fail) {
    fwrite(STDERR, "STOP: regression fingerprint changed unexpectedly\n");
    exit(1);
}

echo "\n=== APPLY TOTALS ===\n";
echo "Products created: 0\n";
echo 'Products modified: ' . count(array_filter($actionsByProduct, static function (array $a): bool {
    foreach ($a as $v) {
        if ($v !== 'KEEP') {
            return true;
        }
    }

    return false;
})) . "\n";
echo "Variants created: 0\n";
echo "Variants modified: 0\n";
echo "Media writes: 0\n";
echo "Specification definitions created: {$defsCreated}\n";
echo "Specification options created: 0\n";
echo "Specification assignments created: {$assignCreated}\n";
echo "Specification assignments updated: {$assignUpdated}\n";
echo "Colour assignments: {$colourAssignments}\n";
echo "Legacy writes: 0\n";
echo "\nAPPLY complete for R2–R8\n";
