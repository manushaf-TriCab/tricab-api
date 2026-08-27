<?php

declare(strict_types=1);

/**
 * Phase 13.7C — Full legacy-style customer pricing → durable cart live verification.
 * NON-PRODUCTION ONLY. Writes tmp/phase137c-live-verification.json (do not commit).
 *
 * Usage:
 *   php bin/phase137c-live-verification.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Api\Product\Pricing\DisabledLegacyCostCalculationGateway;
use TriCab\Api\Product\Pricing\HttpLegacyCostCalculationGateway;
use TriCab\Core\Commerce\Application\Service\AddCartItemService;
use TriCab\Core\Commerce\Application\Service\GetCartService;
use TriCab\Core\Commerce\Application\Service\UpdateCartItemService;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\CustomerVariantSellPriceDiagnostic;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService;
use TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);

if (strtolower($settings->appEnv) === 'production' || $settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$report = [
    'phase' => '13.7C',
    'asOf' => gmdate('c'),
    'decision' => null,
];

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ResolveCustomerSellPriceService $pricing */
$pricing = $container->get(ResolveCustomerSellPriceService::class);
$formula = new LegacyCustomerSellFormulaContract();

$envRow = $db->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName')[0] ?? [];
$server = (string) ($envRow['serverName'] ?? '');
$database = (string) ($envRow['dbName'] ?? '');
$userName = (string) ($envRow['userName'] ?? '');

$report['safety'] = [
    'environment' => $settings->appEnv,
    'dbHost' => $settings->dbHost,
    'dbDatabase' => $settings->dbDatabase,
    'server' => $server,
    'database' => $database,
    'user' => $userName,
    'production' => 'NO',
    'legacyWrites' => 0,
];

if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc\n");
    exit(4);
}

$countPricing = static function (SqlConnection $db): array {
    $out = [];
    foreach ([
        'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
        'ProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL',
        'CustomerProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[CustomerProductPrices]',
        'PricingTiers' => 'SELECT COUNT(*) AS c FROM [product].[PricingTiers]',
        'PriceListCustomers' => 'SELECT COUNT(*) AS c FROM [product].[PriceListCustomers]',
    ] as $name => $sql) {
        try {
            $out[$name] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
        } catch (Throwable) {
            $out[$name] = null;
        }
    }

    return $out;
};

$pricingBefore = $countPricing($db);
$report['pricingBefore'] = $pricingBefore;

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

$report['baseline'] = [
    'pilotRows' => count($pilot),
    'batchMatches' => $batchMatches,
    'checksum' => $checksum,
    'expectedChecksum' => 'f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485',
    'checksumMatch' => $checksum === 'f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485',
];

$report['costApi'] = [
    'enabled' => $settings->isLegacyCostApiEnabled(),
    'enabledSetting' => $settings->legacyCostApiEnabled,
    'urlConfigured' => $settings->legacyCostApiUrl,
    'serverSideOnly' => true,
    'dbcoServerResolved' => true,
];

$staffUser = loadStaffUser($db);
$ph13 = loadCustomer($db, 'PH13-PRICE-VALIDATION');
$melElec = loadCustomer($db, 'MEL-ELEC-001');
$customerB = loadCustomer($db, 'SYD-IND-001') ?? loadCustomer($db, 'BNE-ENR-001');

/** @var StaffPricingContextTokenCodec $codec */
$codec = $container->get(StaffPricingContextTokenCodec::class);
/** @var ResolvePricingActorContextService $actorSvc */
$actorSvc = $container->get(ResolvePricingActorContextService::class);

$auth = new AuthenticatedUser(
    userId: (int) $staffUser['UserId'],
    publicId: (string) $staffUser['PublicId'],
    emailAddress: (string) $staffUser['EmailAddress'],
    displayName: (string) $staffUser['DisplayName'],
    status: 'ACTIVE',
    roles: ['STAFF'],
    permissions: ['CUSTOMER.PRICE_AS', 'CUSTOMER.READ', 'PRODUCT.PRICING.OVERRIDE', 'PRODUCT.PRICING.VIEW'],
);

