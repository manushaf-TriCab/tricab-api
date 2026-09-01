<?php

declare(strict_types=1);

/**
 * Enrich the existing R1 product from the R1 datasheet (Typical Applications,
 * construction, temp, voltage, standards, jacket colour, PAIR/TRIAD cores).
 *
 * Does NOT create products, variants, media, or specification definitions.
 * Does NOT invent variant-matrix values (pairs/triads, OD, bend, current, weight).
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
$internalCode = 'R1';

$typicalApplications = 'Flexible instrumentation cable suitable for wiring of monitoring, control and measuring equipment in fixed installation.';

$coreColours = <<<'TEXT'
PAIR: WH BK
TRIAD: WH BK RE

Other core colours available on request.
TEXT;

$textValues = [
    'CONDUCTORS' => 'Fine wire plain or tinned annealed copper to IEC 60228 and AS/NZS 1125.',
    'CORE-INSULATION' => 'Elastomeric R-E-110',
    'SCREEN' => 'Aluminium Laminate Tape with Drain Wire',
    'SEPARATOR' => 'Polypropylene Tape',
    'JACKET' => 'Low-Friction S-20 Thermoplastic, Elastomeric, Flame Retardant (Cat A). Splash resistant to oil, Skydrol, petrol, acid and sea water. Resists ozone and UV. Excellent abrasion resistance. Anti-Termite/Rodent Jacket is Available.',
    'CORE-COLOURS' => $coreColours,
    'VOLTAGE' => '110 V',
];

$textTypes = [
    'CONDUCTORS' => 'long-text',
    'CORE-INSULATION' => 'long-text',
    'SCREEN' => 'long-text',
    'SEPARATOR' => 'text',
    'JACKET' => 'long-text',
    'CORE-COLOURS' => 'long-text',
    'VOLTAGE' => 'text',
];

$standardCodes = [
    'AS-NZS-1125',
    'AS-NZS-1660-5-1',
    'AS-NZS-1660-5-6',
    'AS-NZS-3808',
    'IEC-60228',
    'IEC-60332-1',
];

function requireDefinition(SqlConnection $db, string $code): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT [SpecificationDefinitionId], [DefinitionCode], [DefinitionName], [DataTypeCode], [ScopeCode]
FROM [product].[SpecificationDefinitions]
WHERE [DefinitionCode] = :code AND [DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );
    if ($rows === []) {
        throw new RuntimeException("MISSING_DEFINITION {$code}");
    }

    return $rows[0];
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
    $text = trim((string) ($rows[0]['ValueLongText'] ?? $rows[0]['ValueText'] ?? ''));

    return $text;
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

/**
 * @return list<string>
 */
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

/**
 * @param list<int> $optionIds
 */
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

function fingerprint(SqlConnection $db, int $productId, int $r1tProductId): array
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
    $otherSpecs = $db->fetchAll(
        'SELECT COUNT(*) AS [N] FROM [product].[ProductSpecificationValues] WHERE [ProductId] <> :id AND [DeletedAtUtc] IS NULL',
        [':id' => $productId],
    );
    $defs = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[SpecificationDefinitions] WHERE [DeletedAtUtc] IS NULL');
    $opts = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[SpecificationOptions] WHERE [DeletedAtUtc] IS NULL');
    $r1t = $db->fetchAll(
        <<<'SQL'
SELECT COUNT(*) AS [N],
       CHECKSUM_AGG(CHECKSUM(sd.[DefinitionCode], ISNULL(psv.[ValueText], N''), ISNULL(psv.[ValueLongText], N''), ISNULL(CONVERT(NVARCHAR(50), psv.[ValueMinDecimal]), N''), ISNULL(CONVERT(NVARCHAR(50), psv.[ValueMaxDecimal]), N''), ISNULL(CONVERT(NVARCHAR(20), psv.[SpecificationOptionId]), N''))) AS [Hash]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[SpecificationDefinitions] sd ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
WHERE psv.[ProductId] = :id AND psv.[DeletedAtUtc] IS NULL
SQL,
        [':id' => $r1tProductId],
    );
    $products = $db->fetchAll('SELECT COUNT(*) AS [N] FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL');

    return [
        'variants' => (int) ($variant[0]['N'] ?? 0),
        'variantHash' => (string) ($variant[0]['Hash'] ?? ''),
        'media' => (int) ($media[0]['N'] ?? 0),
        'mediaHash' => (string) ($media[0]['Hash'] ?? ''),
        'otherSpecs' => (int) ($otherSpecs[0]['N'] ?? 0),
        'definitions' => (int) ($defs[0]['N'] ?? 0),
        'options' => (int) ($opts[0]['N'] ?? 0),
        'r1tSpecs' => (int) ($r1t[0]['N'] ?? 0),
        'r1tHash' => (string) ($r1t[0]['Hash'] ?? ''),
        'products' => (int) ($products[0]['N'] ?? 0),
    ];
}

