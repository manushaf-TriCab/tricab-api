#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Non-prod Product 3D Azure Blob acceptance smoke (connection-string mode).
 *
 * Usage (from tricab-api, with real credentials in .env — never commit them):
 *   php scripts/smoke-product-3d-azure-blob.php
 *
 * Does NOT print SAS signatures, account keys, or connection strings.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Core\Product\Contract\ProductBlobStore;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = Settings::fromEnvironment($_ENV + $_SERVER);

echo "BLOB_AUTH_MODE={$settings->blobAuthMode}\n";
echo 'blobContainer=' . $settings->blobContainerProducts . "\n";
echo 'blobCdnConfigured=' . ($settings->blobCdnBaseUrl !== '' ? 'yes' : 'no') . "\n";
echo 'blobAccountConfigured=' . ($settings->blobAccountName !== '' ? 'yes' : 'no') . "\n";
echo 'blobEndpointConfigured=' . ($settings->blobEndpoint !== '' ? 'yes' : 'no') . "\n";
echo 'blobKeyConfigured=' . ($settings->blobAccountKey !== '' ? 'yes' : 'no') . "\n";

if ($settings->blobAuthMode !== Settings::BLOB_AUTH_MODE_CONNECTION_STRING) {
    fwrite(STDERR, "FAIL: Set BLOB_AUTH_MODE=connection-string and restart API before this smoke.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
$store = $container->get(ProductBlobStore::class);
if (!$store instanceof AzureProductBlobStore) {
    fwrite(STDERR, 'FAIL: ProductBlobStore is ' . $store::class . ", expected AzureProductBlobStore.\n");
    exit(3);
}

$productPublicId = getenv('SMOKE_PRODUCT_PUBLIC_ID') ?: '9970E6F0-F091-F111-B337-000D3AE094E4';
$modelPublicId = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff),
    random_int(0, 0xffff),
    random_int(0, 0xffff),
);
$blobPath = sprintf(
    'products/%s/3d/%s/model/smoke-phase1.glb',
    strtolower($productPublicId),
    strtolower($modelPublicId),
);
$containerName = $settings->blobContainerProducts;
$contentType = 'model/gltf-binary';
$binary = "glTF" . pack('V', 2) . pack('V', 20) . str_repeat("\0", 8);

$ticket = $store->issueWriteUpload($containerName, $blobPath, $contentType, $settings->blobUploadSasTtlSeconds);
$uploadHost = parse_url($ticket->uploadUrl, PHP_URL_HOST) ?: '';
$query = [];
parse_str((string) parse_url($ticket->uploadUrl, PHP_URL_QUERY), $query);
echo "uploadHost={$uploadHost}\n";
echo 'sasKeys=' . implode(',', array_keys($query)) . "\n";
echo 'sasSp=' . ($query['sp'] ?? '') . "\n";
echo 'sasSr=' . ($query['sr'] ?? '') . "\n";
echo 'sasSpr=' . ($query['spr'] ?? '') . "\n";
echo 'expiresAtUtc=' . $ticket->expiresAtUtc . "\n";
echo "blobPath={$blobPath}\n";

if (($query['sp'] ?? '') !== 'cw' || ($query['sr'] ?? '') !== 'b' || ($query['spr'] ?? '') !== 'https') {
    fwrite(STDERR, "FAIL: unexpected SAS scope.\n");
    exit(4);
}
if (isset($query['ss']) || isset($query['srt'])) {
    fwrite(STDERR, "FAIL: account-SAS params present on service SAS.\n");
    exit(4);
}

$ch = curl_init($ticket->uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $binary,
    CURLOPT_HTTPHEADER => [
        'Content-Type: ' . $contentType,
        'x-ms-blob-type: BlockBlob',
        'Content-Length: ' . (string) strlen($binary),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
]);
$raw = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "putHttp={$status}\n";
if ($status < 200 || $status >= 300) {
    fwrite(STDERR, "FAIL: Blob PUT failed (redacted body).\n");
    exit(5);
}

$head = $store->headObject($containerName, $blobPath);
if ($head === null || !$head->exists) {
    fwrite(STDERR, "FAIL: HEAD after PUT missing.\n");
    exit(6);
}
echo 'headExists=yes contentType=' . ($head->contentType ?? 'null') . ' contentLength=' . ($head->contentLength ?? 'null') . "\n";

$publicUrl = $store->buildPublicUrl($containerName, $blobPath) ?? '';
$publicHost = parse_url($publicUrl, PHP_URL_HOST) ?: '';
echo 'publicUrlHost=' . $publicHost . "\n";
echo 'publicUrlIsDevEndpoint=' . (str_contains($publicUrl, '/api/v1/dev/product-files/') ? 'yes' : 'no') . "\n";
if ($publicUrl === '' || str_contains($publicUrl, '/api/v1/dev/product-files/')) {
    fwrite(STDERR, "FAIL: public URL must be Azure/CDN-backed in connection-string mode.\n");
    exit(7);
}

echo "SMOKE_OK\n";
exit(0);
