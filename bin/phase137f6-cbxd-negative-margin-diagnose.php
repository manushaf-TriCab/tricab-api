<?php

declare(strict_types=1);

/**
 * Phase 13.7F.6 — CBXD / negative margin pricing diagnosis (read-only).
 *
 * Usage:
 *   php bin/phase137f6-cbxd-negative-margin-diagnose.php [productSlug] [length]
 *
 * Defaults: flexible-control-lszh-3d-test, 100
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\CoreAutoload;

CoreAutoload::register();

use Dotenv\Dotenv;
use TriCab\Core\Product\Application\Pricing\StaffMarginOverridePolicy;
use TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$productSlug = $argv[1] ?? 'flexible-control-lszh-3d-test';
$length = $argv[2] ?? '100';
$customerCode = 'BNE-ENR-001';
$failCode = 'BVA-CBXD/9C1BK';
$ctrlCode = 'BVA-PAXA/5C50BK';
$margins = [-31, -8, 3, 10, 31, 44, null]; // null = automatic / clear override

$baseUrl = rtrim(getenv('PHASE137_API_BASE') ?: 'http://localhost:8080', '/');
$devHeaders = [
    'X-Dev-Entra-Tenant: development-tenant',
    'X-Dev-Entra-Subject: development-thilan',
    'X-Dev-Entra-Email: thilanh@tricab.com',
    'Content-Type: application/json',
    'Accept: application/json',
];

$get = static function (string $path) use ($baseUrl, $devHeaders): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $devHeaders,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'json' => json_decode((string) $body, true)];
};

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

    return ['status' => $status, 'json' => json_decode((string) $body, true)];
};

$extractItem = static function (?array $json, string $variantId): array {
    foreach ($json['data']['items'] ?? [] as $item) {
        $customer = is_array($item['customer'] ?? null) ? $item['customer'] : $item;
        $id = strtoupper((string) ($customer['variantId'] ?? ''));
        if ($id === strtoupper($variantId)) {
            $diag = is_array($item['diagnostic'] ?? null) ? $item['diagnostic'] : [];

            return [
                'variantId' => $customer['variantId'] ?? null,
                'variantCode' => $customer['variantCode'] ?? null,
                'status' => $customer['status'] ?? null,
                'displayMessage' => $customer['displayMessage'] ?? null,
                'availability' => $customer['availability'] ?? null,
                'unitPrice' => $customer['unitPrice'] ?? null,
                'currency' => $customer['currency'] ?? null,
                'resolvedMargin' => $diag['resolvedMarginPercent'] ?? null,
                'overrideMargin' => $diag['overrideMarginPercent'] ?? null,
                'effectiveMargin' => $diag['marginPercent'] ?? null,
                'marginSource' => $diag['marginSource'] ?? null,
                'baseCost' => $diag['baseCost'] ?? null,
                'fxRate' => $diag['fxRate'] ?? null,
                'formulaPath' => $diag['formulaPath'] ?? null,
                'costSource' => $diag['costSource'] ?? null,
                'trace' => $diag['trace'] ?? [],
            ];
        }
    }

    return ['error' => 'variant_not_in_batch', 'itemsCount' => count($json['data']['items'] ?? [])];
};

$findVariant = static function (array $response, string $code): ?array {
    foreach ($response['json']['data']['variantMatrix']['rows'] ?? [] as $row) {
        if (($row['variantCode'] ?? '') === $code) {
            return $row;
        }
    }

    return null;
};

$customerView = $get('/api/v1/products/' . rawurlencode($productSlug) . '/customer-view');
if (($customerView['status'] ?? 0) !== 200) {
    echo json_encode(['error' => 'product_not_found', 'slug' => $productSlug, 'response' => $customerView], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$failVariant = $findVariant($customerView, $failCode);
$ctrlVariant = $findVariant($customerView, $ctrlCode);
if ($failVariant === null) {
    echo json_encode(['error' => 'failing_variant_not_in_product', 'code' => $failCode], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$customers = $get('/api/v1/pricing/customers?query=' . rawurlencode('BNE'));
$customerId = null;
foreach ($customers['json']['data']['items'] ?? [] as $hit) {
    if (($hit['customerCode'] ?? '') === $customerCode) {
        $customerId = (string) $hit['publicId'];
        break;
    }
}
if ($customerId === null) {
    echo json_encode(['error' => 'customer_not_found', 'response' => $customers], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$contacts = $get('/api/v1/pricing/customers/' . rawurlencode($customerId) . '/contacts');
$contactId = null;
foreach ($contacts['json']['data']['items'] ?? [] as $contact) {
    if (str_contains((string) ($contact['displayName'] ?? ''), 'Riley')) {
        $contactId = (string) $contact['publicId'];
        break;
    }
}
if ($contactId === null) {
    echo json_encode(['error' => 'contact_not_found', 'response' => $contacts], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$ctxResp = $post('/api/v1/pricing/customer-context', [
    'customerId' => $customerId,
    'contactId' => $contactId,
]);
$baseToken = (string) ($ctxResp['json']['data']['pricingContextToken'] ?? '');

$marginMatrix = [];
$policy = [
    'allowedMin' => StaffMarginOverridePolicy::MIN,
    'allowedMax' => StaffMarginOverridePolicy::MAX,
    'acceptsMinus31' => in_array(-31, StaffMarginOverridePolicy::allowedValues(), true),
];

$formula = new LegacyCustomerSellFormulaContract();

foreach ($margins as $margin) {
    $label = $margin === null ? 'automatic' : (string) $margin;
    $token = $baseToken;
    $overrideStep = null;

    if ($margin !== null) {
        try {
            StaffMarginOverridePolicy::normalize($margin);
        } catch (Throwable $e) {
            $marginMatrix[$label] = ['policyError' => $e->getMessage()];
            continue;
        }
        $overrideStep = $post('/api/v1/pricing/margin-override', [
            'pricingContextToken' => $token,
            'margin' => $margin,
        ]);
        $token = (string) ($overrideStep['json']['data']['pricingContextToken'] ?? $token);
    } else {
        $overrideStep = $post('/api/v1/pricing/margin-override', [
            'pricingContextToken' => $token,
            'clear' => true,
        ]);
        $token = (string) ($overrideStep['json']['data']['pricingContextToken'] ?? $token);
    }

    $variantIds = array_values(array_filter([
        (string) ($failVariant['publicId'] ?? ''),
        $ctrlVariant !== null ? (string) ($ctrlVariant['publicId'] ?? '') : null,
    ]));
    $batch = $post('/api/v1/products/' . rawurlencode($productSlug) . '/variants/pricing', [
        'variantIds' => $variantIds,
        'includeDiagnostic' => true,
        'pricingContextToken' => $token,
        'length' => $length,
    ], ["X-Pricing-Context-Token: {$token}"]);

    $failItem = $extractItem($batch['json'], (string) $failVariant['publicId']);
    $ctrlItem = $ctrlVariant !== null
        ? $extractItem($batch['json'], (string) $ctrlVariant['publicId'])
        : ['error' => 'control_not_in_product'];

    $formulaCalc = null;
    if (
        isset($failItem['baseCost'], $failItem['fxRate'], $failItem['effectiveMargin'])
        && is_numeric((string) $failItem['baseCost'])
        && is_numeric((string) $failItem['fxRate'])
        && is_numeric((string) $failItem['effectiveMargin'])
    ) {
        try {
            $converted = $formula->convertBaseCost((string) $failItem['baseCost'], (string) $failItem['fxRate']);
            $denominator = bcsub('100', (string) $failItem['effectiveMargin'], 12);
            $formulaCalc = [
                'convertedBase' => $converted,
                'denominator' => $denominator,
                'sell' => $formula->resolveSellPrice((string) $failItem['baseCost'], (string) $failItem['fxRate'], (string) $failItem['effectiveMargin']),
            ];
        } catch (Throwable $e) {
            $formulaCalc = ['error' => $e->getMessage()];
        }
    }

    $marginMatrix[$label] = [
        'marginOverrideRequest' => [
            'httpStatus' => $overrideStep['status'] ?? null,
            'payloadMargin' => $margin,
            'responseOverrideMargin' => $overrideStep['json']['data']['overrideMargin'] ?? null,
            'tokenChanged' => $token !== $baseToken,
            'tokenPrefix' => substr($token, 0, 28),
        ],
        'batch' => [
            'httpStatus' => $batch['status'] ?? null,
            'batchTokenPrefix' => substr($token, 0, 28),
            'overrideMarginInBatch' => $batch['json']['data']['overrideMargin'] ?? null,
        ],
        'failing' => $failItem,
        'control' => $ctrlItem,
        'formulaCalc' => $formulaCalc,
    ];
}

echo json_encode([
    'phase' => '13.7F.6',
    'productSlug' => $productSlug,
    'length' => $length,
    'customer' => ['code' => $customerCode, 'publicId' => $customerId, 'contactId' => $contactId],
    'variants' => [
        'failing' => [
            'code' => $failCode,
            'publicId' => $failVariant['publicId'] ?? null,
            'variantName' => $failVariant['variantName'] ?? null,
        ],
        'control' => $ctrlVariant !== null ? [
            'code' => $ctrlCode,
            'publicId' => $ctrlVariant['publicId'] ?? null,
            'variantName' => $ctrlVariant['variantName'] ?? null,
        ] : null,
    ],
    'negativeMarginPolicy' => $policy,
    'marginMatrix' => $marginMatrix,
], JSON_PRETTY_PRINT) . PHP_EOL;