$candidates = $db->fetchAll(
    <<<'SQL'
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[InternalCode],
    p.[ProductName],
    p.[ShortDescription],
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
    [':code' => $internalCode, ':style' => $internalCode],
);

if (count($candidates) !== 1) {
    fwrite(STDERR, 'STOP: expected exactly 1 R1 product, found ' . count($candidates) . "\n");
    foreach ($candidates as $row) {
        fwrite(STDERR, sprintf(
            "  id=%s publicId=%s code=%s style=%s name=%s\n",
            $row['ProductId'],
            $row['PublicId'],
            $row['InternalCode'],
            $row['StyleCode'] ?? '',
            $row['ProductName'],
        ));
    }
    exit(1);
}

$product = $candidates[0];
$productId = (int) $product['ProductId'];
$publicId = (string) $product['PublicId'];
if (strcasecmp((string) $product['InternalCode'], 'R1') !== 0) {
    fwrite(STDERR, "STOP: unique match is not InternalCode R1\n");
    exit(1);
}

$r1tRows = $db->fetchAll(
    <<<'SQL'
SELECT [ProductId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[Products]
WHERE [InternalCode] = N'R1T' AND [DeletedAtUtc] IS NULL
SQL,
);
$r1tProductId = $r1tRows !== [] ? (int) $r1tRows[0]['ProductId'] : 0;

$before = fingerprint($db, $productId, $r1tProductId > 0 ? $r1tProductId : $productId);

$variantCount = $before['variants'];
$specCount = $db->fetchAll(
    'SELECT COUNT(*) AS [N] FROM [product].[ProductSpecificationValues] WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL',
    [':id' => $productId],
);
$mediaCount = $before['media'];

echo "\n=== R1 TARGET ===\n";
echo "ProductId={$productId}\n";
echo "publicId={$publicId}\n";
echo "code={$product['InternalCode']}\n";
echo "name={$product['ProductName']}\n";
echo "lifecycle={$product['LifecycleStatusCode']}\n";
echo "status={$product['ProductStatusCode']}\n";
echo "variants={$variantCount}\n";
echo 'specs=' . ($specCount[0]['N'] ?? 0) . "\n";
echo "media={$mediaCount}\n";

$definitionCodes = array_unique(array_merge(array_keys($textValues), ['TEMP-RANGE', 'STANDARD', 'DEFAULT-COLOUR']));
$definitions = [];
foreach ($definitionCodes as $code) {
    $definitions[$code] = requireDefinition($db, $code);
}

$standardOptions = [];
$standardOptionIds = [];
foreach ($standardCodes as $code) {
    $opt = requireOptionByCode($db, (int) $definitions['STANDARD']['SpecificationDefinitionId'], $code);
    $standardOptions[$code] = $opt;
    $standardOptionIds[] = (int) $opt['SpecificationOptionId'];
}
$blackOption = requireOptionByCode(
    $db,
    (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId'],
    'BK',
);

$currentStandards = currentMultiSelectCodes($db, $productId, (int) $definitions['STANDARD']['SpecificationDefinitionId']);
sort($currentStandards);
$proposedStandards = $standardCodes;
$sortedProposed = $proposedStandards;
sort($sortedProposed);
$standardsSame = $currentStandards === $sortedProposed;

$currentTemp = currentRange($db, $productId, (int) $definitions['TEMP-RANGE']['SpecificationDefinitionId']);
$currentColour = currentSelectCode($db, $productId, (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId']);
$currentFull = trim((string) ($product['FullDescription'] ?? ''));

$mapping = [];
$mapping[] = [
    'field' => 'Typical Applications',
    'definition' => 'Products.FullDescription',
    'option' => '—',
    'value' => $typicalApplications,
    'action' => $currentFull === $typicalApplications ? 'NO CHANGE' : 'ASSIGN VALUE',
];

foreach (
    [
        'Conductor' => 'CONDUCTORS',
        'Insulation' => 'CORE-INSULATION',
        'Overall Screen' => 'SCREEN',
        'Separator' => 'SEPARATOR',
        'Jacket' => 'JACKET',
        'Voltage Rating' => 'VOLTAGE',
        'Pair / Triad Core Colours' => 'CORE-COLOURS',
    ] as $label => $code
) {
    $current = currentTextValue($db, $productId, (int) $definitions[$code]['SpecificationDefinitionId']);
    $proposed = $textValues[$code];
    if ($current === null) {
        $action = 'ASSIGN VALUE';
    } elseif ($current === $proposed) {
        $action = 'NO CHANGE';
    } else {
        $action = 'ASSIGN VALUE';
    }
    $mapping[] = [
        'field' => $label,
        'definition' => $code . ' / ' . $definitions[$code]['DefinitionName'],
        'option' => '—',
        'value' => str_replace("\n", ' | ', $proposed),
        'action' => $action,
        'code' => $code,
        'current' => $current,
    ];
}

$tempAction = 'ASSIGN VALUE';
if ($currentTemp['exists'] && $currentTemp['min'] === -15.0 && $currentTemp['max'] === 110.0) {
    $tempAction = 'NO CHANGE';
}
$mapping[] = [
    'field' => 'Operating Temperature',
    'definition' => 'TEMP-RANGE / ' . $definitions['TEMP-RANGE']['DefinitionName'],
    'option' => 'structured min/max',
    'value' => '-15 to +110 (datasheet -15°C to +110°C)',
    'action' => $tempAction,
];

$mapping[] = [
    'field' => 'Standards',
    'definition' => 'STANDARD / ' . $definitions['STANDARD']['DefinitionName'],
    'option' => implode(', ', array_map(static fn (array $o): string => (string) $o['OptionLabel'], $standardOptions)),
    'value' => implode('; ', array_map(static fn (array $o): string => (string) $o['OptionLabel'], $standardOptions)),
    'action' => $standardsSame ? 'NO CHANGE' : 'ASSIGN VALUE',
];

$colourAction = $currentColour === 'BK' ? 'NO CHANGE' : 'ASSIGN VALUE';
$mapping[] = [
    'field' => 'Standard Jacket Colour',
    'definition' => 'DEFAULT-COLOUR / ' . $definitions['DEFAULT-COLOUR']['DefinitionName'],
    'option' => $blackOption['OptionCode'] . ' / ' . $blackOption['OptionLabel'],
    'value' => 'BLACK',
    'action' => $colourAction,
];

$mapping[] = [
    'field' => 'Jacket “other colours on request”',
    'definition' => '(none — no jacket-colour-note definition)',
    'option' => '—',
    'value' => 'informational only; not stored as extra sellable colours',
    'action' => 'NO CHANGE',
];

echo "\n=== DRY-RUN TABLE ===\n";
printf("%-32s | %-36s | %-22s | %-12s\n", 'Specification', 'Existing Definition', 'Existing Option', 'Action');
echo str_repeat('-', 120) . "\n";
$assignCount = 0;
$noChangeCount = 0;
foreach ($mapping as $row) {
    printf(
        "%-32s | %-36s | %-22s | %-12s\n",
        $row['field'],
        substr((string) $row['definition'], 0, 36),
        substr((string) $row['option'], 0, 22),
        $row['action'],
    );
    if ($row['action'] === 'ASSIGN VALUE') {
        $assignCount++;
    }
    if ($row['action'] === 'NO CHANGE') {
        $noChangeCount++;
    }
}

echo "\n=== COUNTS (proposed) ===\n";
echo "Products created: 0\n";
echo "Products modified: " . ($assignCount > 0 || $mapping[0]['action'] === 'ASSIGN VALUE' ? '1' : '0') . " (R1 only)\n";
echo "Variants created: 0\n";
echo "Media writes: 0\n";
echo 'Specification definitions reused: ' . count($definitions) . "\n";
echo "Specification definitions proposed: 0\n";
echo 'Specification options reused: ' . (count($standardOptions) + 1) . " (6 STANDARD + BK)\n";
echo "Specification options proposed: 0\n";
echo "Specification assignments proposed: {$assignCount}\n";
echo "Legacy writes: 0\n";
echo "CREATE DEFINITION: 0\n";
echo "CREATE OPTION: 0\n";

echo "\nCurrent STANDARD options: " . implode(', ', $currentStandards) . "\n";
echo 'Current TEMP-RANGE: min=' . json_encode($currentTemp['min']) . ' max=' . json_encode($currentTemp['max']) . "\n";
echo 'Current DEFAULT-COLOUR: ' . json_encode($currentColour) . "\n";
echo 'Current FullDescription match: ' . ($currentFull === $typicalApplications ? 'YES' : 'NO') . "\n";

if (!$apply) {
    echo "\nDRY-RUN complete. Re-run with --apply to write.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $actions = [];
    if ($mapping[0]['action'] === 'ASSIGN VALUE') {
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
        $actions['FullDescription'] = 'UPDATE';
    } else {
        $actions['FullDescription'] = 'KEEP';
    }

    foreach ($textValues as $code => $value) {
        $actions[$code] = upsertTextValue(
            $db,
            $productId,
            (int) $definitions[$code]['SpecificationDefinitionId'],
            $value,
            $textTypes[$code],
            $actorUserId,
        );
    }

    $actions['TEMP-RANGE'] = upsertRange(
        $db,
        $productId,
        (int) $definitions['TEMP-RANGE']['SpecificationDefinitionId'],
        -15.0,
        110.0,
        $actorUserId,
    );

    $currentForKeep = currentMultiSelectCodes($db, $productId, (int) $definitions['STANDARD']['SpecificationDefinitionId']);
    sort($currentForKeep);
    if ($currentForKeep === $sortedProposed) {
        $actions['STANDARD'] = 'KEEP';
    } else {
        $actions['STANDARD'] = upsertMultiSelectValue(
            $db,
            $productId,
            (int) $definitions['STANDARD']['SpecificationDefinitionId'],
            $standardOptionIds,
            $actorUserId,
        );
    }

    $actions['DEFAULT-COLOUR'] = upsertSelectOption(
        $db,
        $productId,
        (int) $definitions['DEFAULT-COLOUR']['SpecificationDefinitionId'],
        (int) $blackOption['SpecificationOptionId'],
        $actorUserId,
    );

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'APPLY FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

$after = fingerprint($db, $productId, $r1tProductId > 0 ? $r1tProductId : $productId);

echo "\n=== APPLY ACTIONS ===\n";
foreach ($actions as $code => $action) {
    echo "{$code}: {$action}\n";
}

echo "\n=== REGRESSION FINGERPRINTS ===\n";
foreach (['products', 'variants', 'variantHash', 'media', 'mediaHash', 'otherSpecs', 'definitions', 'options', 'r1tSpecs', 'r1tHash'] as $key) {
    $ok = $before[$key] === $after[$key] ? 'UNCHANGED' : 'CHANGED';
    echo "{$key}: before={$before[$key]} after={$after[$key]} {$ok}\n";
}

if (
    $before['products'] !== $after['products']
    || $before['variants'] !== $after['variants']
    || $before['variantHash'] !== $after['variantHash']
    || $before['media'] !== $after['media']
    || $before['mediaHash'] !== $after['mediaHash']
    || $before['otherSpecs'] !== $after['otherSpecs']
    || $before['definitions'] !== $after['definitions']
    || $before['options'] !== $after['options']
    || $before['r1tSpecs'] !== $after['r1tSpecs']
    || $before['r1tHash'] !== $after['r1tHash']
) {
    fwrite(STDERR, "STOP: regression fingerprint changed unexpectedly\n");
    exit(1);
}

echo "\nAPPLY complete for R1 publicId={$publicId}\n";
