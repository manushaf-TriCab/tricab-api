<?php

declare(strict_types=1);

/**
 * Phase 13.7F.3 — HTTP margin override flow (mirrors browser API sequence).
 * Usage: php bin/phase137f3-http-margin-flow.php
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

$variantCode = 'BVA-PAXD/2C35BK';
$customerCode = 'BNE-ENR-001';
$productSlug = 'flexible-control-lszh-3d-test';
$length = '100';

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

if ($variant === null || $customer === null || $contact === null) {
    fwrite(STDERR, json_encode(['error' => 'missing variant/customer/contact'], JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
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
    curl_close($ch);

    return [
        'status' => $status,
        'json' => json_decode((string) $body, true),
        'raw' => (string) $body,
    ];
};

$extractBatchItem = static function (?array $json, string $variantId): array {
    $items = $json['data']['items'] ?? [];
    foreach ($items as $item) {
        $customer = is_array($item['customer'] ?? null) ? $item['customer'] : $item;
        $id = strtoupper((string) ($customer['variantId'] ?? ''));
        if ($id === strtoupper($variantId)) {
            $diag = is_array($item['diagnostic'] ?? null) ? $item['diagnostic'] : [];

            return [
                'status' => $customer['status'] ?? null,
                'unitPrice' => $customer['unitPrice'] ?? null,
                'resolvedMargin' => $diag['resolvedMarginPercent'] ?? null,
                'overrideMargin' => $diag['overrideMarginPercent'] ?? null,
                'effectiveMargin' => $diag['marginPercent'] ?? null,
                'marginSource' => $diag['marginSource'] ?? null,
            ];
        }
    }

    return ['error' => 'variant_not_in_batch'];
};

$out = ['steps' => []];

$selected = $post('/api/v1/pricing/customer-context', [
    'customerId' => (string) $customer['publicId'],
    'contactId' => (string) $contact['publicId'],
]);
$token = (string) ($selected['json']['data']['pricingContextToken'] ?? '');
$out['steps']['customerContext'] = [
    'httpStatus' => $selected['status'],
    'tokenPrefix' => substr($token, 0, 24),
    'overrideMargin' => $selected['json']['data']['overrideMargin'] ?? null,
];

$autoBatch = $post("/api/v1/products/{$productSlug}/variants/pricing", [
    'variantIds' => [(string) $variant['publicId']],
    'includeDiagnostic' => true,
    'pricingContextToken' => $token,
    'length' => $length,
], ["X-Pricing-Context-Token: {$token}"]);
$out['steps']['automaticBatch'] = [
    'httpStatus' => $autoBatch['status'],
    'batchOverrideMargin' => $autoBatch['json']['data']['overrideMargin'] ?? null,
    'item' => $extractBatchItem($autoBatch['json'], (string) $variant['publicId']),
];

$currentToken = $token;
foreach ([3, 10, 31] as $pct) {
    $override = $post('/api/v1/pricing/margin-override', [
        'pricingContextToken' => $currentToken,
        'margin' => $pct,
    ]);
    $newToken = (string) ($override['json']['data']['pricingContextToken'] ?? '');
    $batch = $post("/api/v1/products/{$productSlug}/variants/pricing", [
        'variantIds' => [(string) $variant['publicId']],
        'includeDiagnostic' => true,
        'pricingContextToken' => $newToken,
        'length' => $length,
    ], ["X-Pricing-Context-Token: {$newToken}"]);

    $out['steps'][(string) $pct . 'pct'] = [
        'overrideRequest' => ['margin' => $pct],
        'overrideResponse' => [
            'httpStatus' => $override['status'],
            'overrideMargin' => $override['json']['data']['overrideMargin'] ?? null,
            'tokenChanged' => $newToken !== '' && $newToken !== $currentToken,
            'tokenPrefix' => substr($newToken, 0, 24),
        ],
        'batch' => [
            'httpStatus' => $batch['status'],
            'tokenUsedPrefix' => substr($newToken, 0, 24),
            'batchOverrideMargin' => $batch['json']['data']['overrideMargin'] ?? null,
            'item' => $extractBatchItem($batch['json'], (string) $variant['publicId']),
        ],
    ];
    $currentToken = $newToken !== '' ? $newToken : $currentToken;
}

$cleared = $post('/api/v1/pricing/margin-override', [
    'pricingContextToken' => $token,
    'clear' => true,
]);
$clearToken = (string) ($cleared['json']['data']['pricingContextToken'] ?? '');
$clearBatch = $post("/api/v1/products/{$productSlug}/variants/pricing", [
    'variantIds' => [(string) $variant['publicId']],
    'includeDiagnostic' => true,
    'pricingContextToken' => $clearToken,
    'length' => $length,
], ["X-Pricing-Context-Token: {$clearToken}"]);
$out['steps']['clearOverride'] = [
    'overrideResponse' => [
        'httpStatus' => $cleared['status'],
        'overrideMargin' => $cleared['json']['data']['overrideMargin'] ?? null,
    ],
    'batch' => [
        'httpStatus' => $clearBatch['status'],
        'item' => $extractBatchItem($clearBatch['json'], (string) $variant['publicId']),
    ],
];

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
