<?php

declare(strict_types=1);

/**
 * Phase 13.7F.5 — Variant-specific pricing diagnosis (read-only).
 *
 * Usage:
 *   php bin/phase137f5-variant-pricing-diagnose.php [customerCode] [overrideMargin] [length]
 *
 * Defaults: BNE-ENR-001, 44, 100
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Api\Identity\Persistence\DevelopmentUserManagementCatalog;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService;
use TriCab\Core\Product\Application\Pricing\VariantCommercialCatalogRepository;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$container = ContainerFactory::create(Environment::settings($root));

/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
/** @var ResolvePricingActorContextService $actor */
$actor = $container->get(ResolvePricingActorContextService::class);
/** @var VariantCommercialCatalogRepository $catalog */
$catalog = $container->get(VariantCommercialCatalogRepository::class);
/** @var LegacyCostCalculationGateway|null $legacyCost */
$legacyCost = $container->has(LegacyCostCalculationGateway::class)
    ? $container->get(LegacyCostCalculationGateway::class)
    : null;

$customerCode = $argv[1] ?? 'BNE-ENR-001';
$overrideMargin = isset($argv[2]) && $argv[2] !== '' ? (string) $argv[2] : '44';
$length = $argv[3] ?? '100';

$variantCodes = [
    'BVA-PJXN/5C1.5BK', // primary failing
    'BVA-PAXA/5C50BK',  // control working
    'BVA-PDXD/5C1.5BK',
    'BVA-PHXD/5C1.5BK',
    'BVA-PJ2D/5C1.5BK',
    'BVA-PJXD/5C1.5BK',
    'BVA-PAXD/5C2.5BK',
    'BVA-PAXD/5C4BK',
    // full 5C 1.5mm² sibling set
    'BVA-CAXD/5C1.5BK',
    'BVA-CAXN/5C1.5BK',
    'BVA-CB2D/5C1.5BK',
    'BVA-CBXD/5C1.5BK',
    'BVA-PAXD/5C1.5BK',
    'BVA-PAXN/5C1.5BK',
];

$baseUrl = rtrim(getenv('PHASE137_API_BASE') ?: 'http://localhost:8080', '/');
$devHeaders = [
    'X-Dev-Entra-Tenant: development-tenant',
    'X-Dev-Entra-Subject: development-thilan',
    'X-Dev-Entra-Email: thilanh@tricab.com',
    'Content-Type: application/json',
    'Accept: application/json',
];

$post = static function (string $path, array $payload, array $extraHeaders = []) use ($baseUrl, $devHeaders): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($devHeaders, $extraHeaders),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return ['status' => $status, 'json' => json_decode((string) $body, true)];
};

$customer = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, CustomerCode, CustomerName, CountryCode, TerritoryCode, MarketCode
     FROM [customer].[Customers] WHERE CustomerCode = :code',
    [':code' => $customerCode],
)[0] ?? null;

$contact = $db->fetchAll(
    "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId, cc.DisplayName
     FROM [customer].[CustomerContacts] cc
     INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
     WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
    [':code' => $customerCode],
)[0] ?? null;

