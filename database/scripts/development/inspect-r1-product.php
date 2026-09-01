<?php

declare(strict_types=1);

/**
 * Read-only inspection of R1 product + specification masters.
 * DEVELOPMENT / non-production only. No writes.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__, 3);
$settings = Environment::settings($root);

echo "APP_ENV={$settings->appEnv}\n";
echo "DB_HOST={$settings->dbHost}\n";
echo "DB_DATABASE={$settings->dbDatabase}\n";
echo 'isProduction=' . ($settings->appEnv === 'production' ? 'YES' : 'NO') . "\n";

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$identity = $db->fetchAll('SELECT @@SERVERNAME AS [ServerName], DB_NAME() AS [DbName]');
echo '@@SERVERNAME=' . ($identity[0]['ServerName'] ?? '') . "\n";
echo 'DB_NAME()=' . ($identity[0]['DbName'] ?? '') . "\n";

$products = $db->fetchAll(
    <<<'SQL'
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[InternalCode],
    p.[ProductName],
    pls.[LifecycleStatusCode],
    ps.[StatusCode] AS [ProductStatusCode],
    p.[ShortDescription],
    p.[FullDescription],
    st.[StyleCode],
    st.[DisplayName] AS [StyleName]
FROM [product].[Products] p
INNER JOIN [product].[ProductLifecycleStatuses] pls
    ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
INNER JOIN [product].[ProductStatuses] ps
    ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductStyleAssignments] psa
    ON psa.[ProductId] = p.[ProductId]
LEFT JOIN [product].[ProductStyles] st
    ON st.[ProductStyleId] = psa.[ProductStyleId] AND st.[DeletedAtUtc] IS NULL
WHERE p.[DeletedAtUtc] IS NULL
  AND (
        p.[InternalCode] = N'R1'
     OR st.[StyleCode] = N'R1'
  )
ORDER BY p.[InternalCode], p.[ProductName]
SQL,
);

echo "\n=== R1 CANDIDATES (" . count($products) . ") ===\n";
foreach ($products as $row) {
    echo sprintf(
        "id=%s publicId=%s code=%s name=%s lifecycle=%s status=%s style=%s\n",
        $row['ProductId'],
        $row['PublicId'],
        $row['InternalCode'],
        $row['ProductName'],
        $row['LifecycleStatusCode'],
        $row['ProductStatusCode'],
        $row['StyleCode'] ?? '',
    );
    echo '  short=' . json_encode((string) ($row['ShortDescription'] ?? '')) . "\n";
    echo '  full=' . json_encode((string) ($row['FullDescription'] ?? '')) . "\n";
}

if (count($products) === 1) {
    $productId = (int) $products[0]['ProductId'];
    $variantCount = $db->fetchAll(
        'SELECT COUNT(*) AS [N] FROM [product].[ProductVariants] WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL',
        [':id' => $productId],
    );
    $specCount = $db->fetchAll(
        'SELECT COUNT(*) AS [N] FROM [product].[ProductSpecificationValues] WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL',
        [':id' => $productId],
    );
    $mediaCount = $db->fetchAll(
        'SELECT COUNT(*) AS [N] FROM [product].[ProductImages] WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL',
        [':id' => $productId],
    );
    echo "\nvariants=" . ($variantCount[0]['N'] ?? 0) . "\n";
    echo 'specs=' . ($specCount[0]['N'] ?? 0) . "\n";
    echo 'media=' . ($mediaCount[0]['N'] ?? 0) . "\n";

    $specs = $db->fetchAll(
        <<<'SQL'
SELECT
    sd.[DefinitionCode],
    sd.[DefinitionName],
    sd.[DataTypeCode],
    psv.[ValueText],
    LEFT(ISNULL(psv.[ValueLongText], N''), 400) AS [ValueLongText],
    psv.[ValueMinDecimal],
    psv.[ValueMaxDecimal],
    o.[OptionCode],
    o.[OptionLabel]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[SpecificationDefinitions] sd
    ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = psv.[SpecificationOptionId]
WHERE psv.[ProductId] = :id
  AND psv.[DeletedAtUtc] IS NULL
ORDER BY sd.[DisplayOrder], sd.[DefinitionCode]
SQL,
        [':id' => $productId],
    );
    echo "\n=== EXISTING SPEC VALUES ===\n";
    foreach ($specs as $spec) {
        echo sprintf(
            "%s (%s/%s) text=%s long=%s min=%s max=%s opt=%s %s\n",
            $spec['DefinitionCode'],
            $spec['DefinitionName'],
            $spec['DataTypeCode'],
            json_encode($spec['ValueText']),
            json_encode($spec['ValueLongText']),
            json_encode($spec['ValueMinDecimal']),
            json_encode($spec['ValueMaxDecimal']),
            $spec['OptionCode'] ?? '',
            $spec['OptionLabel'] ?? '',
        );
    }

    $junction = $db->fetchAll(
        <<<'SQL'
SELECT sd.[DefinitionCode], o.[OptionCode], o.[OptionLabel]
FROM [product].[ProductSpecificationValues] psv
INNER JOIN [product].[SpecificationDefinitions] sd
    ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
INNER JOIN [product].[ProductSpecificationOptionValues] pov
    ON pov.[ProductSpecificationValueId] = psv.[ProductSpecificationValueId]
INNER JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = pov.[SpecificationOptionId]
WHERE psv.[ProductId] = :id
  AND psv.[DeletedAtUtc] IS NULL
ORDER BY sd.[DefinitionCode], o.[DisplayOrder], o.[OptionLabel]
SQL,
        [':id' => $productId],
    );
    echo "\n=== MULTI-SELECT OPTIONS ===\n";
    foreach ($junction as $row) {
        echo "{$row['DefinitionCode']} {$row['OptionCode']} {$row['OptionLabel']}\n";
    }

    $media = $db->fetchAll(
        <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [ImageRoleCode], [DisplayOrder], LEFT(ISNULL([PublicUrl], N''), 80) AS [Url]
FROM [product].[ProductImages]
WHERE [ProductId] = :id AND [DeletedAtUtc] IS NULL
ORDER BY [DisplayOrder]
SQL,
        [':id' => $productId],
    );
    echo "\n=== MEDIA ===\n";
    foreach ($media as $row) {
        echo "{$row['PublicId']} {$row['ImageRoleCode']} {$row['DisplayOrder']} {$row['Url']}\n";
    }
}

echo "\n=== DEFINITIONS (active) ===\n";
$defs = $db->fetchAll(
    <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId],
    [DefinitionCode],
    [DefinitionName],
    [DataTypeCode],
    [ScopeCode],
    [IsActive]
FROM [product].[SpecificationDefinitions]
WHERE [DeletedAtUtc] IS NULL
ORDER BY [DefinitionCode]
SQL,
);
foreach ($defs as $def) {
    echo sprintf(
        "%s %s type=%s scope=%s active=%s\n",
        $def['DefinitionCode'],
        $def['DefinitionName'],
        $def['DataTypeCode'],
        $def['ScopeCode'],
        $def['IsActive'],
    );
}

echo "\n=== STANDARD OPTIONS matching R1 set ===\n";
$wanted = [
    'AS/NZS 1125', 'AS/NZS 1660.5.1', 'AS/NZS 1660.5.6', 'AS/NZS 3808',
    'IEC 60228', 'IEC 60332-1',
];
$standardDef = $db->fetchAll(
    "SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = N'STANDARD' AND [DeletedAtUtc] IS NULL",
);
if ($standardDef !== []) {
    $opts = $db->fetchAll(
        <<<'SQL'
SELECT [OptionCode], [OptionLabel], [IsActive]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :id AND [DeletedAtUtc] IS NULL
ORDER BY [OptionLabel]
SQL,
        [':id' => (int) $standardDef[0]['SpecificationDefinitionId']],
    );
    foreach ($opts as $opt) {
        $hay = strtoupper($opt['OptionCode'] . ' ' . $opt['OptionLabel']);
        foreach ($wanted as $want) {
            if (str_contains($hay, strtoupper($want)) || str_contains(strtoupper($want), strtoupper((string) $opt['OptionLabel']))) {
                echo "MATCH want={$want} code={$opt['OptionCode']} label={$opt['OptionLabel']} active={$opt['IsActive']}\n";
            }
        }
    }
    echo "--- all STANDARD labels ---\n";
    foreach ($opts as $opt) {
        echo "{$opt['OptionCode']} | {$opt['OptionLabel']}\n";
    }
}

echo "\n=== DEFAULT-COLOUR OPTIONS ===\n";
$colourDef = $db->fetchAll(
    "SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] = N'DEFAULT-COLOUR' AND [DeletedAtUtc] IS NULL",
);
if ($colourDef !== []) {
    $opts = $db->fetchAll(
        <<<'SQL'
SELECT [OptionCode], [OptionLabel], [IsActive]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :id AND [DeletedAtUtc] IS NULL
ORDER BY [DisplayOrder], [OptionCode]
SQL,
        [':id' => (int) $colourDef[0]['SpecificationDefinitionId']],
    );
    foreach ($opts as $opt) {
        echo "{$opt['OptionCode']} | {$opt['OptionLabel']} active={$opt['IsActive']}\n";
    }
}
