<?php

declare(strict_types=1);

/**
 * PDF Catalogues blob migration — VERIFY (default) / APPLY.
 *
 * Default is dry-run only. Never sets DocumentAvailable=1 until blob HEAD
 * verification succeeds after upload.
 *
 * Usage:
 *   php bin/migrate-pdf-catalogues.php
 *   php bin/migrate-pdf-catalogues.php --verify
 *   php bin/migrate-pdf-catalogues.php --apply
 *
 * Staging inputs (gitignored binaries):
 *   var/catalogue-pdf-migration/catalogue-pdf-migration-manifest.json
 *
 * Tracked checksum manifest:
 *   database/scripts/development/catalogue-pdf-migration-manifest.json
 *
 * STOP conditions:
 *   - APP_ENV=production
 *   - mapping validation failure
 *   - PDF magic / sha256 mismatch
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\PdfCatalogue\Domain\PdfCatalogueInventory;
use TriCab\Core\PdfCatalogue\Module;
use TriCab\Core\Product\Contract\ProductBlobStore;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$apply = in_array('--apply', $argv ?? [], true);
$verifyOnly = !$apply;

$settings = Environment::settings($basePath);
if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "STOP: refuse to run against APP_ENV=production\n");
    exit(1);
}

$stagingManifestPath = $basePath . '/var/catalogue-pdf-migration/catalogue-pdf-migration-manifest.json';
$trackedManifestPath = $basePath . '/database/scripts/development/catalogue-pdf-migration-manifest.json';
if (!is_file($stagingManifestPath)) {
    fwrite(STDERR, "STOP: missing staging manifest: {$stagingManifestPath}\n");
    fwrite(STDERR, "Retrieve and verify live PDFs into var/catalogue-pdf-migration/ first.\n");
    exit(2);
}

/** @var array<string, mixed> $manifest */
$manifest = json_decode((string) file_get_contents($stagingManifestPath), true, 512, JSON_THROW_ON_ERROR);
/** @var list<array<string, mixed>> $items */
$items = $manifest['items'] ?? [];
if ($items === []) {
    fwrite(STDERR, "STOP: manifest has no items\n");
    exit(2);
}

$definitions = [];
foreach (PdfCatalogueInventory::definitions() as $def) {
    $definitions[$def['seedKey']] = $def;
}

echo 'MODE=' . ($apply ? 'APPLY' : 'VERIFY') . "\n";
echo "APP_ENV={$settings->appEnv}\n";
echo 'BLOB_AUTH_MODE=' . $settings->blobAuthMode . "\n";
echo 'TRACKED_MANIFEST=' . (is_file($trackedManifestPath) ? 'yes' : 'missing') . "\n";

$mappingErrors = [];
$seenSeed = [];
$seenBlob = [];
foreach ($items as $item) {
    $seedKey = (string) ($item['seedKey'] ?? '');
    $blobPath = (string) ($item['blobPath'] ?? '');
    $title = (string) ($item['title'] ?? '');
    $issue = (string) ($item['issue'] ?? '');
    $status = (string) ($item['status'] ?? '');
    $legacy = (string) ($item['legacyRelativePath'] ?? '');

    if ($seedKey === '' || isset($seenSeed[$seedKey])) {
        $mappingErrors[] = "duplicate/missing seedKey: {$seedKey}";
    }
    $seenSeed[$seedKey] = true;

    if ($blobPath === '' || isset($seenBlob[$blobPath])) {
        $mappingErrors[] = "duplicate/missing blobPath: {$blobPath}";
    }
    $seenBlob[$blobPath] = true;

    $def = $definitions[$seedKey] ?? null;
    if ($def === null) {
        $mappingErrors[] = "manifest seedKey not in inventory: {$seedKey}";
        continue;
    }
    if ($def['title'] !== $title) {
        $mappingErrors[] = "title mismatch {$seedKey}: manifest={$title} inventory={$def['title']}";
    }
    if ($def['issue'] !== $issue) {
        $mappingErrors[] = "issue mismatch {$seedKey}: manifest={$issue} inventory={$def['issue']}";
    }
    if ($def['documentBlobPath'] !== $blobPath) {
        $mappingErrors[] = "blobPath mismatch {$seedKey}: manifest={$blobPath} inventory={$def['documentBlobPath']}";
    }
    if ($def['legacyRelativePath'] !== $legacy) {
        $mappingErrors[] = "legacy path mismatch {$seedKey}: manifest={$legacy} inventory={$def['legacyRelativePath']}";
    }
    if ($status !== 'VERIFIED') {
        $mappingErrors[] = "source not VERIFIED for {$seedKey}: {$status}";
    }
}

foreach (array_keys($definitions) as $seedKey) {
    if (!isset($seenSeed[$seedKey])) {
        $mappingErrors[] = "inventory seedKey missing from manifest: {$seedKey}";
    }
}

