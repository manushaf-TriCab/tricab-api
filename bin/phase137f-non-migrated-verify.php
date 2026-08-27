<?php

declare(strict_types=1);

/**
 * Phase 13.7F — Non-migrated variant Cost API pricing verification.
 * Usage: php bin/phase137f-non-migrated-verify.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);

if (strtolower($settings->appEnv) === 'production' || $settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);

foreach (['PH13-PRICE-VALIDATION', 'SAMAUS'] as $customerCode) {
    $customerRow = $sql->fetchAll(
        'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS id, CountryCode, TerritoryCode, MarketCode FROM [customer].[Customers] WHERE CustomerCode = :code',
        [':code' => $customerCode],
    )[0] ?? null;
    if ($customerRow !== null) {
        break;
    }
}
if ($customerRow === null) {
    fwrite(STDERR, "No pricing test customer found (PH13-PRICE-VALIDATION / SAMAUS)\n");
    exit(2);
}

$customerId = (string) $customerRow['id'];

$ctx = new CustomerPricingContext(
    customerPublicId: $customerId,
    countryCode: (string) ($customerRow['CountryCode'] ?? 'AU'),
    territoryCode: (string) ($customerRow['TerritoryCode'] ?? 'VIC'),
    marketCode: (string) ($customerRow['MarketCode'] ?? 'CONSULT1'),
    sellCurrency: 'AUD',
    authState: 'OK',
);

$candidates = $sql->fetchAll(
    <<<'SQL'
SELECT TOP 30
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode]
FROM [product].[ProductVariants] v
WHERE v.[DeletedAtUtc] IS NULL
  AND NULLIF(LTRIM(RTRIM(v.[VariantCode])), N'') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM [product].[ProductVariantPrices] vp
      WHERE vp.[ProductVariantId] = v.[ProductVariantId]
        AND vp.[DeletedAtUtc] IS NULL
        AND vp.[IsActive] = 1
  )
ORDER BY v.[VariantCode]
SQL,
);

$passed = [];
foreach ($candidates as $row) {
    if (count($passed) >= 3) {
        break;
    }
    $variantId = (string) $row['VariantPublicId'];
    $code = (string) $row['VariantCode'];

    $probe = $pricing->resolve($variantId, $ctx, true, null, false, null);
    if (!$probe instanceof CustomerVariantSellPriceDiagnostic) {
        continue;
    }
    $trace = implode('|', $probe->trace);
    if (!str_contains($trace, 'LEGACY_COST_API')) {
        continue;
    }

    $length = '100';
    $resolved = $pricing->resolve($variantId, $ctx, true, null, false, $length);
    if (!$resolved instanceof CustomerVariantSellPriceDiagnostic) {
        continue;
    }
    if (!in_array($resolved->customer->status, [CustomerSellPriceStatus::RESOLVED, CustomerSellPriceStatus::TBC], true)) {
        continue;
    }
    if ($resolved->customer->status !== CustomerSellPriceStatus::RESOLVED || $resolved->customer->unitSellPrice === null) {
        continue;
    }

    $passed[] = [
        'variantPublicId' => $variantId,
        'variantCode' => $code,
        'availability' => $resolved->customer->availability,
        'unitSellPrice' => $resolved->customer->unitSellPrice,
        'costSource' => str_contains(implode('|', $resolved->trace), 'LEGACY_COST_API') ? 'LEGACY_COST_API' : 'UNKNOWN',
    ];
}

echo json_encode([
    'nonMigratedCandidatesChecked' => count($candidates),
    'passed' => $passed,
    'decision' => count($passed) >= 3 ? 'NON_MIGRATED_COST_API_PRICING_PASS' : 'NON_MIGRATED_COST_API_PRICING_BLOCKED',
], JSON_PRETTY_PRINT) . PHP_EOL;
