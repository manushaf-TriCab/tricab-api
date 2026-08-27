<?php

declare(strict_types=1);

/**
 * Phase 13.7F.3 — Verify margin override precedence for BVA-PAXD/2C35BK @ BNE.
 * Usage: php bin/phase137f3-margin-override-verify.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$container = ContainerFactory::create(Environment::settings($root));

/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
/** @var ResolvePricingActorContextService $actor */
$actor = $container->get(ResolvePricingActorContextService::class);
/** @var StaffPricingContextTokenCodec $codec */
$codec = $container->get(StaffPricingContextTokenCodec::class);

$variantCode = 'BVA-PAXD/2C35BK';
$customerCode = 'BNE-ENR-001';

$variant = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId FROM product.ProductVariants WHERE VariantCode = :code AND DeletedAtUtc IS NULL',
    [':code' => $variantCode],
)[0] ?? null;

$customer = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId FROM [customer].[Customers] WHERE CustomerCode = :code',
    [':code' => $customerCode],
)[0] ?? null;

$contact = null;
if ($customer !== null) {
    $contact = $db->fetchAll(
        "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId
         FROM [customer].[CustomerContacts] cc
         INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
         WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
        [':code' => $customerCode],
    )[0] ?? null;
}

$user = new AuthenticatedUser(
    userId: 1,
    publicId: '00000000-0000-0000-0000-000000000099',
    emailAddress: 'staff@test.local',
    displayName: 'Staff',
    status: 'active',
    roles: [],
    permissions: ['CUSTOMER.PRICE_AS', 'PRODUCT.PRICING.VIEW', 'PRODUCT.PRICING.OVERRIDE'],
);

$resolve = static function (ResolveCustomerSellPriceService $pricing, string $variantId, $ctx, string $label) use (&$out): array {
    $result = $pricing->resolve($variantId, $ctx, true, null, false, '100');
    $diag = $result instanceof CustomerVariantSellPriceDiagnostic ? $result : null;
    $row = [
        'label' => $label,
        'status' => $diag?->customer->status,
        'unitPrice' => $diag?->customer->unitSellPrice,
        'resolvedMargin' => $diag?->resolvedMarginPercent,
        'overrideMargin' => $diag?->overrideMarginPercent,
        'effectiveMargin' => $diag?->marginPercent,
        'marginSource' => $diag?->marginSource,
        'ctxOverride' => $ctx->overrideMarginPercent,
    ];
    $out['steps'][] = $row;

    return $row;
};

$out = ['variantCode' => $variantCode, 'steps' => []];

if ($variant === null || $customer === null || $contact === null) {
    echo json_encode(['error' => 'missing variant/customer/contact', 'out' => $out], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$selected = $actor->selectCustomer($user, (string) $customer['publicId'], (string) $contact['publicId']);
$ctxAuto = $actor->fromAuthenticatedUser($user, $selected['pricingContextToken']);
$auto = $resolve($pricing, (string) $variant['publicId'], $ctxAuto, 'automatic');

foreach ([3, 10, 31] as $pct) {
    $response = $actor->applyMarginOverride($user, $selected['pricingContextToken'], $pct);
    $decoded = $codec->decode($response['pricingContextToken']);
    $ctx = $actor->fromAuthenticatedUser($user, $response['pricingContextToken']);
    $resolve($pricing, (string) $variant['publicId'], $ctx, (string) $pct . '% override');
    $out['tokenChecks'][(string) $pct] = [
        'responseOverrideMargin' => $response['overrideMargin'] ?? null,
        'tokenPayloadOverride' => $decoded['overrideMargin'] ?? null,
        'ctxOverrideMarginPercent' => $ctx->overrideMarginPercent,
        'tokenChanged' => ($selected['pricingContextToken'] ?? '') !== ($response['pricingContextToken'] ?? ''),
    ];
}

$cleared = $actor->clearOverride($user, $selected['pricingContextToken']);
$ctxClear = $actor->fromAuthenticatedUser($user, $cleared['pricingContextToken']);
$afterClear = $resolve($pricing, (string) $variant['publicId'], $ctxClear, 'clear override');

$out['summary'] = [
    'autoPrice' => $auto['unitPrice'],
    'autoEffective' => $auto['effectiveMargin'],
    'afterClearPrice' => $afterClear['unitPrice'],
    'afterClearEffective' => $afterClear['effectiveMargin'],
    'autoMatchesClear' => $auto['unitPrice'] === $afterClear['unitPrice'],
];

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
