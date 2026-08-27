<?php

declare(strict_types=1);

/**
 * Phase 13.6A live customer pricing verification (non-production).
 * Writes protected parity details to tmp/ — do not commit that file.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$coreSrc = dirname(__DIR__) . '/core/src';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    if (!str_starts_with($class, 'TriCab\\Core\\')) {
        return;
    }
    $path = $coreSrc . '/' . str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);
$container = ContainerFactory::create($settings);
$db = $container->get(SqlConnection::class);
$svc = $container->get(ResolveCustomerSellPriceService::class);
$formula = new LegacyCustomerSellFormulaContract();

$report = [
    'asOf' => gmdate('c'),
    'env' => [
        'APP_ENV' => $settings->appEnv,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'production' => strtolower((string) $settings->appEnv) === 'production' ? 'YES' : 'NO',
    ],
    'sql' => $db->fetchAll('SELECT @@SERVERNAME AS servername, DB_NAME() AS dbname')[0] ?? [],
    'liveCustomerResolvedAvailable' => false,
    'blocker' => null,
    'customers' => [],
    'links' => [],
    'variants' => [],
    'tbcLive' => [],
    'apiSecurity' => [],
    'countsBefore' => [],
    'countsAfter' => [],
];

$counts = static function (SqlConnection $db): array {
    $out = [];
    foreach ([
        'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
        'ProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL',
        'CustomerProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[CustomerProductPrices]',
        'PricingTiers' => 'SELECT COUNT(*) AS c FROM [product].[PricingTiers]',
        'PriceListCustomers' => 'SELECT COUNT(*) AS c FROM [product].[PriceListCustomers]',
        'Customers' => 'SELECT COUNT(*) AS c FROM [customer].[Customers]',
    ] as $name => $sql) {
        try {
            $out[$name] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
        } catch (Throwable) {
            $out[$name] = null;
        }
    }

    return $out;
};

$report['countsBefore'] = $counts($db);

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
$batch = 0;
foreach ($pilot as $row) {
    if (str_contains((string) $row['erpRef'], 'p134-tca-BVA-20260826T010912')) {
        ++$batch;
    }
}
$hash = hash('sha256', json_encode(array_map(static fn (array $r): array => [
    'id' => $r['priceId'],
    'v' => $r['variantPublicId'],
    'code' => $r['VariantCode'],
    'pl' => $r['PriceListCode'],
    'ccy' => $r['CurrencyCode'],
    'ref' => $r['erpRef'],
    'up' => $r['unitPrice'],
], $pilot), JSON_THROW_ON_ERROR));
$report['pilot'] = [
    'expected' => 20,
    'found' => count($pilot),
    'batchMatches' => $batch,
    'checksum' => $hash,
    'duplicates' => $db->fetchAll("
        SELECT v.VariantCode, COUNT(*) AS c
        FROM [product].[ProductVariantPrices] pvp
        INNER JOIN [product].[ProductVariants] v ON v.ProductVariantId = pvp.ProductVariantId
        INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = pvp.PriceListId
        WHERE pl.PriceListCode = N'TCA-BASE' AND v.VariantCode LIKE N'BVA-%' AND pvp.DeletedAtUtc IS NULL
        GROUP BY v.VariantCode HAVING COUNT(*) > 1
    "),
];

$customers = $db->fetchAll("
SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId,
       CustomerCode, CountryCode, TerritoryCode, MarketCode, StatusCode
FROM [customer].[Customers]
ORDER BY CustomerCode
");
foreach ($customers as $customer) {
    $report['customers'][] = [
        'code' => $customer['CustomerCode'],
        'country' => $customer['CountryCode'],
        'territory' => $customer['TerritoryCode'],
        'market' => $customer['MarketCode'],
        'status' => $customer['StatusCode'],
        'publicId' => $customer['publicId'],
    ];
}

$links = $db->fetchAll("
SELECT c.CustomerCode,
       CONVERT(NVARCHAR(36), c.PublicId) AS customerPublicId,
       CONVERT(NVARCHAR(36), cc.PublicId) AS contactPublicId,
       cc.PortalAccessStatusCode,
       CONVERT(NVARCHAR(36), u.PublicId) AS userPublicId
FROM [customer].[Customers] c
LEFT JOIN [customer].[CustomerContacts] cc ON cc.CustomerId = c.CustomerId
LEFT JOIN [customer].[CustomerContactUsers] ccu ON ccu.CustomerContactId = cc.CustomerContactId
LEFT JOIN [identity].[Users] u ON u.UserId = ccu.UserId
");
foreach ($links as $link) {
    $report['links'][] = [
        'customerCode' => $link['CustomerCode'],
        'hasContact' => $link['contactPublicId'] !== null,
        'portal' => $link['PortalAccessStatusCode'],
        'hasUser' => $link['userPublicId'] !== null,
    ];
}

$variants = array_slice($pilot, 0, 5);
foreach ($variants as $variant) {
    $report['variants'][] = [
        'variantCode' => $variant['VariantCode'],
        'variantPublicId' => $variant['variantPublicId'],
        'tcaBase' => 'YES',
        'currency' => $variant['CurrencyCode'],
    ];
}

// Real existing customer: MEL-ELEC-001 = AU / VIC-METRO / ELECTRICAL → expected TBC for BVA
$mel = null;
foreach ($customers as $customer) {
    if ($customer['CustomerCode'] === 'MEL-ELEC-001') {
        $mel = $customer;
        break;
    }
}
if ($mel === null) {
    fwrite(STDERR, "MEL-ELEC-001 missing\n");
    exit(1);
}

$tbcCtx = new CustomerPricingContext(
    (string) $mel['publicId'],
    (string) $mel['CountryCode'],
    (string) $mel['TerritoryCode'],
    (string) $mel['MarketCode'],
    'AUD',
    'OK',
);

$parity = [
    'asOf' => gmdate('c'),
    'note' => 'LIVE RESOLVED customer unavailable in Azure seed data. TBC uses real MEL-ELEC-001. Optional parity-only VIC/CONSULT1 rows are labeled and are NOT live customer context.',
    'liveCustomerResolvedAvailable' => false,
    'scenarios' => [],
];

foreach ($variants as $variant) {
    $resolved = $svc->resolve((string) $variant['variantPublicId'], $tbcCtx, true);
    $customerResult = $resolved->customer;
    $match = $customerResult->status === 'TBC' && $customerResult->unitSellPrice === null ? 'MATCH' : 'MISMATCH';
    $report['tbcLive'][] = [
        'variant' => $variant['VariantCode'],
        'status' => $customerResult->status,
        'unitPrice' => $customerResult->unitSellPrice,
        'match' => $match,
    ];
    $parity['scenarios'][] = [
        'kind' => 'LIVE_CUSTOMER_TBC',
        'customerCode' => 'MEL-ELEC-001',
        'variantId' => $variant['variantPublicId'],
        'variantCode' => $variant['VariantCode'],
        'country' => $mel['CountryCode'],
        'territory' => $mel['TerritoryCode'],
        'market' => $mel['MarketCode'],
        'currency' => 'AUD',
        'azureBaseCost' => $resolved->baseCost,
        'legacyMargin' => $resolved->marginPercent,
        'fx' => $resolved->fxRate,
        'legacySell' => null,
        'newSell' => $customerResult->unitSellPrice,
        'status' => $customerResult->status,
        'difference' => null,
        'match' => $match,
    ];
}

// Documented parity-only (NOT live customer) — proves formula still MATCH when market exists
$parityOnlyCtx = new CustomerPricingContext(
    'PARITY-ONLY-NOT-LIVE-CUSTOMER',
    'AU',
    'VIC',
    'CONSULT1',
    'AUD',
    'OK',
);
$report['parityOnlyResolved'] = [];
foreach ($variants as $variant) {
    $resolved = $svc->resolve((string) $variant['variantPublicId'], $parityOnlyCtx, true);
    $customerResult = $resolved->customer;
    $legacy = $formula->resolveSellPrice((string) $resolved->baseCost, (string) $resolved->fxRate, (string) $resolved->marginPercent);
    $legacyDisplay = bcadd($legacy, '0', 3);
    $match = ($customerResult->status === 'RESOLVED' && $legacyDisplay === $customerResult->unitSellPrice) ? 'MATCH' : 'MISMATCH';
    $report['parityOnlyResolved'][] = [
        'variant' => $variant['VariantCode'],
        'status' => $customerResult->status,
        'match' => $match,
    ];
    $parity['scenarios'][] = [
        'kind' => 'PARITY_ONLY_NOT_LIVE_CUSTOMER',
        'customerCode' => null,
        'variantId' => $variant['variantPublicId'],
        'variantCode' => $variant['VariantCode'],
        'country' => 'AU',
        'territory' => 'VIC',
        'market' => 'CONSULT1',
        'currency' => 'AUD',
        'azureBaseCost' => $resolved->baseCost,
        'legacyMargin' => $resolved->marginPercent,
        'fx' => $resolved->fxRate,
        'legacySell' => $legacyDisplay,
        'newSell' => $customerResult->unitSellPrice,
        'status' => $customerResult->status,
        'difference' => bcsub((string) $customerResult->unitSellPrice, $legacyDisplay, 6),
        'match' => $match,
    ];
}

// Guest
$guest = $svc->resolve((string) $variants[0]['variantPublicId'], CustomerPricingContext::guest(), false);
$report['guest'] = [
    'status' => $guest->status,
    'unitPrice' => $guest->unitSellPrice,
    'match' => $guest->status === 'LOGIN_REQUIRED' && $guest->unitSellPrice === null ? 'MATCH' : 'MISMATCH',
];

// API security + TBC DTO leak check using live MEL context
$action = new PostCustomerVariantPricingBatchAction(
    new class ($tbcCtx) implements CustomerPricingContextResolver {
        public function __construct(private readonly CustomerPricingContext $fixed)
        {
        }

        public function fromAuthenticatedUser(?AuthenticatedUser $user): CustomerPricingContext
        {
            unset($user);

            return $this->fixed;
        }
    },
    $svc,
    new JsonResponder(),
    new ProductManagementErrorMapper(new JsonResponder()),
);

$variantIds = array_column($variants, 'variantPublicId');
$forbiddenBodies = [
    ['variantIds' => $variantIds, 'marginPercent' => 99],
    ['variantIds' => $variantIds, 'dbco' => 2507],
    ['variantIds' => $variantIds, 'baseCost' => '1'],
    ['variantIds' => $variantIds, 'customerId' => '00000000-0000-0000-0000-000000000099'],
    ['variantIds' => $variantIds, 'priceListId' => 'x'],
];
foreach ($forbiddenBodies as $body) {
    $key = array_values(array_diff(array_keys($body), ['variantIds']))[0] ?? 'unknown';
    $response = $action(
        TestAppFactory::request('POST', '/api/v1/products/bva/variants/pricing')
            ->withParsedBody($body)
            ->withAttribute(AuthenticatedUserAttribute::KEY, null),
        new \Slim\Psr7\Response(),
    );
    $report['apiSecurity'][] = [
        'field' => $key,
        'httpStatus' => $response->getStatusCode(),
        'rejected' => $response->getStatusCode() === 422,
    ];
}

$okResponse = $action(
    TestAppFactory::request('POST', '/api/v1/products/bva/variants/pricing')
        ->withParsedBody(['variantIds' => $variantIds])
        ->withAttribute(AuthenticatedUserAttribute::KEY, null),
    new \Slim\Psr7\Response(),
);
$okJson = (string) $okResponse->getBody();
$okPayload = json_decode($okJson, true, 512, JSON_THROW_ON_ERROR);
$leaked = false;
foreach (['baseCost', 'marginPercent', 'dbco', 'priceListId', 'tmktm', 'UnitPrice'] as $needle) {
    if (stripos($okJson, $needle) !== false) {
        $leaked = true;
    }
}
$report['apiTbcBatch'] = [
    'httpStatus' => $okResponse->getStatusCode(),
    'itemCount' => count($okPayload['data']['items'] ?? []),
    'statuses' => array_column($okPayload['data']['items'] ?? [], 'status'),
    'internalDataLeaked' => $leaked ? 'YES' : 'NO',
    'anyZeroPrice' => (bool) array_filter(
        $okPayload['data']['items'] ?? [],
        static fn (array $item): bool => ($item['unitPrice'] ?? null) === '0.00' || ($item['unitPrice'] ?? null) === '0.000',
    ),
];

// NZ / US customer availability
$nz = array_values(array_filter($customers, static fn (array $c): bool => strtoupper((string) $c['CountryCode']) === 'NZ'));
$us = array_values(array_filter($customers, static fn (array $c): bool => strtoupper((string) $c['CountryCode']) === 'US'));
$report['nzLiveCustomer'] = $nz === [] ? 'NZ_LIVE_CUSTOMER_NOT_AVAILABLE' : 'AVAILABLE';
$report['usLive'] = 'TCUS_LIVE_VERIFICATION_DEFERRED';

// Can any existing customer resolve BVA?
$resolvable = [];
foreach ($customers as $customer) {
    $ctx = new CustomerPricingContext(
        (string) $customer['publicId'],
        (string) $customer['CountryCode'],
        (string) $customer['TerritoryCode'],
        (string) $customer['MarketCode'],
        'AUD',
        'OK',
    );
    $status = $svc->resolve((string) $variants[0]['variantPublicId'], $ctx, false)->status;
    $resolvable[] = [
        'code' => $customer['CustomerCode'],
        'territory' => $customer['TerritoryCode'],
        'market' => $customer['MarketCode'],
        'status' => $status,
    ];
}
$report['existingCustomerBvaStatus'] = $resolvable;
$anyResolved = array_filter($resolvable, static fn (array $r): bool => $r['status'] === 'RESOLVED');
if ($anyResolved === []) {
    $report['liveCustomerResolvedAvailable'] = false;
    $report['blocker'] = 'LIVE_CUSTOMER_CONTEXT_NOT_AVAILABLE';
}

$report['countsAfter'] = $counts($db);
$report['deltas'] = [];
foreach ($report['countsBefore'] as $key => $before) {
    $after = $report['countsAfter'][$key] ?? null;
    $report['deltas'][$key] = ($before === null || $after === null) ? null : ($after - $before);
}

file_put_contents(
    $root . '/tmp/phase13.6a-live-customer-pricing-parity.json',
    json_encode($parity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);
file_put_contents(
    $root . '/tmp/phase13.6a-live-verification-summary.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);

echo json_encode([
    'blocker' => $report['blocker'],
    'pilot' => $report['pilot'],
    'existingCustomerBvaStatus' => $report['existingCustomerBvaStatus'],
    'tbcLive' => $report['tbcLive'],
    'parityOnlyResolved' => $report['parityOnlyResolved'],
    'guest' => $report['guest'],
    'apiSecurity' => $report['apiSecurity'],
    'apiTbcBatch' => $report['apiTbcBatch'],
    'nz' => $report['nzLiveCustomer'],
    'us' => $report['usLive'],
    'deltas' => $report['deltas'],
    'links' => $report['links'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