if ($customer === null) {
    echo json_encode(['error' => 'customer not found'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$auth = new AuthenticatedUser(
    userId: 1,
    publicId: DevelopmentUserManagementCatalog::USER_PUBLIC_ID,
    emailAddress: DevelopmentUserManagementCatalog::USER_EMAIL,
    displayName: DevelopmentUserManagementCatalog::USER_DISPLAY_NAME,
    status: 'active',
    roles: [],
    permissions: ['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.VIEW', 'PRODUCT.PRICING.OVERRIDE'],
);

$selected = $actor->selectCustomer($auth, (string) $customer['publicId'], $contact !== null ? (string) $contact['publicId'] : null);
$overridden = $actor->applyMarginOverride($auth, $selected['pricingContextToken'], (int) $overrideMargin);
$token = (string) $overridden['pricingContextToken'];
$ctx = $actor->fromAuthenticatedUser($auth, $token);

$dbcoSvc = new ResolvePricingDbcoService();
$dbco = $dbcoSvc->resolve($ctx, 'TCA-BASE');

$variants = [];
foreach ($variantCodes as $code) {
    $row = $db->fetchAll(
        <<<'SQL'
        SELECT TOP 1 CONVERT(NVARCHAR(36), v.PublicId) AS publicId, v.VariantCode, v.Sku, p.InternalCode AS styleCode,
                eem.ExternalEntityKey AS explicitLegacyCode, eem.IsActive AS explicitLegacyActive,
                (SELECT COUNT(*) FROM product.ProductVariantPrices vp WHERE vp.ProductVariantId = v.ProductVariantId AND vp.DeletedAtUtc IS NULL AND vp.IsActive = 1) AS azurePriceCount
         FROM product.ProductVariants v
         INNER JOIN product.Products p ON p.ProductId = v.ProductId
         LEFT JOIN product.ExternalEntityMappings eem ON eem.InternalEntityId = v.ProductVariantId AND eem.EntityTypeCode = N'VARIANT_LEGACY_PRODUCT_CODE' AND eem.DeletedAtUtc IS NULL
         LEFT JOIN product.ExternalSystems es ON es.ExternalSystemId = eem.ExternalSystemId AND es.SystemCode = N'LEGACY_TC' AND es.DeletedAtUtc IS NULL
         WHERE v.VariantCode = :code AND v.DeletedAtUtc IS NULL
        SQL,
        [':code' => $code],
    )[0] ?? null;

    if ($row === null) {
        $variants[$code] = ['error' => 'VARIANT_NOT_IN_DB'];
        continue;
    }

    $publicId = (string) $row['publicId'];
    $catalogEntry = $catalog->findCommercialCatalog($publicId, gmdate('Y-m-d'));
    $legacyCode = $catalogEntry['legacyProductCode'] ?? null;
    $costApiResults = [];
    if ($legacyCost !== null && $legacyCode !== null && $dbco !== null) {
        foreach (['1', '10', '100'] as $len) {
            $cost = $legacyCost->resolveCost((string) $legacyCode, $dbco, $len);
            $costApiResults[$len] = [
                'called' => true,
                'productCode' => $legacyCode,
                'dbco' => $dbco,
                'length' => $len,
                'success' => $cost->success,
                'status' => $cost->status,
                'failureReason' => $cost->failureReason,
                'defaultCost' => $cost->defaultCost,
                'calculatedCostPerMeter' => $cost->calculatedCostPerMeter,
                'effectiveCost' => $cost->effectiveCostPerUnit(),
            ];
        }
    }

    $resolverResults = [];
    foreach (['1', '10', '100'] as $len) {
        $result = $pricing->resolve($publicId, $ctx, true, null, false, $len);
        $diag = $result instanceof CustomerVariantSellPriceDiagnostic ? $result : null;
        $resolverResults[$len] = [
            'status' => $diag?->customer->status ?? $result->status,
            'availability' => $diag?->customer->availability ?? $result->availability,
            'unitPrice' => $diag?->customer->unitSellPrice ?? null,
            'resolvedMargin' => $diag?->resolvedMarginPercent ?? null,
            'overrideMargin' => $diag?->overrideMarginPercent ?? null,
            'effectiveMargin' => $diag?->marginPercent ?? null,
            'marginSource' => $diag?->marginSource ?? null,
            'baseCost' => $diag?->baseCost ?? null,
            'formulaPath' => $diag?->formulaPath ?? null,
            'trace' => $diag?->trace ?? [],
        ];
    }

    $variants[$code] = [
        'publicId' => $publicId,
        'variantCode' => $row['VariantCode'],
        'sku' => $row['Sku'],
        'styleCode' => $row['styleCode'],
        'explicitLegacyCode' => $row['explicitLegacyCode'],
        'azurePriceCount' => (int) $row['azurePriceCount'],
        'catalogLegacyProductCode' => $legacyCode,
        'dbco' => $dbco,
        'costApi' => $costApiResults,
        'resolver' => $resolverResults,
    ];
}

// HTTP batch for all variants at requested length (mirrors browser)
$batchVariantIds = [];
foreach ($variantCodes as $code) {
    if (isset($variants[$code]['publicId'])) {
        $batchVariantIds[] = $variants[$code]['publicId'];
    }
}

$httpBatch = $post('/api/v1/products/flexible-control-lszh-3d-test/variants/pricing', [
    'variantIds' => $batchVariantIds,
    'includeDiagnostic' => true,
    'pricingContextToken' => $token,
    'length' => $length,
], ["X-Pricing-Context-Token: {$token}"]);

if ($httpBatch['status'] !== 200) {
    // Re-issue token via HTTP so dev middleware actor matches batch request.
    $httpCtx = $post('/api/v1/pricing/customer-context', [
        'customerId' => (string) $customer['publicId'],
        'contactId' => $contact !== null ? (string) $contact['publicId'] : null,
    ]);
    $httpToken = (string) ($httpCtx['json']['data']['pricingContextToken'] ?? '');
    $httpOverride = $post('/api/v1/pricing/margin-override', [
        'pricingContextToken' => $httpToken,
        'margin' => (int) $overrideMargin,
    ]);
    $httpToken = (string) ($httpOverride['json']['data']['pricingContextToken'] ?? $httpToken);
    $httpBatch = $post('/api/v1/products/flexible-control-lszh-3d-test/variants/pricing', [
        'variantIds' => $batchVariantIds,
        'includeDiagnostic' => true,
        'pricingContextToken' => $httpToken,
        'length' => $length,
    ], ["X-Pricing-Context-Token: {$httpToken}"]);
}

$httpItems = [];
foreach ($httpBatch['json']['data']['items'] ?? [] as $item) {
    $customerItem = is_array($item['customer'] ?? null) ? $item['customer'] : $item;
    $diag = is_array($item['diagnostic'] ?? null) ? $item['diagnostic'] : [];
    $vid = strtoupper((string) ($customerItem['variantId'] ?? ''));
    $code = null;
    foreach ($variants as $vc => $meta) {
        if (isset($meta['publicId']) && strtoupper((string) $meta['publicId']) === $vid) {
            $code = $vc;
            break;
        }
    }
    $httpItems[$code ?? $vid] = [
        'variantId' => $customerItem['variantId'] ?? null,
        'status' => $customerItem['status'] ?? null,
        'unitPrice' => $customerItem['unitPrice'] ?? null,
        'displayMessage' => $customerItem['displayMessage'] ?? null,
        'availability' => $customerItem['availability'] ?? null,
        'resolvedMargin' => $diag['resolvedMarginPercent'] ?? null,
        'overrideMargin' => $diag['overrideMarginPercent'] ?? null,
        'effectiveMargin' => $diag['marginPercent'] ?? null,
        'baseCost' => $diag['baseCost'] ?? null,
        'formulaPath' => $diag['formulaPath'] ?? null,
        'trace' => $diag['trace'] ?? [],
    ];
}

echo json_encode([
    'customerCode' => $customerCode,
    'contact' => $contact['DisplayName'] ?? null,
    'overrideMargin' => $overrideMargin,
    'length' => $length,
    'contextOverrideMargin' => $ctx->overrideMarginPercent,
    'dbco' => $dbco,
    'variants' => $variants,
    'httpBatch' => [
        'status' => $httpBatch['status'],
        'error' => $httpBatch['json']['error'] ?? null,
        'items' => $httpItems,
    ],
], JSON_PRETTY_PRINT) . PHP_EOL;
