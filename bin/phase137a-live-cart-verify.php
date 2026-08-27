<?php

declare(strict_types=1);

/**
 * Phase 13.7A — safety + schema + optional live cart add (NON-PRODUCTION).
 *
 * Usage:
 *   php bin/phase137a-live-cart-verify.php
 *   php bin/phase137a-live-cart-verify.php --add
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Commerce\Application\Service\AddCartItemService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$doAdd = in_array('--add', $argv, true);
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$env = $db->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName')[0] ?? [];
$server = (string) ($env['serverName'] ?? '');
$database = (string) ($env['dbName'] ?? '');
$user = (string) ($env['userName'] ?? '');

echo "APP_ENV={$settings->appEnv}\n";
echo "SERVER={$server}\n";
echo "DB={$database}\n";
echo "USER={$user}\n";
echo "Production=NO\n";

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc\n");
    exit(4);
}

$pricingCounts = [];
foreach ([
    'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
    'ProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL',
    'CustomerProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[CustomerProductPrices]',
    'PricingTiers' => 'SELECT COUNT(*) AS c FROM [product].[PricingTiers]',
    'PriceListCustomers' => 'SELECT COUNT(*) AS c FROM [product].[PriceListCustomers]',
] as $name => $sql) {
    try {
        $pricingCounts[$name] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
    } catch (Throwable) {
        $pricingCounts[$name] = null;
    }
}

$pilot = $db->fetchAll("
SELECT CONVERT(NVARCHAR(36), pvp.ProductVariantPriceId) AS priceId,
       CONVERT(NVARCHAR(36), v.PublicId) AS variantPublicId,
       v.VariantCode,
       pl.PriceListCode,
       cur.CurrencyCode,
       CONVERT(NVARCHAR(100), pvp.ErpSourceReference) AS erpRef,
       CONVERT(NVARCHAR(50), pvp.UnitPrice) AS unitPrice
FROM [product].[ProductVariantPrices] pvp
INNER JOIN [product].[ProductVariants] v ON v.ProductVariantId = pvp.ProductVariantId
INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = pvp.PriceListId
INNER JOIN [product].[Currencies] cur ON cur.CurrencyId = pl.CurrencyId
WHERE pl.PriceListCode = N'TCA-BASE'
  AND v.VariantCode LIKE N'BVA-%'
  AND pvp.DeletedAtUtc IS NULL
ORDER BY v.VariantCode
");
$batchMatches = 0;
foreach ($pilot as $row) {
    if (str_contains((string) $row['erpRef'], 'p134-tca-BVA-20260826T010912')) {
        ++$batchMatches;
    }
}
$checksum = hash('sha256', json_encode(array_map(static fn (array $r): array => [
    'id' => $r['priceId'],
    'v' => $r['variantPublicId'],
    'code' => $r['VariantCode'],
    'pl' => $r['PriceListCode'],
    'ccy' => $r['CurrencyCode'],
    'ref' => $r['erpRef'],
    'up' => $r['unitPrice'],
], $pilot), JSON_THROW_ON_ERROR));

$schema = [
    'carts' => (int) ($db->fetchAll("SELECT CASE WHEN OBJECT_ID(N'[commerce].[Carts]', N'U') IS NULL THEN 0 ELSE 1 END AS c")[0]['c'] ?? 0),
    'lines' => (int) ($db->fetchAll("SELECT CASE WHEN OBJECT_ID(N'[commerce].[CartLines]', N'U') IS NULL THEN 0 ELSE 1 END AS c")[0]['c'] ?? 0),
    'lengthType' => (string) ($db->fetchAll("
        SELECT DATA_TYPE AS t FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=N'commerce' AND TABLE_NAME=N'CartLines' AND COLUMN_NAME=N'Length'
    ")[0]['t'] ?? ''),
    'openUx' => (int) ($db->fetchAll("
        SELECT CASE WHEN EXISTS (
          SELECT 1 FROM sys.indexes WHERE name = N'UX_commerce_Carts_Open_Owner_Customer_Mode_Currency'
            AND object_id = OBJECT_ID(N'[commerce].[Carts]')
        ) THEN 1 ELSE 0 END AS c
    ")[0]['c'] ?? 0),
];

$cartCounts = [
    'carts' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[Carts]')[0]['c'] ?? 0),
    'lines' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[CartLines]')[0]['c'] ?? 0),
    'openCarts' => (int) ($db->fetchAll("SELECT COUNT(*) AS c FROM [commerce].[Carts] WHERE Status=N'OPEN' AND DeletedAtUtc IS NULL")[0]['c'] ?? 0),
];

$out = [
    'safety' => [
        'appEnv' => $settings->appEnv,
        'server' => $server,
        'database' => $database,
        'user' => $user,
        'production' => 'NO',
        'legacyWrites' => 0,
    ],
    'pilot' => [
        'found' => count($pilot),
        'batchMatches' => $batchMatches,
        'checksum' => $checksum,
    ],
    'pricingCounts' => $pricingCounts,
    'schema' => $schema,
    'cartCountsBefore' => $cartCounts,
    'add' => null,
];

$schemaOk = $schema['carts'] === 1 && $schema['lines'] === 1
    && strtolower($schema['lengthType']) === 'decimal'
    && $schema['openUx'] === 1;

echo $schemaOk ? "CART_SCHEMA_APPLIED\n" : "CART_SCHEMA_FAILED\n";
echo 'Pilot BVA TCA-BASE rows=' . count($pilot) . " batchMatches={$batchMatches}\n";
echo 'Checksum=' . $checksum . "\n";

if (!$schemaOk) {
    file_put_contents($basePath . '/tmp/phase137a-live-verify.json', json_encode($out, JSON_PRETTY_PRINT));
    exit(1);
}

if ($doAdd) {
    $ph13 = $db->fetchAll("
SELECT TOP 1
  CONVERT(NVARCHAR(36), c.PublicId) AS customerPublicId,
  c.CustomerId,
  c.CustomerCode,
  c.CustomerName
FROM [customer].[Customers] c
WHERE c.CustomerCode = N'PH13-PRICE-VALIDATION'
");
    if ($ph13 === []) {
        fwrite(STDERR, "PH13-PRICE-VALIDATION customer not found\n");
        exit(1);
    }
    $row = $ph13[0];
    $u = $db->fetchAll("
SELECT TOP 1 UserId, CONVERT(NVARCHAR(36), PublicId) AS PublicId, EmailAddress, DisplayName
FROM [identity].[Users]
WHERE NormalizedEmailAddress = N'THILANH@TRICAB.COM'
");
    if ($u === []) {
        fwrite(STDERR, "User thilanh@tricab.com not found\n");
        exit(1);
    }
    $userId = (int) $u[0]['UserId'];
    $userPublicId = (string) $u[0]['PublicId'];
    $email = (string) $u[0]['EmailAddress'];
    $display = (string) $u[0]['DisplayName'];

    $variant = $db->fetchAll("
SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS PublicId, VariantCode
FROM [product].[ProductVariants]
WHERE VariantCode = N'BVA-CAXD/37C1.5BK'
");
    if ($variant === []) {
        fwrite(STDERR, "Variant BVA-CAXD/37C1.5BK not found\n");
        exit(1);
    }

    $auth = new AuthenticatedUser(
        userId: $userId,
        publicId: $userPublicId,
        emailAddress: $email,
        displayName: $display,
        status: 'ACTIVE',
        roles: ['STAFF'],
        permissions: ['CUSTOMER.PRICE_AS', 'CUSTOMER.READ', 'PRODUCT.PRICING.OVERRIDE', 'PRODUCT.PRICING.VIEW'],
    );

    /** @var StaffPricingContextTokenCodec $codec */
    $codec = $container->get(StaffPricingContextTokenCodec::class);
    $token = $codec->issue($userPublicId, (string) $row['customerPublicId'], null);

    /** @var AddCartItemService $add */
    $add = $container->get(AddCartItemService::class);
    $result = $add->execute(
        $auth,
        (string) $variant[0]['PublicId'],
        1,
        100,
        $token,
        null,
        '0.01',
    );

    $cartCountsAfter = [
        'carts' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[Carts]')[0]['c'] ?? 0),
        'lines' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[CartLines]')[0]['c'] ?? 0),
    ];

    $pricingAfter = [];
    foreach ([
        'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
        'ProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL',
        'CustomerProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[CustomerProductPrices]',
        'PricingTiers' => 'SELECT COUNT(*) AS c FROM [product].[PricingTiers]',
        'PriceListCustomers' => 'SELECT COUNT(*) AS c FROM [product].[PriceListCustomers]',
    ] as $name => $sql) {
        try {
            $pricingAfter[$name] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
        } catch (Throwable) {
            $pricingAfter[$name] = null;
        }
    }

    $line = $result['items'][0] ?? null;
    $out['add'] = [
        'accepted' => $result['accepted'] ?? false,
        'clientPriceIgnored' => $result['clientPriceIgnored'] ?? false,
        'clientPriceTrusted' => $result['clientPriceTrusted'] ?? false,
        'cartPersistence' => $result['cartPersistence'] ?? null,
        'unitPrice' => $line['unitPrice'] ?? null,
        'lineTotal' => $line['lineTotal'] ?? null,
        'currency' => $line['currency'] ?? null,
        'variantCode' => $line['variantCode'] ?? null,
        'cartId' => $result['cartId'] ?? null,
        'effectiveCustomer' => $result['effectiveCustomer'] ?? null,
        'cartCountsAfter' => $cartCountsAfter,
        'pricingDelta' => [
            'ProductVariantPrices' => ($pricingAfter['ProductVariantPrices'] ?? 0) - ($pricingCounts['ProductVariantPrices'] ?? 0),
            'ProductPrices' => ($pricingAfter['ProductPrices'] ?? 0) - ($pricingCounts['ProductPrices'] ?? 0),
            'CustomerProductPrices' => ($pricingAfter['CustomerProductPrices'] ?? 0) - ($pricingCounts['CustomerProductPrices'] ?? 0),
            'PricingTiers' => ($pricingAfter['PricingTiers'] ?? 0) - ($pricingCounts['PricingTiers'] ?? 0),
            'PriceListCustomers' => ($pricingAfter['PriceListCustomers'] ?? 0) - ($pricingCounts['PriceListCustomers'] ?? 0),
        ],
    ];

    echo "ADD accepted=" . (($result['accepted'] ?? false) ? 'YES' : 'NO') . "\n";
    echo 'unitPrice=' . ($line['unitPrice'] ?? 'null') . ' lineTotal=' . ($line['lineTotal'] ?? 'null') . "\n";
    echo 'clientPriceIgnored=' . (($result['clientPriceIgnored'] ?? false) ? 'YES' : 'NO') . "\n";
}

file_put_contents($basePath . '/tmp/phase137a-live-verify.json', json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
exit(0);
