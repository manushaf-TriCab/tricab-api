<?php

declare(strict_types=1);

/**
 * Phase 13.7G — Runtime Legacy Cost API pricing verification (read-only).
 *
 * Usage: php bin/phase137g-runtime-cost-api-verify.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
$dbcoSvc = new ResolvePricingDbcoService();

$customerRow = null;
foreach (['BNE-ENR-001', 'PH13-PRICE-VALIDATION', 'SAMAUS'] as $customerCode) {
    $customerRow = $sql->fetchAll(
        'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS id, CustomerCode, CountryCode, TerritoryCode, MarketCode
         FROM [customer].[Customers] WHERE CustomerCode = :code',
        [':code' => $customerCode],
    )[0] ?? null;
    if ($customerRow !== null) {
        break;
    }
}
if ($customerRow === null) {
    fwrite(STDERR, "No pricing test customer found\n");
    exit(2);
}

$ctx = new CustomerPricingContext(
    customerPublicId: (string) $customerRow['id'],
    countryCode: (string) ($customerRow['CountryCode'] ?? 'AU'),
    territoryCode: (string) ($customerRow['TerritoryCode'] ?? 'VIC'),
    marketCode: (string) ($customerRow['MarketCode'] ?? 'ELE'),
    sellCurrency: 'AUD',
    authState: 'OK',
    pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
);

$publishedStyles = $sql->fetchAll(
    <<<'SQL'
SELECT TOP 20
    [StyleCode],
    MIN([ProductName]) AS [ProductName],
    MIN([Slug]) AS [Slug]
FROM (
    SELECT
        NULLIF(LTRIM(RTRIM(p.[InternalCode])), N'') AS [StyleCode],
        p.[ProductName],
        psSlug.[Slug]
    FROM [product].[Products] p
    INNER JOIN [product].[ProductSlugs] psSlug ON psSlug.[ProductId] = p.[ProductId] AND psSlug.[DeletedAtUtc] IS NULL AND psSlug.[IsPrimary] = 1
    INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
    WHERE p.[DeletedAtUtc] IS NULL
      AND p.[IsActive] = 1
      AND ps.[StatusCode] = N'published'
      AND psSlug.[Slug] IS NOT NULL
      AND NULLIF(LTRIM(RTRIM(p.[InternalCode])), N'') IS NOT NULL
    UNION
    SELECT
        LEFT(v.[VariantCode], NULLIF(CHARINDEX(N'-', v.[VariantCode]), 0) - 1) AS [StyleCode],
        p.[ProductName],
        psSlug.[Slug]
    FROM [product].[ProductVariants] v
    INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
    INNER JOIN [product].[ProductSlugs] psSlug ON psSlug.[ProductId] = p.[ProductId] AND psSlug.[DeletedAtUtc] IS NULL AND psSlug.[IsPrimary] = 1
    INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
    WHERE v.[DeletedAtUtc] IS NULL
      AND p.[DeletedAtUtc] IS NULL
      AND p.[IsActive] = 1
      AND ps.[StatusCode] = N'published'
      AND v.[VariantCode] LIKE N'%-%'
) styles
WHERE [StyleCode] IS NOT NULL AND LTRIM(RTRIM([StyleCode])) <> N''
GROUP BY [StyleCode]
ORDER BY [StyleCode]
SQL,
);

$extractDiag = static function (CustomerVariantSellPriceDiagnostic $diag): array {
    $trace = implode('|', $diag->trace);
    preg_match('/costSource=([A-Z_]+)/', $trace, $costSourceMatch);

    return [
        'status' => $diag->customer->status,
        'availability' => $diag->customer->availability,
        'unitPrice' => $diag->customer->unitSellPrice,
        'costSource' => $costSourceMatch[1] ?? (str_contains($trace, 'COST_SOURCE:LEGACY_COST_API') ? 'LEGACY_COST_API' : 'UNKNOWN'),
        'costApiCalled' => str_contains($trace, 'COST_API_CALL'),
        'cost' => $diag->baseCost,
        'resolvedMargin' => $diag->resolvedMarginPercent,
        'overrideMargin' => $diag->overrideMarginPercent,
        'effectiveMargin' => $diag->marginPercent,
        'marginSource' => $diag->marginSource,
        'formulaPath' => $diag->formulaPath,
        'traceSummary' => array_slice($diag->trace, 0, 12),
    ];
};

$styleResults = [];
$seenStyles = [];
foreach ($publishedStyles as $styleRow) {
    $style = (string) ($styleRow['StyleCode'] ?? '');
    if ($style === '' || isset($seenStyles[$style])) {
        continue;
    }
    $seenStyles[$style] = true;

    $variants = $sql->fetchAll(
        <<<'SQL'
SELECT TOP 2
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
WHERE v.[DeletedAtUtc] IS NULL
  AND p.[DeletedAtUtc] IS NULL
  AND (
      p.[InternalCode] = :style
      OR v.[VariantCode] LIKE :likeStyle
  )
ORDER BY v.[VariantCode]
SQL,
        [
            ':style' => $style,
            ':likeStyle' => $style . '-%',
        ],
    );

    $variantRows = [];
    foreach ($variants as $variant) {
        $variantId = (string) $variant['VariantPublicId'];
        $code = (string) $variant['VariantCode'];
        $dbco = $dbcoSvc->resolve($ctx, 'TCA-BASE');
        $diag = $pricing->resolve($variantId, $ctx, true, null, false, '100');
        if (!$diag instanceof CustomerVariantSellPriceDiagnostic) {
            continue;
        }
        $variantRows[] = array_merge([
            'variantCode' => $code,
            'variantId' => $variantId,
            'published' => true,
            'dbco' => $dbco,
        ], $extractDiag($diag));
    }

    if ($variantRows !== []) {
        $styleResults[] = [
            'style' => $style,
            'productName' => $styleRow['ProductName'] ?? null,
            'slug' => $styleRow['Slug'] ?? null,
            'variants' => $variantRows,
        ];
    }

    if (count($styleResults) >= 5) {
        break;
    }
}

// Supplement with non-BVA catalog styles when tc has few published products beyond BVA.
$supplementStyles = $sql->fetchAll(
    <<<'SQL'
SELECT TOP 20
    LEFT(v.[VariantCode], CHARINDEX(N'-', v.[VariantCode]) - 1) AS [StyleCode],
    MIN(v.[VariantCode]) AS [SampleVariantCode]
FROM [product].[ProductVariants] v
WHERE v.[DeletedAtUtc] IS NULL
  AND v.[VariantCode] LIKE N'%-%'
  AND LEFT(v.[VariantCode], CHARINDEX(N'-', v.[VariantCode]) - 1) <> N'BVA'
  AND NOT EXISTS (
      SELECT 1
      FROM [product].[ProductVariantPrices] vp
      WHERE vp.[ProductVariantId] = v.[ProductVariantId]
        AND vp.[DeletedAtUtc] IS NULL
        AND vp.[IsActive] = 1
  )
GROUP BY LEFT(v.[VariantCode], CHARINDEX(N'-', v.[VariantCode]) - 1)
ORDER BY MIN(v.[VariantCode])
SQL,
);

foreach ($supplementStyles as $styleRow) {
    if (count($styleResults) >= 5) {
        break;
    }
    $style = (string) ($styleRow['StyleCode'] ?? '');
    if ($style === '' || isset($seenStyles[$style])) {
        continue;
    }
    $seenStyles[$style] = true;

    $variants = $sql->fetchAll(
        <<<'SQL'
SELECT TOP 2
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode]
FROM [product].[ProductVariants] v
WHERE v.[DeletedAtUtc] IS NULL
  AND v.[VariantCode] LIKE :likeStyle
  AND NOT EXISTS (
      SELECT 1
      FROM [product].[ProductVariantPrices] vp
      WHERE vp.[ProductVariantId] = v.[ProductVariantId]
        AND vp.[DeletedAtUtc] IS NULL
        AND vp.[IsActive] = 1
  )
ORDER BY v.[VariantCode]
SQL,
        [':likeStyle' => $style . '-%'],
    );

    $variantRows = [];
    foreach ($variants as $variant) {
        $variantId = (string) $variant['VariantPublicId'];
        $code = (string) $variant['VariantCode'];
        $dbco = $dbcoSvc->resolve($ctx, 'TCA-BASE');
        $diag = $pricing->resolve($variantId, $ctx, true, null, false, '100');
        if (!$diag instanceof CustomerVariantSellPriceDiagnostic) {
            continue;
        }
        $variantRows[] = array_merge([
            'variantCode' => $code,
            'variantId' => $variantId,
            'published' => false,
            'dbco' => $dbco,
        ], $extractDiag($diag));
    }

    if ($variantRows !== []) {
        $styleResults[] = [
            'style' => $style,
            'productName' => null,
            'slug' => null,
            'catalogSupplement' => true,
            'variants' => $variantRows,
        ];
    }
}

// Ensure BVA is first if present
usort($styleResults, static fn (array $a, array $b): int => ($a['style'] === 'BVA' ? 0 : 1) <=> ($b['style'] === 'BVA' ? 0 : 1));

$bvaVariantId = null;
foreach ($styleResults as $row) {
    if (($row['style'] ?? '') === 'BVA' && isset($row['variants'][0]['variantId'])) {
        $bvaVariantId = $row['variants'][0]['variantId'];
        break;
    }
}

$lengthMatrix = [];
if ($bvaVariantId !== null) {
    foreach (['1', '10', '100'] as $len) {
        $diag = $pricing->resolve($bvaVariantId, $ctx, true, null, false, $len);
        if ($diag instanceof CustomerVariantSellPriceDiagnostic) {
            $lengthMatrix[$len] = $extractDiag($diag);
        }
    }
}

$batchIds = [];
foreach ($styleResults as $row) {
    foreach ($row['variants'] as $variant) {
        $batchIds[] = $variant['variantId'];
    }
}
$batchIds = array_values(array_unique(array_slice($batchIds, 0, 10)));

$batchTimings = [];
foreach ([1, 5, min(10, count($batchIds))] as $count) {
    if ($count === 0) {
        continue;
    }
    $ids = array_slice($batchIds, 0, $count);
    $start = hrtime(true);
    foreach ($ids as $id) {
        $pricing->resolve($id, $ctx, false, null, false, '100');
    }
    $batchTimings[(string) $count] = round((hrtime(true) - $start) / 1_000_000, 1);
}

$report = [
    'decision' => 'PHASE_13_7G_PENDING',
    'environment' => [
        'appEnv' => $settings->appEnv,
        'legacyCostApiEnabled' => $settings->isLegacyCostApiEnabled(),
        'pricingCostSource' => $settings->pricingCostSource,
        'legacyCostApiPrimary' => $settings->isLegacyCostApiPrimaryCostSource(),
        'legacyCostApiUrl' => $settings->legacyCostApiUrl,
    ],
    'customer' => [
        'code' => $customerRow['CustomerCode'] ?? null,
        'id' => $customerRow['id'] ?? null,
    ],
    'styleSamples' => $styleResults,
    'lengthMatrix' => $lengthMatrix,
    'batchTimingsMs' => $batchTimings,
    'dbWrites' => 0,
];

$allCostApi = true;
$hasBva = false;
$bvaUsesLegacy = false;
$nonBvaCount = 0;
$nonBvaLegacy = 0;
$nonBvaVariantTotal = 0;
foreach ($styleResults as $row) {
    $isBva = ($row['style'] ?? '') === 'BVA';
    if ($isBva) {
        $hasBva = true;
    } else {
        ++$nonBvaCount;
        $nonBvaVariantTotal += count($row['variants']);
    }
    foreach ($row['variants'] as $variant) {
        if (($variant['costSource'] ?? '') !== 'LEGACY_COST_API') {
            $allCostApi = false;
        }
        if ($isBva && ($variant['costSource'] ?? '') === 'LEGACY_COST_API') {
            $bvaUsesLegacy = true;
        }
        if (!$isBva && ($variant['costSource'] ?? '') === 'LEGACY_COST_API') {
            ++$nonBvaLegacy;
        }
    }
}

if (
    $settings->isLegacyCostApiPrimaryCostSource()
    && $hasBva
    && $bvaUsesLegacy
    && $nonBvaCount >= 4
    && $nonBvaLegacy === $nonBvaVariantTotal
    && $allCostApi
) {
    $report['decision'] = 'ALL_PUBLISHED_RUNTIME_COST_API_PRICING_READY';
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
