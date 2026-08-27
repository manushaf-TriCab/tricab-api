<?php

declare(strict_types=1);

/**
 * Phase 13.7H — read-only published product pricing coverage.
 *
 * Usage: php bin/phase137h-published-pricing-coverage.php
 *
 * Enumerates published storefront products, visible variants, publicId coverage,
 * Cost API resolvability, and optional HTTP batch against localhost.
 * No writes.
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

$published = $sql->fetchAll(
    <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[InternalCode] AS [StyleCode],
    p.[ProductName],
    primarySlug.[Slug] AS [Slug],
    ps.[StatusCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductSlugs] primarySlug
    ON primarySlug.[ProductId] = p.[ProductId]
   AND primarySlug.[DeletedAtUtc] IS NULL
   AND primarySlug.[IsPrimary] = 1
WHERE p.[DeletedAtUtc] IS NULL
  AND p.[IsActive] = 1
  AND ps.[StatusCode] = N'published'
ORDER BY p.[InternalCode]
SQL
);

$extractDiag = static function (CustomerVariantSellPriceDiagnostic $diag): array {
    $trace = $diag->trace;

    return [
        'status' => $diag->customer->status,
        'availability' => $diag->customer->availability,
        'unitPrice' => $diag->customer->unitSellPrice,
        'variantCode' => $diag->customer->variantCode,
        'costSource' => str_contains(implode('|', $trace), 'COST_SOURCE:LEGACY_COST_API') ? 'LEGACY_COST_API' : 'UNKNOWN',
        'costApiCalled' => str_contains(implode('|', $trace), 'COST_API_CALL'),
        'cost' => $diag->baseCost,
        'resolvedMargin' => $diag->resolvedMarginPercent,
        'overrideMargin' => $diag->overrideMarginPercent,
        'effectiveMargin' => $diag->marginPercent,
        'trace' => array_slice($trace, 0, 14),
    ];
};

$products = [];
$sampledVariants = 0;
$costApiAttempted = 0;
$costApiOk = 0;
$eligibleProducts = 0;

foreach ($published as $product) {
    $productId = (string) $product['ProductPublicId'];
    $style = (string) ($product['StyleCode'] ?? '');
    $slug = trim((string) ($product['Slug'] ?? ''));
    $pathKey = $slug !== '' ? $slug : $productId;

    $variants = $sql->fetchAll(
        <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode],
    v.[Sku],
    v.[IsActive],
    ps.[StatusCode],
    CASE WHEN EXISTS (
        SELECT 1 FROM [product].[ProductVariantPrices] vp
        WHERE vp.[ProductVariantId] = v.[ProductVariantId]
          AND vp.[DeletedAtUtc] IS NULL
          AND vp.[IsActive] = 1
    ) THEN 1 ELSE 0 END AS [HasAzurePrice]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = v.[ProductStatusId]
WHERE p.[PublicId] = :productId
  AND v.[DeletedAtUtc] IS NULL
  AND v.[IsActive] = 1
ORDER BY v.[DisplayOrder], v.[VariantCode]
SQL,
        [':productId' => $productId],
    );

    $visible = count($variants);
    $withPublicId = 0;
    $batchEligible = 0;
    foreach ($variants as $variant) {
        $id = trim((string) ($variant['VariantPublicId'] ?? ''));
        $code = trim((string) ($variant['VariantCode'] ?? $variant['Sku'] ?? ''));
        if ($id !== '') {
            ++$withPublicId;
        }
        if ($id !== '' && $code !== '') {
            ++$batchEligible;
        }
    }

    $sample = [];
    $sizeGroup = $sql->fetchAll(
        <<<'SQL'
SELECT TOP 8
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
WHERE p.[PublicId] = :productId
  AND v.[DeletedAtUtc] IS NULL
  AND v.[IsActive] = 1
  AND (
      v.[VariantCode] LIKE N'%/4P.75%'
      OR v.[VariantName] LIKE N'%4P 0.75%'
      OR v.[VariantCode] LIKE N'%/10C%'
      OR v.[VariantCode] LIKE N'%/2C%'
      OR v.[VariantCode] LIKE N'%-%'
  )
ORDER BY
    CASE WHEN v.[VariantCode] LIKE N'%/4P.75%' THEN 0 ELSE 1 END,
    v.[VariantCode]
SQL,
        [':productId' => $productId],
    );
    if ($sizeGroup === []) {
        $sizeGroup = array_slice($variants, 0, 2);
    } else {
        $sizeGroup = array_slice($sizeGroup, 0, 6);
    }

    $dbco = $dbcoSvc->resolve($ctx, 'TCA-BASE');
    foreach ($sizeGroup as $variant) {
        $variantId = (string) $variant['VariantPublicId'];
        $code = (string) ($variant['VariantCode'] ?? '');
        ++$sampledVariants;
        ++$costApiAttempted;
        $start = hrtime(true);
        $diag = $pricing->resolve($variantId, $ctx, true, null, false, '100');
        $ms = round((hrtime(true) - $start) / 1_000_000, 1);
        if (!$diag instanceof CustomerVariantSellPriceDiagnostic) {
            $sample[] = [
                'variantCode' => $code,
                'variantId' => $variantId,
                'status' => $diag->status,
                'costApiMs' => $ms,
                'dbco' => $dbco,
            ];
            continue;
        }
        $row = $extractDiag($diag);
        if ($row['costApiCalled']) {
            ++$costApiOk;
        }
        $sample[] = array_merge([
            'variantCode' => $code,
            'variantId' => $variantId,
            'dbco' => $dbco,
            'costApiMs' => $ms,
            'hasAzurePrice' => false,
        ], $row);
    }

    $uiEligible = $pathKey !== '' && $batchEligible === $visible && $visible > 0;
    if ($uiEligible) {
        ++$eligibleProducts;
    }

    $products[] = [
        'style' => $style,
        'productName' => $product['ProductName'] ?? null,
        'productPublicId' => $productId,
        'slug' => $slug !== '' ? $slug : null,
        'pricingPathKey' => $pathKey,
        'visibleVariantCount' => $visible,
        'variantsWithPublicId' => $withPublicId,
        'pricingBatchEligible' => $batchEligible,
        'pricingUiEligible' => $uiEligible,
        'samples' => $sample,
    ];
}

$http = [
    'attempted' => false,
    'baseUrl' => rtrim((string) (getenv('PHASE137_API_BASE') ?: 'http://localhost:8080'), '/'),
];

$rca = null;
foreach ($products as $row) {
    if (strcasecmp((string) $row['style'], 'RCA') === 0) {
        $rca = $row;
        break;
    }
}

if ($rca !== null) {
    $contact = $sql->fetchAll(
        "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId
         FROM [customer].[CustomerContacts] cc
         INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
         WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
        [':code' => (string) $customerRow['CustomerCode']],
    )[0] ?? null;

    $ids = array_values(array_filter(array_map(
        static fn (array $item): string => (string) ($item['variantId'] ?? ''),
        $rca['samples'],
    )));

    if ($contact !== null && $ids !== []) {
        $http['attempted'] = true;
        $devHeaders = [
            'X-Dev-Entra-Tenant: development-tenant',
            'X-Dev-Entra-Subject: development-thilan',
            'X-Dev-Entra-Email: thilanh@tricab.com',
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $post = static function (string $path, array $payload, array $extra = []) use ($http, $devHeaders): array {
            $ch = curl_init($http['baseUrl'] . $path);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array_merge($devHeaders, $extra),
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_TIMEOUT => 120,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['status' => $status, 'json' => json_decode((string) $body, true)];
        };

        $selected = $post('/api/v1/pricing/customer-context', [
            'customerId' => (string) $customerRow['id'],
            'contactId' => (string) $contact['publicId'],
        ]);
        $token = (string) ($selected['json']['data']['pricingContextToken'] ?? '');
        $pathKey = rawurlencode((string) $rca['pricingPathKey']);
        $batchStart = hrtime(true);
        $auto = $post("/api/v1/products/{$pathKey}/variants/pricing", [
            'variantIds' => $ids,
            'includeDiagnostic' => true,
            'pricingContextToken' => $token,
            'length' => '100',
        ], $token !== '' ? ["X-Pricing-Context-Token: {$token}"] : []);
        $batchMs = round((hrtime(true) - $batchStart) / 1_000_000, 1);

        $override = $token !== ''
            ? $post('/api/v1/pricing/margin-override', [
                'pricingContextToken' => $token,
                'margin' => 31,
            ])
            : ['status' => 0, 'json' => null];
        $overrideToken = (string) ($override['json']['data']['pricingContextToken'] ?? '');
        $overrideBatch = $overrideToken !== ''
            ? $post("/api/v1/products/{$pathKey}/variants/pricing", [
                'variantIds' => $ids,
                'includeDiagnostic' => true,
                'pricingContextToken' => $overrideToken,
                'length' => '100',
            ], ["X-Pricing-Context-Token: {$overrideToken}"])
            : ['status' => 0, 'json' => null];

        $http['rca'] = [
            'pathKey' => $rca['pricingPathKey'],
            'variantCount' => count($ids),
            'customerContextHttp' => $selected['status'],
            'automaticHttp' => $auto['status'],
            'automaticBatchMs' => $batchMs,
            'automaticItems' => $auto['json']['data']['items'] ?? [],
            'override31Http' => $override['status'],
            'override31BatchHttp' => $overrideBatch['status'],
            'override31Items' => $overrideBatch['json']['data']['items'] ?? [],
        ];
    }
}

$report = [
    'phase' => '13.7H',
    'dbWrites' => 0,
    'environment' => [
        'appEnv' => $settings->appEnv,
        'pricingCostSource' => $settings->pricingCostSource,
        'legacyCostApiPrimary' => $settings->isLegacyCostApiPrimaryCostSource(),
    ],
    'customer' => [
        'code' => $customerRow['CustomerCode'] ?? null,
        'id' => $customerRow['id'] ?? null,
    ],
    'publishedProductCount' => count($published),
    'publishedStyles' => array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['style'] ?? ''),
        $products,
    ))),
    'pricingUiEligible' => $eligibleProducts . '/' . count($published),
    'variantsSampled' => $sampledVariants,
    'costApiAttempted' => $costApiAttempted,
    'costApiCalled' => $costApiOk,
    'products' => $products,
    'http' => $http,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
