<?php

declare(strict_types=1);

/**
 * ALI-FLEX Marine remaining gaps: BEDDING (PJ), JACKET-OPTION-P (BV/DV/PJ).
 * Does not create products/variants, does not change CURRENT numbers or media.
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
echo 'PRODUCTION=' . ($settings->appEnv === 'production' ? 'YES' : 'NO') . "\n";

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);
$actorUserId = 1;

function definitionSearch(SqlConnection $db, string $sql, array $params = []): void
{
    foreach ($db->fetchAll($sql, $params) as $row) {
        echo sprintf(
            "  %s | %s | type=%s scope=%s group=%s order=%s publicId=%s\n",
            $row['DefinitionCode'] ?? '',
            $row['DefinitionName'] ?? '',
            $row['DataTypeCode'] ?? '',
            $row['ScopeCode'] ?? '',
            $row['GroupCode'] ?? '',
            $row['DisplayOrder'] ?? '',
            $row['PublicId'] ?? '',
        );
    }
}

echo "\n=== DEFINITION SEARCH bed/jacket-option/note/current ===\n";
definitionSearch(
    $db,
    <<<'SQL'
SELECT
    d.[DefinitionCode],
    d.[DefinitionName],
    d.[DataTypeCode],
    d.[ScopeCode],
    g.[GroupCode],
    d.[DisplayOrder],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId]
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] g ON g.[SpecificationGroupId] = d.[SpecificationGroupId]
WHERE d.[DeletedAtUtc] IS NULL
  AND (
    d.[DefinitionCode] LIKE N'%BED%'
    OR d.[DefinitionName] LIKE N'%bed%'
    OR d.[DefinitionCode] LIKE N'%JACKET-OPTION%'
    OR d.[DefinitionName] LIKE N'%jacket option%'
    OR d.[DefinitionCode] LIKE N'%NOTE%'
    OR d.[DefinitionName] LIKE N'%note%'
    OR d.[DefinitionCode] LIKE N'%FOOT%'
    OR d.[DefinitionName] LIKE N'%footnote%'
    OR d.[DefinitionCode] LIKE N'%CURRENT%'
    OR d.[DefinitionName] LIKE N'%current%'
  )
ORDER BY d.[DefinitionCode]
SQL,
);

echo "\n=== JACKET-OPTION-A TEMPLATE ===\n";
$jacketA = $db->fetchAll(
    <<<'SQL'
SELECT
    d.[SpecificationDefinitionId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    d.[DefinitionCode],
    d.[DefinitionName],
    d.[Description],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[Unit],
    d.[DisplayOrder],
    d.[IsRequired],
    d.[IsFilterable],
    d.[IsSearchable],
    d.[IsComparable],
    d.[ValidationRulesJson],
    g.[GroupCode]
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] g ON g.[SpecificationGroupId] = d.[SpecificationGroupId]
WHERE d.[DefinitionCode] = N'JACKET-OPTION-A'
  AND d.[DeletedAtUtc] IS NULL
SQL,
);
if ($jacketA === []) {
    fwrite(STDERR, "STOP: JACKET-OPTION-A missing; cannot clone P Type\n");
    exit(1);
}
echo json_encode($jacketA[0], JSON_UNESCAPED_UNICODE) . "\n";

function requireProduct(SqlConnection $db, string $code): array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT p.[ProductId], p.[InternalCode], p.[ProductName], CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId]
FROM [product].[Products] p
WHERE p.[InternalCode] = :code AND p.[DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );
    if (count($rows) !== 1) {
        throw new RuntimeException("STOP: {$code} resolved to " . count($rows) . ' products');
    }

    return $rows[0];
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

    $valueText = $dataType === 'text' ? $value : null;
    $valueLongText = $dataType === 'long-text' ? $value : null;
    if ($dataType === 'text' && strlen($value) > 400) {
        $valueText = null;
        $valueLongText = $value;
    }

    $current = null;
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
SET [ValueText] = :valueText,
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

    return $current === '' ? 'ADD' : 'UPDATE';
}

function loadDefinition(SqlConnection $db, string $code): ?array
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT
    d.[SpecificationDefinitionId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    d.[DefinitionCode],
    d.[DefinitionName],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[DisplayOrder]
FROM [product].[SpecificationDefinitions] d
WHERE d.[DefinitionCode] = :code AND d.[DeletedAtUtc] IS NULL
SQL,
        [':code' => $code],
    );

    return $rows[0] ?? null;
}

function bvaFingerprint(SqlConnection $db): string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT d.[DefinitionCode], v.[ValueText], LEFT(v.[ValueLongText], 200) AS [ValueLongText],
       v.[ValueMinDecimal], v.[ValueMaxDecimal], o.[OptionCode]
FROM [product].[Products] p
LEFT JOIN [product].[ProductSpecificationValues] v
    ON v.[ProductId] = p.[ProductId] AND v.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[SpecificationDefinitions] d
    ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = v.[SpecificationOptionId]
WHERE p.[InternalCode] = N'BVA' AND p.[DeletedAtUtc] IS NULL
ORDER BY d.[DefinitionCode]
SQL,
    );

    return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE));
}

function currentSample(SqlConnection $db, string $code): string
{
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT TOP 3 pv.[VariantCode], vs.[ValueDecimal]
FROM [product].[Products] p
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductId] = p.[ProductId] AND pv.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductVariantSpecificationValues] vs
    ON vs.[ProductVariantId] = pv.[ProductVariantId] AND vs.[DeletedAtUtc] IS NULL
INNER JOIN [product].[SpecificationDefinitions] d
    ON d.[SpecificationDefinitionId] = vs.[SpecificationDefinitionId]
WHERE p.[InternalCode] = :code
  AND p.[DeletedAtUtc] IS NULL
  AND d.[DefinitionCode] = N'CURRENT'
ORDER BY pv.[VariantCode]
SQL,
        [':code' => $code],
    );

    return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE));
}

$bv = requireProduct($db, 'BV');
$dv = requireProduct($db, 'DV');
$pj = requireProduct($db, 'PJ');
$up = requireProduct($db, 'UP');
echo "Resolved BV/DV/PJ/UP\n";

$bvaBefore = bvaFingerprint($db);
$currentBefore = [
    'BV' => currentSample($db, 'BV'),
    'DV' => currentSample($db, 'DV'),
    'PJ' => currentSample($db, 'PJ'),
    'UP' => currentSample($db, 'UP'),
];
echo "BVA fingerprint before={$bvaBefore}\n";

$beddingCreated = false;
$jacketPCreated = false;

$db->beginTransaction();
try {
    $bedding = loadDefinition($db, 'BEDDING');
    if ($bedding === null) {
        $clone = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[SpecificationDefinitions]
    ([DefinitionCode], [DefinitionName], [Description], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit],
     [DisplayOrder], [IsRequired], [IsFilterable], [IsSearchable], [IsComparable], [MinimumValue], [MaximumValue],
     [DecimalPrecision], [ValidationRulesJson], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationDefinitionId], CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
       INSERTED.[DataTypeCode]
SELECT
    N'BEDDING',
    N'Bedding',
    N'Catalogue construction — bedding',
    d.[SpecificationGroupId],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[Unit],
    15,
    d.[IsRequired],
    d.[IsFilterable],
    d.[IsSearchable],
    d.[IsComparable],
    d.[MinimumValue],
    d.[MaximumValue],
    d.[DecimalPrecision],
    d.[ValidationRulesJson],
    1,
    :createdBy,
    :updatedBy
FROM [product].[SpecificationDefinitions] d
WHERE d.[DefinitionCode] = N'CORE-INSULATION'
  AND d.[DeletedAtUtc] IS NULL
SQL,
            [':createdBy' => $actorUserId, ':updatedBy' => $actorUserId],
        );
        if ($clone === []) {
            throw new RuntimeException('Failed to clone BEDDING from CORE-INSULATION');
        }
        $bedding = [
            'SpecificationDefinitionId' => (int) $clone[0]['SpecificationDefinitionId'],
            'PublicId' => (string) $clone[0]['PublicId'],
            'DataTypeCode' => (string) $clone[0]['DataTypeCode'],
        ];
        $beddingCreated = true;
        echo "CREATED BEDDING publicId={$bedding['PublicId']} type={$bedding['DataTypeCode']}\n";
    } else {
        echo "REUSED BEDDING publicId={$bedding['PublicId']}\n";
    }

    $jacketP = loadDefinition($db, 'JACKET-OPTION-P');
    if ($jacketP === null) {
        $clone = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[SpecificationDefinitions]
    ([DefinitionCode], [DefinitionName], [Description], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit],
     [DisplayOrder], [IsRequired], [IsFilterable], [IsSearchable], [IsComparable], [MinimumValue], [MaximumValue],
     [DecimalPrecision], [ValidationRulesJson], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[SpecificationDefinitionId], CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
       INSERTED.[DataTypeCode], INSERTED.[DisplayOrder]
SELECT
    N'JACKET-OPTION-P',
    N'P Type',
    N'Jacket option P',
    d.[SpecificationGroupId],
    d.[DataTypeCode],
    d.[ScopeCode],
    d.[Unit],
    83,
    d.[IsRequired],
    d.[IsFilterable],
    d.[IsSearchable],
    d.[IsComparable],
    d.[MinimumValue],
    d.[MaximumValue],
    d.[DecimalPrecision],
    d.[ValidationRulesJson],
    1,
    :createdBy,
    :updatedBy
FROM [product].[SpecificationDefinitions] d
WHERE d.[DefinitionCode] = N'JACKET-OPTION-A'
  AND d.[DeletedAtUtc] IS NULL
SQL,
            [':createdBy' => $actorUserId, ':updatedBy' => $actorUserId],
        );
        if ($clone === []) {
            throw new RuntimeException('Failed to clone JACKET-OPTION-P from JACKET-OPTION-A');
        }
        $jacketP = [
            'SpecificationDefinitionId' => (int) $clone[0]['SpecificationDefinitionId'],
            'PublicId' => (string) $clone[0]['PublicId'],
            'DataTypeCode' => (string) $clone[0]['DataTypeCode'],
        ];
        $jacketPCreated = true;
        echo "CREATED JACKET-OPTION-P publicId={$jacketP['PublicId']} type={$jacketP['DataTypeCode']} order={$clone[0]['DisplayOrder']}\n";
    } else {
        echo "REUSED JACKET-OPTION-P publicId={$jacketP['PublicId']}\n";
    }

    $beddingId = (int) $bedding['SpecificationDefinitionId'];
    $jacketPId = (int) $jacketP['SpecificationDefinitionId'];
    $beddingType = (string) ($bedding['DataTypeCode'] ?? loadDefinition($db, 'BEDDING')['DataTypeCode']);
    $jacketPType = (string) ($jacketP['DataTypeCode'] ?? 'text');

    $pjAction = upsertTextValue(
        $db,
        (int) $pj['ProductId'],
        $beddingId,
        'Pressure Extruded LSZH compound',
        $beddingType,
        $actorUserId,
    );
    echo "PJ BEDDING {$pjAction}\n";

    $pValue = 'PUR/TPU, Oil & MUD Resistant';
    echo 'BV P Type ' . upsertTextValue($db, (int) $bv['ProductId'], $jacketPId, $pValue, $jacketPType, $actorUserId) . "\n";
    echo 'DV P Type ' . upsertTextValue($db, (int) $dv['ProductId'], $jacketPId, $pValue, $jacketPType, $actorUserId) . "\n";
    echo 'PJ P Type ' . upsertTextValue($db, (int) $pj['ProductId'], $jacketPId, $pValue, $jacketPType, $actorUserId) . "\n";

    $upP = $db->fetchAll(
        <<<'SQL'
SELECT v.[ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues] v
WHERE v.[ProductId] = :productId
  AND v.[SpecificationDefinitionId] = :definitionId
  AND v.[DeletedAtUtc] IS NULL
SQL,
        [':productId' => (int) $up['ProductId'], ':definitionId' => $jacketPId],
    );
    echo 'UP P Type assigned: ' . ($upP === [] ? 'NO' : 'YES ERROR') . "\n";

    $upBedding = $db->fetchAll(
        <<<'SQL'
SELECT v.[ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues] v
WHERE v.[ProductId] IN (
    SELECT [ProductId] FROM [product].[Products]
    WHERE [InternalCode] IN (N'BV', N'DV', N'UP') AND [DeletedAtUtc] IS NULL
)
  AND v.[SpecificationDefinitionId] = :definitionId
  AND v.[DeletedAtUtc] IS NULL
SQL,
        [':definitionId' => $beddingId],
    );
    echo 'BEDDING assigned to BV/DV/UP: ' . ($upBedding === [] ? 'NO' : 'YES ERROR count=' . count($upBedding)) . "\n";

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'ROLLBACK: ' . $e->getMessage() . "\n");
    exit(1);
}

$bvaAfter = bvaFingerprint($db);
echo 'BVA unchanged: ' . ($bvaBefore === $bvaAfter ? 'YES' : 'NO') . "\n";
foreach (['BV', 'DV', 'PJ', 'UP'] as $code) {
    $after = currentSample($db, $code);
    echo "CURRENT sample {$code} unchanged: " . ($currentBefore[$code] === $after ? 'YES' : 'NO') . "\n";
}

echo "\nBEDDING_CREATED=" . ($beddingCreated ? 'yes' : 'no') . " publicId={$bedding['PublicId']}\n";
echo "JACKET-OPTION-P_CREATED=" . ($jacketPCreated ? 'yes' : 'no') . " publicId={$jacketP['PublicId']}\n";
echo "VARIANT_ROWS_CHANGED_THIS_PHASE=0\n";
echo "Done.\n";
