<?php

declare(strict_types=1);

/**
 * Phase 13.7F.1 — Diagnose BNE selected-customer pricing failure.
 * Usage: php bin/phase137f1-bne-pricing-diagnose.php
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
use TriCab\Core\Product\Application\Pricing\ResolveCustomerPricingContextService;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);
$container = ContainerFactory::create($settings);

/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
/** @var ResolvePricingActorContextService $actor */
$actor = $container->get(ResolvePricingActorContextService::class);
$dbcoSvc = new ResolvePricingDbcoService();

$variantCode = 'BVA-PJ2D/3C25BK';
$customerCode = 'BNE-ENR-001';

$customer = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, CustomerCode, CustomerName, CountryCode, TerritoryCode, MarketCode, StatusCode
     FROM [customer].[Customers] WHERE CustomerCode = :code',
    [':code' => $customerCode],
)[0] ?? null;

$variant = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), v.PublicId) AS publicId, v.VariantCode, p.InternalCode AS styleCode,
            (SELECT COUNT(*) FROM product.ProductVariantPrices vp WHERE vp.ProductVariantId = v.ProductVariantId AND vp.DeletedAtUtc IS NULL AND vp.IsActive = 1) AS priceCount
     FROM product.ProductVariants v
     INNER JOIN product.Products p ON p.ProductId = v.ProductId
     WHERE v.VariantCode = :code AND v.DeletedAtUtc IS NULL',
    [':code' => $variantCode],
)[0] ?? null;

$contact = null;
if ($customer !== null) {
    $contact = $db->fetchAll(
        "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId, cc.DisplayName, cc.IsActive
         FROM [customer].[CustomerContacts] cc
         INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
         WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
        [':code' => $customerCode],
    )[0] ?? null;
}

$out = [
    'customer' => $customer,
    'contact' => $contact,
    'variant' => $variant,
    'comparisons' => [],
];

if ($customer === null || $variant === null) {
    echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$buildCtx = static function (array $cust, ?string $override = null): CustomerPricingContext {
    $country = (string) ($cust['CountryCode'] ?? '');
    $sell = match (strtoupper($country)) {
        'NZ' => 'NZD',
        'US' => 'USD',
        default => 'AUD',
    };

    return new CustomerPricingContext(
        customerPublicId: (string) $cust['publicId'],
        countryCode: $country !== '' ? $country : null,
        territoryCode: $cust['TerritoryCode'] !== null ? (string) $cust['TerritoryCode'] : null,
        marketCode: $cust['MarketCode'] !== null ? (string) $cust['MarketCode'] : null,
        sellCurrency: $sell,
        authState: 'OK',
        pricingMode: CustomerPricingContext::MODE_STAFF_SELECTED,
        overrideMarginPercent: $override,
        customerCode: (string) $cust['CustomerCode'],
        customerDisplayName: (string) $cust['CustomerName'],
    );
};

$ph13 = $db->fetchAll(
    "SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, CustomerCode, CustomerName, CountryCode, TerritoryCode, MarketCode
     FROM [customer].[Customers] WHERE CustomerCode = N'PH13-PRICE-VALIDATION'",
)[0] ?? null;

foreach (['BNE' => $customer, 'PH13' => $ph13] as $label => $cust) {
    if ($cust === null) {
        continue;
    }
    $ctx = $buildCtx($cust);
    $dbco = $dbcoSvc->resolve($ctx, 'TCA-BASE');
    $result = $pricing->resolve((string) $variant['publicId'], $ctx, true, null, false, '100');
    $diag = $result instanceof CustomerVariantSellPriceDiagnostic ? $result : null;
    $out['comparisons'][$label] = [
        'country' => $ctx->countryCode,
        'territory' => $ctx->territoryCode,
        'market' => $ctx->marketCode,
        'sellCurrency' => $ctx->sellCurrency,
        'dbco' => $dbco,
        'status' => $diag?->customer->status ?? $result->status,
        'availability' => $diag?->customer->availability ?? $result->availability,
        'unitPrice' => $diag?->customer->unitSellPrice ?? ($result instanceof CustomerVariantSellPriceDiagnostic ? $result->customer->unitSellPrice : $result->unitSellPrice),
        'resolvedMargin' => $diag?->resolvedMarginPercent ?? null,
        'marginSource' => $diag?->marginSource ?? null,
        'trace' => $diag?->trace ?? [],
    ];
}

if ($contact !== null) {
    try {
        $auth = new AuthenticatedUser(
            userId: 1,
            publicId: '00000000-0000-0000-0000-000000000099',
            emailAddress: 'staff@test.local',
            displayName: 'Staff',
            status: 'active',
            roles: [],
            permissions: ['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.VIEW', 'PRODUCT.PRICING.OVERRIDE'],
        );
        $selected = $actor->selectCustomer($auth, (string) $customer['publicId'], (string) $contact['publicId']);
        $ctxFromToken = $actor->fromAuthenticatedUser($auth, $selected['pricingContextToken']);
        $result = $pricing->resolve((string) $variant['publicId'], $ctxFromToken, true, null, false, '100');
        $diag = $result instanceof CustomerVariantSellPriceDiagnostic ? $result : null;
        $out['tokenPath'] = [
            'tokenIssued' => true,
            'status' => $diag?->customer->status ?? $result->status,
            'unitPrice' => $diag?->customer->unitSellPrice ?? null,
            'trace' => $diag?->trace ?? [],
        ];
    } catch (Throwable $e) {
        $out['tokenPath'] = ['tokenIssued' => false, 'error' => $e->getMessage()];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
