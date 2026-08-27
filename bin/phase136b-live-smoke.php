<?php

declare(strict_types=1);

/**
 * Phase 13.6B live smoke (non-production). Console-safe output only.
 * Sensitive parity → tmp/phase13.6b-live-parity.json
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
use TriCab\Api\Http\Action\PublicProduct\PostPrepareRfqLinePricingAction;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContextResolver;
use TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);
if (strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
$db = $c->get(SqlConnection::class);
$svc = $c->get(ResolveCustomerSellPriceService::class);
$formula = new LegacyCustomerSellFormulaContract();
$resolveUser = $c->get(ResolveAuthenticatedUserHandler::class);
$customerCtx = $c->get(ResolveAuthenticatedCustomerContextService::class);
$pricingCtx = $c->get(CustomerPricingContextResolver::class);

$counts = static function (SqlConnection $db): array {
    $out = [];
    foreach ([
        'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
        'Customers' => 'SELECT COUNT(*) AS c FROM [customer].[Customers]',
        'CustomerContacts' => 'SELECT COUNT(*) AS c FROM [customer].[CustomerContacts]',
        'CustomerContactUsers' => 'SELECT COUNT(*) AS c FROM [customer].[CustomerContactUsers] WHERE UnlinkedAtUtc IS NULL AND IsActive = 1',
    ] as $k => $sql) {
        $out[$k] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
    }

    return $out;
};

$beforePilot = json_decode((string) file_get_contents($root . '/tmp/phase13.6b-pilot-baseline-before.json'), true);

$pilot = $db->fetchAll("
SELECT CONVERT(NVARCHAR(36), v.PublicId) AS id, v.VariantCode, CONVERT(NVARCHAR(50), pvp.UnitPrice) AS cost,
       pl.PriceListCode, cur.CurrencyCode, CONVERT(NVARCHAR(100), pvp.ErpSourceReference) AS erpRef
FROM [product].[ProductVariantPrices] pvp
INNER JOIN [product].[ProductVariants] v ON v.ProductVariantId = pvp.ProductVariantId
INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = pvp.PriceListId
INNER JOIN [product].[Currencies] cur ON cur.CurrencyId = pl.CurrencyId
WHERE pl.PriceListCode = N'TCA-BASE' AND v.VariantCode LIKE N'BVA-%' AND pvp.DeletedAtUtc IS NULL
ORDER BY v.VariantCode
");
$hash = hash('sha256', json_encode(array_map(static fn (array $r): array => [
    'code' => $r['VariantCode'], 'id' => $r['id'], 'pl' => $r['PriceListCode'], 'ccy' => $r['CurrencyCode'],
    'ref' => $r['erpRef'], 'up' => $r['cost'],
], $pilot), JSON_THROW_ON_ERROR));

$identity = new VerifiedIdentity(
    provider: ExternalProvider::MICROSOFT_ENTRA,
    subjectId: $settings->devAuthDefaultSubject,
    tenantId: $settings->devAuthDefaultTenant,
    verifiedEmail: $settings->devAuthDefaultEmail,
);
$user = $resolveUser->handle($identity);
$portal = $customerCtx->execute($user->publicId, $user->userId, $user->roles);
$context = $pricingCtx->fromAuthenticatedUser($user);

$console = [
    'env' => $settings->appEnv,
    'production' => 'NO',
    'pilotCount' => count($pilot),
    'checksumBefore' => $beforePilot['checksum'] ?? null,
    'checksumAfter' => $hash,
    'pilotUnchanged' => ($beforePilot['checksum'] ?? null) === $hash,
    'userEmailDomain' => str_contains($user->emailAddress, '@') ? explode('@', $user->emailAddress, 2)[1] : null,
    'linkedCustomers' => $portal?->customerPublicIds ?? [],
    'context' => [
        'authState' => $context->authState,
        'country' => $context->countryCode,
        'territory' => $context->territoryCode,
        'market' => $context->marketCode,
        'currency' => $context->sellCurrency,
        'customerPublicId' => $context->customerPublicId,
    ],
    'customerContextResolved' => $context->authState === 'OK'
        && strtoupper((string) $context->countryCode) === 'AU'
        && strtoupper((string) $context->territoryCode) === 'VIC'
        && strtoupper((string) $context->marketCode) === 'CONSULT1',
    'variants' => [],
    'melElec' => [],
    'rfqTamper' => null,
    'counts' => $counts($db),
];

$parity = ['asOf' => gmdate('c'), 'variants' => []];
$pick = array_slice($pilot, 0, 5);
foreach ($pick as $variant) {
    $diag = $svc->resolve((string) $variant['id'], $context, true);
    $cust = $diag->customer;
    $legacy = null;
    $match = 'MISMATCH';
    if ($cust->status === 'RESOLVED' && $diag->baseCost && $diag->fxRate && $diag->marginPercent) {
        $legacy = bcadd($formula->resolveSellPrice($diag->baseCost, $diag->fxRate, $diag->marginPercent), '0', 3);
        $match = $legacy === $cust->unitSellPrice ? 'MATCH' : 'MISMATCH';
    }
    $console['variants'][] = [
        'variant' => $variant['VariantCode'],
        'status' => $cust->status,
        'availability' => $cust->availability,
        'match' => $match,
    ];
    $parity['variants'][] = [
        'variant' => $variant['VariantCode'],
        'variantId' => $variant['id'],
        'azureBase' => $diag->baseCost,
        'margin' => $diag->marginPercent,
        'fx' => $diag->fxRate,
        'legacySell' => $legacy,
        'newSell' => $cust->unitSellPrice,
        'status' => $cust->status,
        'match' => $match,
    ];
}

$mel = $db->fetchAll("SELECT CONVERT(NVARCHAR(36), PublicId) AS pid, CountryCode, TerritoryCode, MarketCode FROM [customer].[Customers] WHERE CustomerCode = N'MEL-ELEC-001'");
if ($mel !== []) {
    $melCtx = new CustomerPricingContext(
        (string) $mel[0]['pid'],
        (string) $mel[0]['CountryCode'],
        (string) $mel[0]['TerritoryCode'],
        (string) $mel[0]['MarketCode'],
        'AUD',
        'OK',
    );
    foreach (array_slice($pick, 0, 3) as $variant) {
        $r = $svc->resolve((string) $variant['id'], $melCtx, false);
        $console['melElec'][] = [
            'variant' => $variant['VariantCode'],
            'status' => $r->status,
            'unitPrice' => $r->unitSellPrice,
            'match' => $r->status === 'TBC' && $r->unitSellPrice === null ? 'MATCH' : 'MISMATCH',
        ];
    }
}

// RFQ prepare via action with resolved request user
$prepareAction = $c->get(PostPrepareRfqLinePricingAction::class);
$reqUser = $c->get(ResolveRequestAuthenticatedUser::class);
$pricingAction = $c->get(PostCustomerVariantPricingBatchAction::class);

$request = TestAppFactory::request('POST', '/api/v1/products/flexible-control-lszh-3d-test/rfq/lines/prepare')
    ->withParsedBody([
        'variantId' => $pick[0]['id'],
        'quantity' => 1,
        'length' => 100,
        'unitPrice' => '0.01',
    ])
    ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_EMAIL, $settings->devAuthDefaultEmail)
    ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_TENANT, $settings->devAuthDefaultTenant)
    ->withHeader(DevelopmentAuthenticationMiddleware::HEADER_SUBJECT, $settings->devAuthDefaultSubject);

// Attach VerifiedIdentity like DEV middleware
$request = $request->withAttribute(
    \TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute::KEY,
    $identity,
);

$rfqResponse = $prepareAction($request, new \Slim\Psr7\Response());
$rfqPayload = json_decode((string) $rfqResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
$rfq = $rfqPayload['data'] ?? [];
$console['rfqTamper'] = [
    'http' => $rfqResponse->getStatusCode(),
    'accepted' => $rfq['accepted'] ?? null,
    'clientPriceTrusted' => $rfq['clientPriceTrusted'] ?? null,
    'clientPriceIgnored' => $rfq['clientPriceIgnored'] ?? null,
    'status' => $rfq['status'] ?? null,
    'serverUnitPricePresent' => isset($rfq['unitPrice']) && $rfq['unitPrice'] !== null && $rfq['unitPrice'] !== '0.01',
    'estimatedTotal' => $rfq['estimatedTotal'] ?? null,
    'blocked' => ($rfq['clientPriceTrusted'] ?? true) === false
        && ($rfq['unitPrice'] ?? '0.01') !== '0.01'
        && ($rfq['status'] ?? '') === 'RESOLVED',
];

$batchRequest = TestAppFactory::request('POST', '/api/v1/products/flexible-control-lszh-3d-test/variants/pricing')
    ->withParsedBody(['variantIds' => array_column($pick, 'id'), 'customerId' => 'impersonate-me'])
    ->withAttribute(\TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute::KEY, $identity);
$batchReject = $pricingAction($batchRequest, new \Slim\Psr7\Response());
$console['overrideReject'] = $batchReject->getStatusCode();

$batchOk = $pricingAction(
    TestAppFactory::request('POST', '/api/v1/products/x/variants/pricing')
        ->withParsedBody(['variantIds' => array_column($pick, 'id')])
        ->withAttribute(\TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute::KEY, $identity),
    new \Slim\Psr7\Response(),
);
$batchJson = (string) $batchOk->getBody();
$batchData = json_decode($batchJson, true, 512, JSON_THROW_ON_ERROR);
$leak = false;
foreach (['"baseCost"', 'marginPercent', '"dbco"', 'priceListId', 'tmktm'] as $needle) {
    if (stripos($batchJson, $needle) !== false) {
        $leak = true;
    }
}
$console['apiBatch'] = [
    'http' => $batchOk->getStatusCode(),
    'statuses' => array_column($batchData['data']['items'] ?? [], 'status'),
    'leak' => $leak ? 'YES' : 'NO',
];

$guest = $svc->resolve((string) $pick[0]['id'], CustomerPricingContext::guest(), false);
$console['guest'] = ['status' => $guest->status, 'match' => $guest->status === 'LOGIN_REQUIRED' ? 'MATCH' : 'MISMATCH'];

file_put_contents($root . '/tmp/phase13.6b-live-parity.json', json_encode($parity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($root . '/tmp/phase13.6b-live-smoke-summary.json', json_encode($console, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode($console, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