if ($mappingErrors !== []) {
    fwrite(STDERR, "MAPPING_VALIDATION_FAILED\n");
    foreach ($mappingErrors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(3);
}
echo "MAPPING_VALIDATION=ok (" . count($items) . " catalogues)\n";

$container = ContainerFactory::create($settings);
$blobContainer = trim($settings->blobContainerCatalogues);
if ($blobContainer === '') {
    $blobContainer = Module::BLOB_CONTAINER;
}
echo "BLOB_CONTAINER={$blobContainer}\n";

/** @var ProductBlobStore $blobs */
$blobs = $container->get(ProductBlobStore::class);

$verifyRows = [];
foreach ($items as $item) {
    $seedKey = (string) $item['seedKey'];
    $localPath = (string) ($item['localPath'] ?? '');
    $expectedSha = (string) ($item['sha256'] ?? '');
    $expectedSize = (int) ($item['sizeBytes'] ?? 0);
    $blobPath = (string) $item['blobPath'];

    $row = [
        'seedKey' => $seedKey,
        'blobPath' => $blobPath,
        'localOk' => false,
        'shaOk' => false,
        'upload' => 'skipped',
        'headOk' => false,
        'documentAvailable' => false,
        'error' => null,
    ];

    if ($localPath === '' || !is_file($localPath)) {
        $row['error'] = 'local PDF missing';
        $verifyRows[] = $row;
        continue;
    }
    $binary = (string) file_get_contents($localPath);
    $row['localOk'] = str_starts_with($binary, '%PDF-') && strlen($binary) === $expectedSize;
    $actualSha = hash('sha256', $binary);
    $row['shaOk'] = hash_equals($expectedSha, $actualSha);
    if (!$row['localOk'] || !$row['shaOk']) {
        $row['error'] = 'local PDF integrity failed';
        $verifyRows[] = $row;
        continue;
    }

    if ($verifyOnly) {
        $row['upload'] = 'dry-run';
        $verifyRows[] = $row;
        continue;
    }

    // APPLY path — still refuse production (already gated).
    try {
        $blobs->putObject($blobContainer, $blobPath, 'application/pdf', $binary);
        $row['upload'] = 'put';
        $head = $blobs->headObject($blobContainer, $blobPath);
        $row['headOk'] = $head !== null && $head->sizeBytes === $expectedSize;
        if (!$row['headOk']) {
            $row['error'] = 'blob HEAD missing or size mismatch; DocumentAvailable left 0';
            $verifyRows[] = $row;
            continue;
        }

        // Optional round-trip hash when adapter can read.
        $downloaded = $blobs->getObjectBinary($blobContainer, $blobPath);
        if (is_string($downloaded) && $downloaded !== '') {
            if (!hash_equals($expectedSha, hash('sha256', $downloaded))) {
                $row['error'] = 'downloaded blob sha256 mismatch; DocumentAvailable left 0';
                $verifyRows[] = $row;
                continue;
            }
        }

        $updated = markDocumentAvailable($container, $seedKey, $blobPath);
        $row['documentAvailable'] = $updated;
        if (!$updated) {
            $row['error'] = 'blob verified but DB update skipped/failed (Azure SQL catalogue schema required)';
        }
    } catch (Throwable $e) {
        $row['error'] = $e->getMessage();
        $row['upload'] = 'failed';
    }

    $verifyRows[] = $row;
}

$failed = array_values(array_filter(
    $verifyRows,
    static fn (array $row): bool => $row['error'] !== null || $row['localOk'] !== true || $row['shaOk'] !== true,
));

foreach ($verifyRows as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
}

echo 'RESULT=' . ($failed === [] ? 'OK' : 'FAILED') . "\n";
echo 'FAILED_COUNT=' . count($failed) . "\n";
if ($verifyOnly) {
    echo "NOTE=VERIFY only — no blob writes, DocumentAvailable unchanged\n";
}
exit($failed === [] ? 0 : 4);

/**
 * @param \Psr\Container\ContainerInterface $container
 */
function markDocumentAvailable($container, string $seedKey, string $blobPath): bool
{
    try {
        /** @var SqlConnection $db */
        $db = $container->get(SqlConnection::class);
    } catch (Throwable) {
        return false;
    }

    try {
        $affected = $db->execute(
            <<<'SQL'
UPDATE [catalogue].[Catalogues]
SET
    [DocumentBlobPath] = :blobPath,
    [DocumentAvailable] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [SeedKey] = :seedKey
  AND [DeletedAtUtc] IS NULL
SQL
            ,
            [
                'blobPath' => $blobPath,
                'seedKey' => $seedKey,
            ],
        );

        return $affected > 0;
    } catch (Throwable $e) {
        fwrite(STDERR, '[migrate-pdf-catalogues] DB update failed for ' . $seedKey . ': ' . $e->getMessage() . "\n");

        return false;
    }
}
