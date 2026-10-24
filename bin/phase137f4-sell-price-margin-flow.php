<?php

declare(strict_types=1);

/**
 * Phase 13.7F.4 — HTTP margin/sell-price flow for BVA-PAXA/5C50BK.
 * Usage: php bin/phase137f4-sell-price-margin-flow.php [customerCode]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$container = ContainerFactory::create(Environment::settings($root));

/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$baseUrl = rtrim(getenv('PHASE137_API_BASE') ?: 'http://localhost:8080', '/');
$devHeaders = [
    'X-Dev-Entra-Tenant: development-tenant',
    'X-Dev-Entra-Subject: development-thilan',
    'X-Dev-Entra-Email: thilanh@tricab.com',
    'Content-Type: application/json',
    'Accept: application/json',
];

$variantCode = 'BVA-PAXA/5C50BK';
$customerCode = $argv[1] ?? 'PH13-PRICE-VALIDATION';
$productSlug = 'flexible-control-lszh-3d-test';
$length = '100';
$margins = [null, 13, 20, 31, null]; // null = auto/clear

$variant = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId FROM product.ProductVariants WHERE VariantCode = :code AND DeletedAtUtc IS NULL',
    [':code' => $variantCode],
)[0] ?? null;

$customer = $db->fetchAll(
    'SELECT TOP 1 CONVERT(NVARCHAR(36), PublicId) AS publicId FROM [customer].[Customers] WHERE CustomerCode = :code',
    [':code' => $customerCode],
)[0] ?? null;

$contact = null;
if ($customer !== null && $customerCode === 'BNE-ENR-001') {
    $contact = $db->fetchAll(
        "SELECT TOP 1 CONVERT(NVARCHAR(36), cc.PublicId) AS publicId
         FROM [customer].[CustomerContacts] cc
         INNER JOIN [customer].[Customers] c ON c.CustomerId = cc.CustomerId
         WHERE c.CustomerCode = :code AND cc.DisplayName LIKE N'%Riley%'",
        [':code' => $customerCode],
    )[0] ?? null;
}

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

$extract = static function (?array $json, string $variantId): array {
    foreach ($json['data']['items'] ?? [] as $item) {
        $customer = is_array($item['customer'] ?? null) ? $item['customer'] : $item;
        if (strtoupper((string) ($customer['variantId'] ?? '')) === strtoupper($variantId)) {
            $diag = is_array($item['diagnostic'] ?? null) ? $item['diagnostic'] : [];

            return [
                'status' => $customer['status'] ?? null,
                'unitPrice' => $customer['unitPrice'] ?? null,
                'resolvedMargin' => $diag['resolvedMarginPercent'] ?? null,
                'overrideMargin' => $diag['overrideMarginPercent'] ?? null,
                'effectiveMargin' => $diag['marginPercent'] ?? null,
                'marginSource' => $diag['marginSource'] ?? null,
                'baseCost' => $diag['baseCost'] ?? null,
                'formulaPath' => $diag['formulaPath'] ?? null,
            ];
        }
    }

    return ['error' => 'not_found'];
};

if ($variant === null || $customer === null) {
    echo json_encode(['error' => 'missing data'], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$ctxPayload = ['customerId' => (string) $customer['publicId']];
if ($contact !== null) {
    $ctxPayload['contactId'] = (string) $contact['publicId'];
}
$selected = $post('/api/v1/pricing/customer-context', $ctxPayload);
$baseToken = (string) ($selected['json']['data']['pricingContextToken'] ?? '');
$currentToken = $baseToken;
$out = ['variantCode' => $variantCode, 'customerCode' => $customerCode, 'length' => $length, 'steps' => []];

$labels = ['AUTOMATIC', '13%', '20%', '31%', 'CLEAR'];
foreach ($margins as $i => $margin) {
    $label = $labels[$i];
    if ($label === 'CLEAR') {
        $override = $post('/api/v1/pricing/margin-override', ['pricingContextToken' => $baseToken, 'clear' => true]);
        $currentToken = (string) ($override['json']['data']['pricingContextToken'] ?? $baseToken);
    } elseif ($margin !== null) {
        $override = $post('/api/v1/pricing/margin-override', ['pricingContextToken' => $currentToken, 'margin' => $margin]);
        $currentToken = (string) ($override['json']['data']['pricingContextToken'] ?? $currentToken);
    }

    $batch = $post("/api/v1/products/{$productSlug}/variants/pricing", [
        'variantIds' => [(string) $variant['publicId']],
        'includeDiagnostic' => true,
        'pricingContextToken' => $currentToken,
        'length' => $length,
    ], ["X-Pricing-Context-Token: {$currentToken}"]);

    $out['steps'][$label] = [
        'batchTokenPrefix' => substr($currentToken, 0, 20),
        'batchEnvelopeOverride' => $batch['json']['data']['overrideMargin'] ?? null,
        'item' => $extract($batch['json'], (string) $variant['publicId']),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