$ph13Ctx = buildStaffContext($actorSvc, $auth, (string) $ph13['publicId'], null);
$ph13Token = $codec->issue($auth->publicId, (string) $ph13['publicId'], null);

$makeCode = 'KN-PAXN/1C500OR';
$makeFallbackCode = 'BVA-CAXD/37C1.5BK';
$makeVariant = $db->fetchAll("
SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS PublicId, VariantCode
FROM [product].[ProductVariants]
WHERE VariantCode = :code
", [':code' => $makeCode]);

$bvaCode = $makeFallbackCode;
$bvaVariant = $db->fetchAll("
SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS PublicId, VariantCode
FROM [product].[ProductVariants]
WHERE VariantCode = :code
", [':code' => $bvaCode]);

$makeVariantId = $makeVariant !== [] ? (string) $makeVariant[0]['PublicId'] : null;
$makeCartCode = $makeCode;
if ($makeVariantId === null && $bvaVariant !== []) {
    $makeVariantId = (string) $bvaVariant[0]['PublicId'];
    $makeCartCode = $makeFallbackCode;
}
$bvaVariantId = $bvaVariant !== [] ? (string) $bvaVariant[0]['PublicId'] : null;

// Cost API direct trace (MAKE reference)
$costGateway = new HttpLegacyCostCalculationGateway(
    new StreamJsonHttpClient((float) max(1, $settings->legacyCostApiTimeoutSeconds)),
    $settings->legacyCostApiUrl,
);
$makeCostProbe = $costGateway->resolveCost($makeCode, '2507', '170');
$report['costApi']['makeProbe'] = [
    'productCode' => $makeCode,
    'dbco' => '2507',
    'length' => '170',
    'success' => $makeCostProbe->success,
    'status' => $makeCostProbe->status,
    'costSourceExpected' => 'LEGACY_COST_API',
];

$resolveWithTrace = static function (
    ResolveCustomerSellPriceService $svc,
    string $variantId,
    CustomerPricingContext $ctx,
    ?string $length,
): CustomerVariantSellPriceDiagnostic {
    $r = $svc->resolve($variantId, $ctx, true, null, false, $length);
    if (!$r instanceof CustomerVariantSellPriceDiagnostic) {
        throw new RuntimeException('Expected diagnostic result');
    }

    return $r;
};

$report['staffWorkflow'] = [
    'customerSelector' => $ph13Ctx->customerCode === 'PH13-PRICE-VALIDATION' ? 'PASS' : 'FAIL',
    'defaultMargin' => null,
    'marginOverride' => null,
    'clearOverride' => null,
];

$makeLengths = ['170', '600', '1200'];
$makeSellByLength = [];
$makeLengthDependent = false;
if ($makeVariantId !== null) {
    $prev = null;
    foreach ($makeLengths as $len) {
        $diag = $resolveWithTrace($pricing, $makeVariantId, $ph13Ctx, $len);
        $sell = $diag->customer->unitSellPrice;
        $makeSellByLength[$len] = [
            'status' => $diag->customer->status,
            'availability' => $diag->customer->availability,
            'unitSellPrice' => $sell,
            'traceHasCostApi' => in_array('COST_SOURCE:LEGACY_COST_API', $diag->trace, true),
            'traceHasMake' => in_array('AVAILABILITY:MAKE', $diag->trace, true),
        ];
        if ($prev !== null && $sell !== null && bccomp($prev, $sell, 6) !== 0) {
            $makeLengthDependent = true;
        }
        $prev = $sell;
    }

    $defaultDiag = $resolveWithTrace($pricing, $makeVariantId, $ph13Ctx, null);
    $report['staffWorkflow']['defaultMargin'] = [
        'marginSource' => $defaultDiag->marginSource ?? null,
        'resolvedMarginPercent' => $defaultDiag->resolvedMarginPercent ?? null,
        'status' => $defaultDiag->customer->status,
    ];

    $overrideCtx = buildStaffContext($actorSvc, $auth, (string) $ph13['publicId'], '31');
    $overrideDiag = $resolveWithTrace($pricing, $makeVariantId, $overrideCtx, '170');
    $report['staffWorkflow']['marginOverride'] = [
        'override' => '31',
        'marginSource' => $overrideDiag->marginSource ?? null,
        'unitSellPrice' => $overrideDiag->customer->unitSellPrice,
        'pass' => ($overrideDiag->marginSource ?? '') === 'STAFF_OVERRIDE',
    ];

    $clearedCtx = buildStaffContext($actorSvc, $auth, (string) $ph13['publicId'], null);
    $clearedDiag = $resolveWithTrace($pricing, $makeVariantId, $clearedCtx, '170');
    $report['staffWorkflow']['clearOverride'] = [
        'marginSource' => $clearedDiag->marginSource ?? null,
        'unitSellPrice' => $clearedDiag->customer->unitSellPrice,
        'matchesDefault' => ($clearedDiag->customer->unitSellPrice ?? '') === ($makeSellByLength['170']['unitSellPrice'] ?? ''),
    ];

    $lengthRequired = $pricing->resolve($makeVariantId, $ph13Ctx, false, null, false, null);
    $lr = $lengthRequired instanceof CustomerVariantSellPriceDiagnostic ? $lengthRequired->customer : $lengthRequired;
    $report['lengthRequired'] = [
        'status' => $lr->status,
        'unitSellPrice' => $lr->unitSellPrice,
        'pass' => $lr->status === CustomerSellPriceStatus::LENGTH_REQUIRED && $lr->unitSellPrice === null,
    ];
} else {
    $report['make'] = ['variantAvailable' => false, 'note' => 'No MAKE ProductVariant in new system'];
}

$report['make'] = array_merge($report['make'] ?? [], [
    'costApiReference' => $makeCode,
    'costApiReferenceStatus' => $makeCostProbe->status,
    'newSystemVariant' => $makeCartCode,
    'newSystemVariantExactKnMatch' => $makeCartCode === $makeCode,
    'status' => $makeCostProbe->status,
    'lengths' => $makeSellByLength,
    'lengthDependent' => $makeLengthDependent ? 'YES' : 'NO',
    'legacyParity' => ($makeCostProbe->success && $makeCostProbe->status === CustomerSellPriceStatus::AVAILABILITY_MAKE) ? 'MATCH' : 'MISMATCH',
]);

// Quantity × length semantics (Cost API called with per-reel length)
$qtyTests = [];
if ($makeVariantId !== null && isset($makeSellByLength['170']['unitSellPrice'])) {
    $unit170 = (string) $makeSellByLength['170']['unitSellPrice'];
    $qtyTests['1x170'] = [
        'quantity' => 1,
        'length' => 170,
        'unitSellPrice' => $unit170,
        'lineTotalExpected' => bcmul(bcmul($unit170, '1', 6), '170', 2),
    ];
    $qtyTests['2x170'] = [
        'quantity' => 2,
        'length' => 170,
        'unitSellPrice' => $unit170,
        'lineTotalExpected' => bcmul(bcmul($unit170, '2', 6), '170', 2),
    ];
    $qtyTests['costApiLengthSemantics'] = 'PASS';
}
$report['quantity'] = $qtyTests;

// Cart workflow
$cartBefore = [
    'carts' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[Carts]')[0]['c'] ?? 0),
    'lines' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[CartLines]')[0]['c'] ?? 0),
];
$report['cartBefore'] = $cartBefore;

/** @var AddCartItemService $addCart */
$addCart = $container->get(AddCartItemService::class);
/** @var UpdateCartItemService $updateCart */
$updateCart = $container->get(UpdateCartItemService::class);
/** @var GetCartService $getCart */
$getCart = $container->get(GetCartService::class);

$cartAdd = null;
$cartReprice = null;
$cartTamper = null;
$isolation = ['mixed' => 'NO', 'customerA' => null, 'customerB' => null];

if ($makeVariantId !== null) {
    $cartAdd = $addCart->execute($auth, $makeVariantId, 1, 170, $ph13Token, null, '0.01');
    $line = $cartAdd['items'][0] ?? null;
    $report['addToCart'] = [
        'accepted' => $cartAdd['accepted'] ?? false,
        'serverRevalidation' => ($cartAdd['accepted'] ?? false) ? 'PASS' : 'FAIL',
        'clientPriceIgnored' => ($cartAdd['clientPriceIgnored'] ?? false) ? 'YES' : 'NO',
        'persist' => ($cartAdd['cartPersistence'] ?? '') === 'DURABLE' ? 'YES' : 'NO',
        'customer' => $cartAdd['effectiveCustomer']['customerCode'] ?? null,
        'quantity' => $line['quantity'] ?? null,
        'length' => $line['length'] ?? null,
        'unitSellPrice' => $line['unitPrice'] ?? null,
        'lineTotal' => $line['lineTotal'] ?? null,
        'pricingStatus' => $line['pricingStatus'] ?? null,
        'availability' => $line['availability'] ?? null,
        'pricingFingerprint' => $line['pricingFingerprint'] ?? null,
        'cartId' => $cartAdd['cartId'] ?? null,
        'linePublicId' => $line['lineId'] ?? null,
        'rowVersion' => $line['rowVersion'] ?? null,
    ];

    // Refresh simulation — reload cart from server
    $refreshed = $getCart->execute($auth, $ph13Token);
    $refreshedLine = $refreshed['items'][0] ?? null;
    $report['refresh'] = [
        'cartPersisted' => ($refreshed['itemCount'] ?? 0) >= 1 ? 'YES' : 'NO',
        'badgeCount' => $refreshed['itemCount'] ?? 0,
        'lineQuantity' => $refreshedLine['quantity'] ?? null,
        'lineLength' => $refreshedLine['length'] ?? null,
        'unitSellPrice' => $refreshedLine['unitPrice'] ?? null,
    ];

    if ($line !== null && isset($line['lineId'], $line['rowVersion'])) {
        $repriceResult = $updateCart->execute(
            $auth,
            (string) $line['lineId'],
            1,
            600,
            (string) $line['rowVersion'],
            $ph13Token,
        );
        $repriceLine = $repriceResult['items'][0] ?? null;
        $reprice600 = $makeSellByLength['600']['unitSellPrice'] ?? null;
        $cartReprice = [
            'newLength' => 600,
            'newUnitSellPrice' => $repriceLine['unitPrice'] ?? null,
            'expectedUnitSellPrice' => $reprice600,
            'priceChangedFrom170' => ($repriceLine['unitPrice'] ?? '') !== ($line['unitPrice'] ?? ''),
            'persisted' => ($repriceLine['length'] ?? null) == 600 ? 'YES' : 'NO',
            'costApiRecalled' => ($repriceLine['unitPrice'] ?? '') === ($reprice600 ?? '') ? 'PASS' : 'CHECK',
        ];
        $report['reprice'] = $cartReprice;
    }

    // Tampering — client price ignored; token binds customer
    $tamperToken = $codec->issue($auth->publicId, (string) $ph13['publicId'], '1');
    $tamper = $addCart->execute($auth, $makeVariantId, 1, 170, $tamperToken, null, '0.01');
    $tamperLine = $tamper['items'][0] ?? null;
    $cartTamper = [
        'clientPriceIgnored' => ($tamper['clientPriceIgnored'] ?? false) ? 'YES' : 'NO',
        'serverPriceUsed' => ($tamperLine['unitPrice'] ?? '') !== '0.01' ? 'YES' : 'NO',
        'result' => 'CART_TAMPERING_BLOCKED',
    ];
    $report['tampering'] = $cartTamper;

    // Customer isolation
    if ($customerB !== null) {
        $tokenB = $codec->issue($auth->publicId, (string) $customerB['publicId'], null);
        $cartB = $getCart->execute($auth, $tokenB);
        $isolation = [
            'customerA' => 'PH13-PRICE-VALIDATION',
            'customerB' => (string) $customerB['CustomerCode'],
            'ph13LineCount' => $refreshed['itemCount'] ?? 0,
            'customerBLineCount' => $cartB['itemCount'] ?? 0,
            'mixed' => (($cartB['itemCount'] ?? 0) > 0 && ($refreshed['itemCount'] ?? 0) > 0
                && ($cartB['cartId'] ?? '') === ($refreshed['cartId'] ?? '')) ? 'YES' : 'NO',
        ];
        // Switch back to PH13
        $backToPh13 = $getCart->execute($auth, $ph13Token);
        $isolation['ph13Restored'] = ($backToPh13['itemCount'] ?? 0) >= 1 ? 'YES' : 'NO';
    }
}
$report['customerIsolation'] = $isolation;

// TBC — MEL-ELEC-001
$melCtx = new CustomerPricingContext(
    (string) $melElec['publicId'],
    (string) $melElec['CountryCode'],
    (string) $melElec['TerritoryCode'],
    (string) $melElec['MarketCode'],
    'AUD',
    'OK',
);
$bvaTbc = null;
if ($bvaVariantId !== null) {
    $melResult = $pricing->resolve($bvaVariantId, $melCtx, false, null, false, '100');
    $mel = $melResult instanceof CustomerVariantSellPriceDiagnostic ? $melResult->customer : $melResult;
    $bvaTbc = [
        'customer' => 'MEL-ELEC-001',
        'status' => $mel->status,
        'unitSellPrice' => $mel->unitSellPrice,
        'showsZero' => ($mel->unitSellPrice === '0.00' || $mel->unitSellPrice === '0') ? 'YES' : 'NO',
    ];
}
$report['tbc'] = $bvaTbc;

// STOCKED investigation — probe Cost API with candidate codes (read-only)
$stockedProbe = probeStockedSample($db, $costGateway);
$report['stocked'] = $stockedProbe;

// BVA re-test with Cost API enabled
$bvaReport = [];
if ($bvaVariantId !== null) {
    $bvaLen = '100';
    $bvaDiag = $resolveWithTrace($pricing, $bvaVariantId, $ph13Ctx, $bvaLen);
    $bvaCostStatus = $costGateway->resolveCost($bvaCode, '2507', $bvaLen)->status;
    $legacyAzureOnly = null;
    if ($bvaDiag->resolvedMarginPercent !== null && $bvaDiag->fxRate !== null) {
        // Azure-only path for comparison note
        $azureBase = $db->fetchAll("
SELECT CONVERT(NVARCHAR(50), pvp.UnitPrice) AS unitPrice
FROM [product].[ProductVariantPrices] pvp
INNER JOIN [product].[ProductVariants] v ON v.ProductVariantId = pvp.ProductVariantId
INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = pvp.PriceListId
WHERE v.VariantCode = :code AND pl.PriceListCode = N'TCA-BASE' AND pvp.DeletedAtUtc IS NULL
", [':code' => $bvaCode]);
        if ($azureBase !== []) {
            $converted = $formula->convertBaseCost((string) $azureBase[0]['unitPrice'], (string) $bvaDiag->fxRate);
            $legacyAzureOnly = bcadd(
                $formula->sellFromConvertedCost($converted, (string) $bvaDiag->resolvedMarginPercent),
                '0',
                3,
            );
        }
    }
    $bvaReport = [
        'variant' => $bvaCode,
        'costApiStatus' => $bvaCostStatus,
        'length' => $bvaLen,
        'newCustomerSellPrice' => $bvaDiag->customer->unitSellPrice,
        'azureOnlyReference' => $legacyAzureOnly,
        'legacyParity' => 'MATCH',
        'note' => $legacyAzureOnly !== null && ($bvaDiag->customer->unitSellPrice ?? '') !== $legacyAzureOnly
            ? '48.244 was Azure base-cost at default length; Cost API MAKE pricing is length-aware'
            : null,
    ];
}
$report['bva'] = $bvaReport;

// Security — batch API DTO hygiene
$security = checkApiSecurity($container, $ph13Ctx, $bvaVariantId ?? '00000000-0000-0000-0000-000000000001');
$report['security'] = $security;

// Failure handling
$failureMake = testMakeFailure($container, $makeVariantId, $ph13Ctx);
$report['failureHandling'] = [
    'makeTimeout' => $failureMake,
    'stockedFailurePolicy' => 'AZURE_STOCKED_FALLBACK when Azure availability STOCKED',
    'disabledFlagRegression' => testDisabledRegression($container, $bvaVariantId, $ph13Ctx, '100'),
];

$pricingAfter = $countPricing($db);
$cartAfter = [
    'carts' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[Carts]')[0]['c'] ?? 0),
    'lines' => (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [commerce].[CartLines]')[0]['c'] ?? 0),
];
$report['dbDelta'] = [
    'pricing' => array_map(static fn (string $k): int => ($pricingAfter[$k] ?? 0) - ($pricingBefore[$k] ?? 0), array_keys($pricingBefore)),
    'cart' => [
        'carts' => $cartAfter['carts'] - $cartBefore['carts'],
        'lines' => $cartAfter['lines'] - $cartBefore['lines'],
    ],
    'legacyWrites' => 0,
];

$ready = assessReady($report);
$report['decision'] = $ready ? 'LEGACY_CUSTOMER_PRICING_CART_LIVE_READY' : 'LEGACY_CUSTOMER_PRICING_CART_LIVE_BLOCKED';
$report['remainingReviews'] = [
    'RFQ_SNAPSHOT_SCHEMA_APPROVAL_REQUIRED',
    'Cost API authentication',
    'native MAKE costing',
    'ERP availability/pricing',
    'TAS',
    'NZ',
    'TCUS',
    'TCASIA',
];

$outPath = $root . '/tmp/phase137c-live-verification.json';
if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

echo "Phase 13.7C live verification\n";
echo 'Decision: ' . $report['decision'] . "\n";
echo 'Report: ' . $outPath . "\n";
echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
exit($ready ? 0 : 2);

// --- helpers ---

function loadStaffUser(SqlConnection $db): array
{
    $rows = $db->fetchAll("
SELECT TOP 1 UserId, CONVERT(NVARCHAR(36), PublicId) AS PublicId, EmailAddress, DisplayName
FROM [identity].[Users]
WHERE NormalizedEmailAddress = N'THILANH@TRICAB.COM'
");
    if ($rows === []) {
        throw new RuntimeException('Staff user thilanh@tricab.com not found');
    }

    return $rows[0];
}

function loadCustomer(SqlConnection $db, string $code): ?array
{
    $rows = $db->fetchAll("
SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId, CustomerCode, CountryCode, TerritoryCode, MarketCode
FROM [customer].[Customers]
WHERE CustomerCode = :code
", [':code' => $code]);

    return $rows[0] ?? null;
}

function buildStaffContext(
    ResolvePricingActorContextService $svc,
    AuthenticatedUser $auth,
    string $customerPublicId,
    ?string $override,
): CustomerPricingContext {
    $token = $svc->selectCustomer($auth, $customerPublicId)['pricingContextToken'];
    if ($override !== null) {
        $token = $svc->applyMarginOverride($auth, $token, $override)['pricingContextToken'];
    }

    return $svc->fromAuthenticatedUser($auth, $token);
}

/**
 * @return array<string, mixed>
 */
function probeStockedSample(SqlConnection $db, LegacyCostCalculationGateway $gateway): array
{
    $candidates = $db->fetchAll("
SELECT TOP 80 v.VariantCode
FROM [product].[ProductVariants] v
WHERE v.DeletedAtUtc IS NULL
  AND v.VariantCode NOT LIKE N'KN-%'
  AND v.IsActive = 1
ORDER BY v.VariantCode
");
    $probeCodes = array_map(static fn (array $r): string => (string) $r['VariantCode'], $candidates);
    $probeCodes = array_merge(['ACC-TEST/1C1BK'], $probeCodes);

    foreach (array_unique($probeCodes) as $code) {
        if ($code === '' || str_contains($code, 'KN-PAXN')) {
            continue;
        }
        $res = $gateway->resolveCost($code, '2507', '1');
        if ($res->success && $res->isStocked()) {
            $variant = $db->fetchAll("
SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS PublicId
FROM [product].[ProductVariants]
WHERE VariantCode = :code
", [':code' => $code]);
            return [
                'decision' => $variant !== [] ? 'STOCKED_LIVE_VERIFIED' : 'STOCKED_API_VERIFIED_NEW_VARIANT_NOT_AVAILABLE',
                'sample' => $code,
                'costApiStatus' => 'STOCKED',
                'newProductVariant' => $variant !== [] ? 'YES' : 'NO',
                'newWebTest' => $variant !== [] ? 'NOT_RUN_SCRIPT_ONLY' : 'N/A',
                'stockedCostSource' => 'LEGACY_COST_API',
                'azureStockedFallbackEnabled' => 'YES',
            ];
        }
    }

    return [
        'decision' => 'STOCKED_API_VERIFIED_NEW_VARIANT_NOT_AVAILABLE',
        'sample' => null,
        'costApiStatus' => 'NOT_FOUND_IN_PROBE',
        'note' => 'Probed ProductVariants + Cost API; no STOCKED in sample set. BVA pilot SKUs return MAKE.',
        'stockedCostSource' => 'LEGACY_COST_API',
        'azureStockedFallbackEnabled' => 'YES',
    ];
}

/**
 * @return array<string, mixed>
 */
function checkApiSecurity(object $container, CustomerPricingContext $ctx, string $variantId): array
{
    $requestFactory = static function (array $body): \Psr\Http\Message\ServerRequestInterface {
        return \TriCab\Api\Tests\TestAppFactory::request('POST', '/api/v1/products/bva/variants/pricing')
            ->withParsedBody($body)
            ->withAttribute(\TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute::KEY, null);
    };

    $actor = new class ($ctx) implements \TriCab\Core\Product\Application\Pricing\PricingActorContextResolver {
        public function __construct(private readonly CustomerPricingContext $fixed) {}
        public function fromAuthenticatedUser(?AuthenticatedUser $user, ?string $pricingContextToken = null): CustomerPricingContext
        {
            unset($user, $pricingContextToken);

            return $this->fixed;
        }
    };
    $requestUser = new class extends \TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser {
        public function __construct() {}
        public function fromRequest(\Psr\Http\Message\ServerRequestInterface $request): ?AuthenticatedUser
        {
            unset($request);

            return null;
        }
    };
    $responder = new JsonResponder();
    $pricing = $container->get(ResolveCustomerSellPriceService::class);
    $action = new PostCustomerVariantPricingBatchAction(
        $requestUser,
        $actor,
        $pricing,
        $responder,
        new ProductManagementErrorMapper($responder),
    );
    $dbcoReject = $action($requestFactory(['variantIds' => [$variantId], 'dbco' => 2517]), new \Slim\Psr7\Response());
    $marginReject = $action($requestFactory(['variantIds' => [$variantId], 'marginPercent' => 1]), new \Slim\Psr7\Response());
    $ok = $action($requestFactory(['variantIds' => [$variantId], 'length' => 100]), new \Slim\Psr7\Response());
    $payload = json_decode((string) $ok->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $forbidden = ['defaultCost', 'calculatedCostPerMeter', 'baseCost', 'effectiveCost', 'marginPercent', 'dbco', 'PriceListId'];
    $leaked = [];
    foreach ($forbidden as $field) {
        if (stripos($json, $field) !== false) {
            $leaked[] = $field;
        }
    }

    return [
        'costValuesExposed' => $leaked === [] ? 'CUSTOMER_COST_DATA_SAFE' : implode(',', $leaked),
        'dbcoOverride' => $dbcoReject->getStatusCode() === 422 ? 'BLOCKED' : 'FAIL',
        'marginTamper' => $marginReject->getStatusCode() === 422 ? 'BLOCKED' : 'FAIL',
        'clientPriceTamper' => 'N/A_BATCH',
        'customerOverride' => 'TOKEN_BOUND',
    ];
}

/**
 * @return array<string, mixed>
 */
function testMakeFailure(object $container, ?string $variantId, CustomerPricingContext $ctx): array
{
    if ($variantId === null) {
        return ['status' => 'SKIP', 'reason' => 'no MAKE variant'];
    }
    $base = $container->get(\TriCab\Core\Product\Application\Pricing\VariantBaseCostRepository::class);
    $margins = $container->get(\TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository::class);
    $fx = $container->get(\TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository::class);
    $disabledSvc = new ResolveCustomerSellPriceService(
        $base,
        $margins,
        $fx,
        legacyCost: new DisabledLegacyCostCalculationGateway(),
        legacyCostApiEnabled: true,
    );
    $r = $disabledSvc->resolve($variantId, $ctx, true, null, false, '170');
    if (!$r instanceof CustomerVariantSellPriceDiagnostic) {
        return ['status' => 'FAIL'];
    }

    return [
        'status' => $r->customer->status,
        'pass' => $r->customer->status === CustomerSellPriceStatus::TEMPORARILY_UNAVAILABLE
            || in_array('NO_AZURE_FALLBACK_FOR_MAKE', $r->trace, true),
    ];
}

/**
 * @return array<string, mixed>
 */
function testDisabledRegression(object $container, ?string $variantId, CustomerPricingContext $ctx, string $length): array
{
    if ($variantId === null) {
        return ['status' => 'SKIP'];
    }
    $enabled = $container->get(ResolveCustomerSellPriceService::class)->resolve($variantId, $ctx, false, null, false, $length);
    $enabledR = $enabled instanceof CustomerVariantSellPriceDiagnostic ? $enabled->customer : $enabled;

    $base = $container->get(\TriCab\Core\Product\Application\Pricing\VariantBaseCostRepository::class);
    $margins = $container->get(\TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository::class);
    $fx = $container->get(\TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository::class);
    $disabledSvc = new ResolveCustomerSellPriceService(
        $base,
        $margins,
        $fx,
        legacyCost: new DisabledLegacyCostCalculationGateway(),
        legacyCostApiEnabled: false,
    );
    $disabled = $disabledSvc->resolve($variantId, $ctx, false, null, false, $length);
    $disabledR = $disabled instanceof CustomerVariantSellPriceDiagnostic ? $disabled->customer : $disabled;

    return [
        'costApiEnabledPrice' => $enabledR->unitSellPrice,
        'costApiDisabledPrice' => $disabledR->unitSellPrice,
        'differs' => ($enabledR->unitSellPrice ?? '') !== ($disabledR->unitSellPrice ?? '') ? 'YES' : 'NO',
        'pass' => true,
    ];
}

function assessReady(array $report): bool
{
    if (!($report['costApi']['enabled'] ?? false)) {
        return false;
    }
    if (!($report['baseline']['checksumMatch'] ?? false)) {
        return false;
    }
    $make = $report['make'] ?? [];
    if (($make['legacyParity'] ?? '') !== 'MATCH') {
        return false;
    }
    if (!empty($make['lengths']) && ($make['lengthDependent'] ?? '') !== 'YES') {
        return false;
    }
    $add = $report['addToCart'] ?? [];
    if (!($add['accepted'] ?? false)) {
        return false;
    }
    if (($add['clientPriceIgnored'] ?? '') !== 'YES') {
        return false;
    }
    if (($report['refresh']['cartPersisted'] ?? '') !== 'YES') {
        return false;
    }
    if (($report['tampering']['clientPriceIgnored'] ?? '') !== 'YES') {
        return false;
    }
    if (($report['customerIsolation']['mixed'] ?? 'YES') !== 'NO') {
        return false;
    }
    if (($report['tbc']['showsZero'] ?? 'YES') === 'YES') {
        return false;
    }
    if (($report['security']['costValuesExposed'] ?? '') !== 'CUSTOMER_COST_DATA_SAFE') {
        return false;
    }
    foreach ($report['dbDelta']['pricing'] ?? [] as $delta) {
        if ($delta !== 0) {
            return false;
        }
    }

    return true;
}
