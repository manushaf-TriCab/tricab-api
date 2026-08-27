<?php

declare(strict_types=1);

/**
 * Phase 13.7F.2 — Verify batch pricing per variant for BNE + margin override.
 * Usage: php bin/phase137f2-batch-verify.php
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

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$container = ContainerFactory::create(Environment::settings($root));

/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
/** @var ResolvePricingActorContextService $actor */
$actor = $container->get(ResolvePricingActorContextService::class);

$variantCodes = [
    'BVA-PJ2D/3C25BK',
    'BVA-CAXD/37C2.5BK',
    'BVA-CBXD/19C.75BK',
];

$cust = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, CustomerCode FROM [customer].[Customers] WHERE CustomerCode = :code',
    [':code' => 'BNE-ENR-001'],
)[0] ?? null;

$contact = null;
if ($cust !== null) {
    $contact = $db->fetchAll(
        "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId
         FROM [customer].[CustomerContacts] cc
         INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
         WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
        [':code' => 'BNE-ENR-001'],
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

$out = ['variants' => [], 'override10' => null, 'override31' => null];

if ($cust === null || $contact === null) {
    echo json_encode(['error' => 'BNE customer/contact not found', 'out' => $out], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$selected = $actor->selectCustomer($user, (string) $cust['publicId'], (string) $contact['publicId']);
$ctx = $actor->fromAuthenticatedUser($user, $selected['pricingContextToken']);

foreach ($variantCodes as $code) {
    $row = $db->fetchAll(
        'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, VariantCode
         FROM product.ProductVariants WHERE VariantCode = :code AND DeletedAtUtc IS NULL',
        [':code' => $code],
    )[0] ?? null;
    if ($row === null) {
        $out['variants'][] = ['variantCode' => $code, 'error' => 'NOT_FOUND'];
        continue;
    }

    $result = $pricing->resolve((string) $row['publicId'], $ctx, true, null, false, '100');
    $diag = $result instanceof CustomerVariantSellPriceDiagnostic ? $result : null;
    $staff = $diag?->toStaffArray();
    $out['variants'][] = [
        'variantCode' => $code,
        'matrixPublicId' => $row['publicId'],
        'responseVariantId' => $staff['customer']['variantId'] ?? null,
        'idCaseMatch' => strcasecmp((string) $row['publicId'], (string) ($staff['customer']['variantId'] ?? '')) === 0,
        'status' => $diag?->customer->status,
        'availability' => $diag?->customer->availability,
        'unitPrice' => $diag?->customer->unitSellPrice,
        'marginSource' => $diag?->marginSource,
        'resolvedMargin' => $diag?->resolvedMarginPercent,
        'trace' => array_values(array_filter(
            $diag?->trace ?? [],
            static fn (string $t): bool => str_contains($t, 'MARGIN')
                || str_contains($t, 'COST')
                || str_contains($t, 'NO_'),
        )),
    ];
}

$firstId = $out['variants'][0]['matrixPublicId'] ?? null;
if ($firstId !== null) {
    foreach ([10, 31] as $pct) {
        $ov = $actor->applyMarginOverride($user, $selected['pricingContextToken'], $pct);
        $ctxOv = $actor->fromAuthenticatedUser($user, $ov['pricingContextToken']);
        $r = $pricing->resolve((string) $firstId, $ctxOv, true, null, false, '100');
        $d = $r instanceof CustomerVariantSellPriceDiagnostic ? $r : null;
        $key = 'override' . $pct;
        $out[$key] = [
            'status' => $d?->customer->status,
            'unitPrice' => $d?->customer->unitSellPrice,
            'effectiveMargin' => $d?->marginPercent,
            'overrideInToken' => $ctxOv->overrideMarginPercent,
        ];
    }
}

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
