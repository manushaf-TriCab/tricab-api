<?php

declare(strict_types=1);

/**
 * Phase 4H Technical Document Library migration CLI.
 *
 * Usage:
 *   php bin/phase4h-technical-document-migration.php freeze
 *   php bin/phase4h-technical-document-migration.php revalidate
 *   php bin/phase4h-technical-document-migration.php canary
 *   php bin/phase4h-technical-document-migration.php migrate-remaining
 *   php bin/phase4h-technical-document-migration.php reconcile
 *   php bin/phase4h-technical-document-migration.php idempotency-check
 *
 * Requires env PHASE4H_EXECUTE=true for canary/migrate-remaining writes.
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Api\Technical\Document\TechnicalDocumentPathBuilder;
use TriCab\Api\Technical\Migration\Phase4HTechnicalDocumentMigrator;
use TriCab\Api\Technical\Persistence\AzureSqlTechnicalDocumentLibraryRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$docsRoot = dirname($basePath, 2) . '/tc-web/docs/technical';
// workspace layout: Projects/tricab-core/tricab-api and Projects/tc-web
$docsRoot = '/Users/thilanh/Documents/Projects/tc-web/docs/technical';
$outDir = $docsRoot . '/phase4h';
@mkdir($outDir, 0775, true);
$cacheDir = '/tmp/tricab-phase4h-sources';
@mkdir($cacheDir, 0775, true);

$command = $argv[1] ?? '';
$settings = Environment::settings($basePath);

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll('SELECT DB_NAME() AS db_name, @@SERVERNAME AS server_name, SUSER_SNAME() AS login_name')[0];
$dbName = strtolower((string) $identity['db_name']);
$server = strtolower((string) $identity['server_name']);
if ($dbName !== 'tc' || !str_contains($server, 'tricab-mel')) {
    fwrite(STDERR, "STOP: target identity not NON_PRODUCTION tricab-mel/tc\n");
    exit(1);
}

/** @var ProductBlobStore $blob */
$blob = $container->get(ProductBlobStore::class);
/** @var AzureSqlTechnicalDocumentLibraryRepository $library */
$library = $container->get(\TriCab\Api\Technical\Document\TechnicalDocumentLibraryRepository::class);
if (!$library instanceof AzureSqlTechnicalDocumentLibraryRepository) {
    fwrite(STDERR, "STOP: Technical library repository is not Azure SQL adapter\n");
    exit(1);
}

$migrator = new Phase4HTechnicalDocumentMigrator(
    $db,
    $blob,
    $library,
    new TechnicalDocumentPathBuilder(),
    $settings->blobContainerProducts,
    $cacheDir,
);

$canaryLegacyIds = ['3725', '4768', 'install-b06', 'acc-warranty-x07-x08', '4347'];

switch ($command) {
    case 'freeze':
        $frozen = freezeAllowList($docsRoot, $outDir, $identity, $settings, $db, $canaryLegacyIds);
        echo "Frozen {$frozen['approvedCount']} candidates hash={$frozen['allowListHash']}\n";
        break;
    case 'revalidate':
        $result = revalidateAll($outDir, $migrator);
        echo "Source-valid={$result['sourceValid']} drift={$result['sourceDrift']}\n";
        break;
    case 'canary':
        requireExecuteGate();
        $result = runBatch($outDir, $migrator, $db, $blob, $settings, $library, $container, 'canary', $canaryLegacyIds);
        echo json_encode($result['summary'], JSON_PRETTY_PRINT) . "\n";
        break;
    case 'migrate-remaining':
        requireExecuteGate();
        assertCanaryPassed($outDir);
        $frozen = json_decode((string) file_get_contents($outDir . '/allow-list-frozen.json'), true, 512, JSON_THROW_ON_ERROR);
        $canaryDone = json_decode((string) file_get_contents($outDir . '/canary-result.json'), true, 512, JSON_THROW_ON_ERROR);
        $done = [];
        foreach (($canaryDone['results'] ?? []) as $row) {
            if (in_array($row['status'] ?? '', ['CREATED', 'REUSE_EXISTING'], true)) {
                $done[(string) $row['legacyId']] = true;
            }
        }
        $remaining = [];
        foreach ($frozen['candidates'] as $c) {
            $id = (string) $c['legacyId'];
            if (!isset($done[$id])) {
                $remaining[] = $id;
            }
        }
        // batches of 25
        $batchSize = 25;
        $allSummaries = [];
        for ($i = 0; $i < count($remaining); $i += $batchSize) {
            $slice = array_slice($remaining, $i, $batchSize);
            $batchNo = (int) floor($i / $batchSize) + 1;
            echo "Batch {$batchNo}: " . count($slice) . " candidates...\n";
            $result = runBatch($outDir, $migrator, $db, $blob, $settings, $library, $container, 'batch-' . $batchNo, $slice);
            $allSummaries[] = $result['summary'];
            file_put_contents($outDir . '/batch-' . $batchNo . '-summary.json', json_encode($result['summary'], JSON_PRETTY_PRINT) . "\n");
        }
        file_put_contents($outDir . '/remaining-batches-summary.json', json_encode($allSummaries, JSON_PRETTY_PRINT) . "\n");
        echo "Remaining batches complete: " . count($allSummaries) . "\n";
        break;
    case 'reconcile':
        $recon = reconcile($outDir, $db, $blob, $settings, $container, $library);
        echo json_encode($recon['counts'], JSON_PRETTY_PRINT) . "\n";
        break;
    case 'idempotency-check':
        $idem = idempotencyCheck($outDir, $migrator);
        echo json_encode($idem, JSON_PRETTY_PRINT) . "\n";
        break;
    default:
        fwrite(STDERR, "Unknown command. Use freeze|revalidate|canary|migrate-remaining|reconcile|idempotency-check\n");
        exit(1);
}

function requireExecuteGate(): void
{
    $flag = getenv('PHASE4H_EXECUTE') ?: ($_ENV['PHASE4H_EXECUTE'] ?? '');
    if ($flag !== 'true') {
        fwrite(STDERR, "STOP: set PHASE4H_EXECUTE=true to enable Technical Document Library writes\n");
        exit(1);
    }
}

function assertCanaryPassed(string $outDir): void
{
    $path = $outDir . '/canary-gate.json';
    if (!is_file($path)) {
        fwrite(STDERR, "STOP: canary gate missing\n");
        exit(1);
    }
    $gate = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($gate['status'] ?? '') !== 'PASS') {
        fwrite(STDERR, "STOP: canary gate not PASS\n");
        exit(1);
    }
}

/**
 * @param list<string> $canaryLegacyIds
 * @return array<string, mixed>
 */
function freezeAllowList(
    string $docsRoot,
    string $outDir,
    array $identity,
    $settings,
    SqlConnection $db,
    array $canaryLegacyIds,
): array {
    $plan = json_decode((string) file_get_contents($docsRoot . '/phase4/document-migration-plan.json'), true, 512, JSON_THROW_ON_ERROR);
    $v2 = json_decode((string) file_get_contents($docsRoot . '/phase4f/document-migration-plan-v2.json'), true, 512, JSON_THROW_ON_ERROR);
    $planById = [];
    foreach ($plan['items'] as $item) {
        $planById[(string) $item['legacyId']] = $item;
    }
    $candidates = [];
    foreach ($v2['items'] as $item) {
        if (($item['phase4dAction'] ?? '') !== 'CREATE') {
            continue;
        }
        $id = (string) $item['legacyId'];
        $p = $planById[$id] ?? null;
        if ($p === null) {
            throw new RuntimeException('Missing phase4 plan row for ' . $id);
        }
        $candidates[] = [
            'legacyId' => $id,
            'legacyTitle' => $item['legacyTitle'] ?? $p['legacyTitle'] ?? null,
            'family' => $item['family'] ?? $p['family'] ?? null,
            'filename' => $item['filename'] ?? $p['filename'],
            'sha256' => strtolower((string) ($item['sha256'] ?? $p['sha256'])),
            'size' => (int) ($p['size'] ?? 0),
            'pdfValid' => (bool) ($item['pdfValid'] ?? $p['pdfValid'] ?? false),
            'targetDocumentType' => $item['targetDocumentType'] ?? $p['targetDocumentType'],
            'styleAssociationStatus' => $item['styleAssociationStatus'] ?? $p['styleAssociationStatus'],
            'legacyStyles' => $item['legacyStyles'] ?? $p['legacyStyles'] ?? [],
            'newProductStyleIds' => $item['newProductStyleIds'] ?? $p['newProductStyleIds'] ?? [],
            'v2Action' => $item['v2Action'],
            'ownership' => $item['ownership'] ?? null,
            'technicalCategories' => $item['technicalCategories'] ?? [],
            'issuer' => $p['issuer'] ?? null,
            'sourceUrl' => $p['sourceUrl'] ?? null,
            'sourcePath' => $p['sourcePath'] ?? null,
        ];
    }
    if (count($candidates) !== 204) {
        throw new RuntimeException('Allow-list count != 204: ' . count($candidates));
    }
    usort($candidates, static fn (array $a, array $b): int => strcmp((string) $a['legacyId'], (string) $b['legacyId']));
    $canonical = json_encode($candidates, JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', (string) $canonical);

    $counts = $db->fetchAll(<<<'SQL'
SELECT
  (SELECT COUNT(*) FROM technical.Documents) AS documents,
  (SELECT COUNT(*) FROM technical.DocumentCategoryLinks) AS category_links,
  (SELECT COUNT(*) FROM technical.DocumentProductLinks) AS product_links,
  (SELECT COUNT(*) FROM technical.DocumentStyleLinks) AS style_links,
  (SELECT COUNT(*) FROM product.ProductDocuments) AS product_documents
SQL)[0];

    $snapshot = [
        'generatedAt' => gmdate('c'),
        'phase' => '4H',
        'environment' => [
            'appEnv' => $settings->appEnv,
            'server' => $identity['server_name'],
            'database' => $identity['db_name'],
            'login' => $identity['login_name'],
            'blobContainer' => $settings->blobContainerProducts,
            'blobPathPrefix' => 'products/technical/documents/{documentPublicId}/{safeFileName}',
            'classification' => 'NON_PRODUCTION_VERIFIED',
        ],
        'counts' => [
            'technicalDocuments' => (int) $counts['documents'],
            'categoryLinks' => (int) $counts['category_links'],
            'productLinks' => (int) $counts['product_links'],
            'styleLinks' => (int) $counts['style_links'],
            'productDocuments' => (int) $counts['product_documents'],
        ],
        'approvedCandidates' => 204,
        'allowListHash' => $hash,
        'canaryLegacyIds' => $canaryLegacyIds,
        'executionGate' => 'PHASE4H_EXECUTE',
        'legacyMigrationBlobWritesEnabled' => false,
    ];
    file_put_contents($outDir . '/pre-migration-snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT) . "\n");

    $frozen = [
        'generatedAt' => gmdate('c'),
        'approvedCount' => 204,
        'allowListHash' => $hash,
        'canaryLegacyIds' => $canaryLegacyIds,
        'candidates' => $candidates,
    ];
    file_put_contents($outDir . '/allow-list-frozen.json', json_encode($frozen, JSON_PRETTY_PRINT) . "\n");
    file_put_contents($outDir . '/rollback-manifest.json', json_encode([
        'phase' => '4H',
        'generatedAt' => gmdate('c'),
        'objects' => [],
    ], JSON_PRETTY_PRINT) . "\n");

    return $frozen;
}

/**
 * @return array{sourceValid:int, sourceDrift:int}
 */
function revalidateAll(string $outDir, Phase4HTechnicalDocumentMigrator $migrator): array
{
    $frozen = json_decode((string) file_get_contents($outDir . '/allow-list-frozen.json'), true, 512, JSON_THROW_ON_ERROR);
    $results = [];
    $valid = 0;
    $drift = 0;
    foreach ($frozen['candidates'] as $i => $candidate) {
        $row = $migrator->revalidateSource($candidate);
        $results[] = $row;
        if ($row['ok']) {
            $valid++;
        } else {
            $drift++;
        }
        if ((($i + 1) % 20) === 0) {
            echo '  revalidated ' . ($i + 1) . "/204\n";
        }
    }
    $payload = [
        'generatedAt' => gmdate('c'),
        'sourceValid' => $valid,
        'sourceDrift' => $drift,
        'results' => $results,
    ];
    file_put_contents($outDir . '/source-revalidation.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");
    $executable = [];
    $byId = [];
    foreach ($results as $r) {
        $byId[(string) $r['legacyId']] = $r;
    }
    foreach ($frozen['candidates'] as $c) {
        $id = (string) $c['legacyId'];
        if (($byId[$id]['ok'] ?? false) === true) {
            $executable[] = $c;
        }
    }
    file_put_contents($outDir . '/executable-allow-list.json', json_encode([
        'generatedAt' => gmdate('c'),
        'count' => count($executable),
        'allowListHash' => $frozen['allowListHash'],
        'candidates' => $executable,
    ], JSON_PRETTY_PRINT) . "\n");

    return ['sourceValid' => $valid, 'sourceDrift' => $drift];
}

/**
 * @param list<string> $legacyIds
 * @return array<string, mixed>
 */
function runBatch(
    string $outDir,
    Phase4HTechnicalDocumentMigrator $migrator,
    SqlConnection $db,
    ProductBlobStore $blob,
    $settings,
    AzureSqlTechnicalDocumentLibraryRepository $library,
    $container,
    string $batchName,
    array $legacyIds,
): array {
    $execPath = $outDir . '/executable-allow-list.json';
    if (!is_file($execPath)) {
        throw new RuntimeException('executable-allow-list.json missing — run revalidate first');
    }
    $exec = json_decode((string) file_get_contents($execPath), true, 512, JSON_THROW_ON_ERROR);
    $byId = [];
    foreach ($exec['candidates'] as $c) {
        $byId[(string) $c['legacyId']] = $c;
    }
    $results = [];
    $manifest = json_decode((string) file_get_contents($outDir . '/rollback-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($legacyIds as $legacyId) {
        $legacyId = (string) $legacyId;
        if (!isset($byId[$legacyId])) {
            $results[] = ['status' => 'SKIPPED_NOT_EXECUTABLE', 'legacyId' => $legacyId];
            continue;
        }
        echo "  migrating {$legacyId}...\n";
        $row = $migrator->migrateOne($byId[$legacyId], false);
        $results[] = $row;
        if (($row['status'] ?? '') === 'CREATED') {
            $manifest['objects'][] = [
                'legacyId' => $row['legacyId'],
                'sourceSha' => $row['sha256'],
                'publicId' => $row['publicId'],
                'documentId' => $row['documentId'],
                'container' => $row['container'],
                'storagePath' => $row['storagePath'],
                'categoryLinkIds' => $row['categoryLinkIds'],
                'styleLinkIds' => $row['styleLinkIds'],
                'productLinkIds' => $row['productLinkIds'],
                'timestamp' => gmdate('c'),
                'batch' => $batchName,
            ];
        }
    }
    file_put_contents($outDir . '/rollback-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

    $summary = [
        'batch' => $batchName,
        'attempted' => count($legacyIds),
        'created' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'CREATED')),
        'reused' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'REUSE_EXISTING')),
        'failed' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'EXECUTION_FAILED')),
        'sourceDrift' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'SOURCE_DRIFT')),
        'skipped' => count(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'SKIPPED_NOT_EXECUTABLE')),
    ];
    $payload = ['generatedAt' => gmdate('c'), 'summary' => $summary, 'results' => $results];
    file_put_contents($outDir . '/' . $batchName . '-result.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");

    if ($batchName === 'canary') {
        $gate = validateCanary($outDir, $db, $blob, $settings, $library, $container, $results);
        file_put_contents($outDir . '/canary-gate.json', json_encode($gate, JSON_PRETTY_PRINT) . "\n");
        $payload['gate'] = $gate;
        file_put_contents($outDir . '/canary-result.json', json_encode($payload, JSON_PRETTY_PRINT) . "\n");
    }

    return $payload;
}

/**
 * @param list<array<string, mixed>> $results
 * @return array<string, mixed>
 */
function validateCanary(
    string $outDir,
    SqlConnection $db,
    ProductBlobStore $blob,
    $settings,
    AzureSqlTechnicalDocumentLibraryRepository $library,
    $container,
    array $results,
): array {
    $checks = [];
    $pass = true;
    $created = array_values(array_filter($results, static fn ($r) => ($r['status'] ?? '') === 'CREATED'));
    $checks['createdCount'] = count($created);
    if (count($created) < 1) {
        $pass = false;
        $checks['createdOk'] = false;
    } else {
        $checks['createdOk'] = true;
    }

    foreach ($created as $row) {
        $doc = $db->fetchAll(
            'SELECT Title, OriginalFileName, ChecksumSha256, ByteSize, IsActive, DeletedAtUtc, StoragePath, StorageContainer FROM technical.Documents WHERE PublicId=:p',
            [':p' => $row['publicId']],
        );
        $okDoc = $doc !== [] && (int) $doc[0]['IsActive'] === 1 && $doc[0]['DeletedAtUtc'] === null
            && strtolower((string) $doc[0]['ChecksumSha256']) === strtolower((string) $row['sha256']);
        $bytes = $blob->getObjectBinary($settings->blobContainerProducts, (string) $row['storagePath']);
        $okBlob = $bytes !== null && strlen($bytes) > 0 && hash('sha256', $bytes) === strtolower((string) $row['sha256']);
        $checks['documents'][] = [
            'legacyId' => $row['legacyId'],
            'publicId' => $row['publicId'],
            'dbOk' => $okDoc,
            'blobOk' => $okBlob,
        ];
        if (!$okDoc || !$okBlob) {
            $pass = false;
        }
    }

    /** @var ResolveTechnicalDocumentsQuery $query */
    $query = $container->get(ResolveTechnicalDocumentsQuery::class);
    $apiChecks = [];
    foreach (['industrial-certs', 'sbo-certs', 'installation-guides', 'accessory-warranty'] as $cat) {
        $payload = $query->execute($cat);
        $apiChecks[$cat] = [
            'itemSource' => $payload['itemSource'] ?? null,
            'itemCount' => isset($payload['items']) && is_array($payload['items']) ? count($payload['items']) : 0,
        ];
        if (($payload['itemSource'] ?? '') !== 'technical-document-library') {
            // installation/accessory/industrial/sbo should be DB-backed after canary
            if (in_array($cat, ['industrial-certs', 'sbo-certs', 'installation-guides', 'accessory-warranty'], true)) {
                $pass = false;
            }
        }
    }
    $checks['api'] = $apiChecks;

    $pd = (int) $db->fetchAll('SELECT COUNT(*) AS c FROM product.ProductDocuments')[0]['c'];
    $checks['productDocuments'] = $pd;
    if ($pd !== 11) {
        $pass = false;
    }

    $manifest = json_decode((string) file_get_contents($outDir . '/rollback-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $checks['rollbackObjects'] = count($manifest['objects'] ?? []);
    if ($checks['rollbackObjects'] !== count($created)) {
        $pass = false;
    }

    return [
        'status' => $pass ? 'PASS' : 'FAIL',
        'checks' => $checks,
        'generatedAt' => gmdate('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function reconcile(
    string $outDir,
    SqlConnection $db,
    ProductBlobStore $blob,
    $settings,
    $container,
    AzureSqlTechnicalDocumentLibraryRepository $library,
): array {
    $pre = json_decode((string) file_get_contents($outDir . '/pre-migration-snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
    $counts = $db->fetchAll(<<<'SQL'
SELECT
  (SELECT COUNT(*) FROM technical.Documents WHERE DeletedAtUtc IS NULL) AS documents,
  (SELECT COUNT(*) FROM technical.DocumentCategoryLinks WHERE DeletedAtUtc IS NULL) AS category_links,
  (SELECT COUNT(*) FROM technical.DocumentProductLinks WHERE DeletedAtUtc IS NULL) AS product_links,
  (SELECT COUNT(*) FROM technical.DocumentStyleLinks WHERE DeletedAtUtc IS NULL) AS style_links,
  (SELECT COUNT(*) FROM product.ProductDocuments) AS product_documents
SQL)[0];
    $manifest = json_decode((string) file_get_contents($outDir . '/rollback-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $shaOk = 0;
    $shaFail = 0;
    $zero = 0;
    $missing = 0;
    foreach ($manifest['objects'] as $obj) {
        $bytes = $blob->getObjectBinary($settings->blobContainerProducts, (string) $obj['storagePath']);
        if ($bytes === null) {
            $missing++;
            continue;
        }
        if (strlen($bytes) === 0) {
            $zero++;
            continue;
        }
        if (hash('sha256', $bytes) === strtolower((string) $obj['sourceSha'])) {
            $shaOk++;
        } else {
            $shaFail++;
        }
    }

    /** @var ResolveTechnicalDocumentsQuery $query */
    $query = $container->get(ResolveTechnicalDocumentsQuery::class);
    $families = [];
    foreach ([
        'warranty', 'bep-pvc-disposal', 'bep-pvc-certificate', 'industrial-certs',
        'sbo-certs', 'accessory-certs', 'accessory-warranty', 'installation-guides',
    ] as $cat) {
        $payload = $query->execute($cat);
        $source = $payload['itemSource'] ?? 'unknown';
        $count = isset($payload['items']) && is_array($payload['items']) ? count($payload['items']) : 0;
        $status = 'EMPTY';
        if ($source === 'technical-document-library' && $count > 0) {
            $status = 'DB_BACKED';
        } elseif ($source === 'catalog-json' && $count > 0) {
            $blocked = 0;
            foreach ($payload['items'] as $item) {
                if (($item['migrationStatus'] ?? '') === 'BLOCKED') {
                    $blocked++;
                }
            }
            $status = $blocked === $count ? 'BLOCKED' : 'CATALOG_FALLBACK';
        }
        $families[$cat] = ['status' => $status, 'itemSource' => $source, 'itemCount' => $count];
    }

    $out = [
        'generatedAt' => gmdate('c'),
        'counts' => [
            'documentsBefore' => (int) $pre['counts']['technicalDocuments'],
            'documentsAfter' => (int) $counts['documents'],
            'documentsDelta' => (int) $counts['documents'] - (int) $pre['counts']['technicalDocuments'],
            'categoryLinksBefore' => (int) $pre['counts']['categoryLinks'],
            'categoryLinksAfter' => (int) $counts['category_links'],
            'productLinksBefore' => (int) $pre['counts']['productLinks'],
            'productLinksAfter' => (int) $counts['product_links'],
            'styleLinksBefore' => (int) $pre['counts']['styleLinks'],
            'styleLinksAfter' => (int) $counts['style_links'],
            'productDocumentsBefore' => (int) $pre['counts']['productDocuments'],
            'productDocumentsAfter' => (int) $counts['product_documents'],
            'productDocumentsDelta' => (int) $counts['product_documents'] - (int) $pre['counts']['productDocuments'],
        ],
        'blobs' => [
            'manifestObjects' => count($manifest['objects']),
            'shaVerified' => $shaOk,
            'shaFailed' => $shaFail,
            'zeroByte' => $zero,
            'missing' => $missing,
        ],
        'families' => $families,
    ];
    file_put_contents($outDir . '/post-migration-reconciliation.json', json_encode($out, JSON_PRETTY_PRINT) . "\n");

    return $out;
}

/**
 * @return array<string, mixed>
 */
function idempotencyCheck(string $outDir, Phase4HTechnicalDocumentMigrator $migrator): array
{
    $exec = json_decode((string) file_get_contents($outDir . '/executable-allow-list.json'), true, 512, JSON_THROW_ON_ERROR);
    $createWould = 0;
    $reuse = 0;
    $other = 0;
    foreach ($exec['candidates'] as $c) {
        $row = $migrator->migrateOne($c, true);
        if (($row['status'] ?? '') === 'REUSE_EXISTING' || ($row['status'] ?? '') === 'DRY_RUN_OK' && false) {
            // dry run on existing returns REUSE; on missing returns DRY_RUN_OK
        }
        if (($row['status'] ?? '') === 'REUSE_EXISTING') {
            $reuse++;
        } elseif (($row['status'] ?? '') === 'DRY_RUN_OK') {
            $createWould++;
        } else {
            $other++;
        }
    }
    $out = [
        'generatedAt' => gmdate('c'),
        'wouldCreate' => $createWould,
        'reuseExisting' => $reuse,
        'other' => $other,
        'idempotency' => $createWould === 0 ? 'PASS' : 'FAIL',
    ];
    file_put_contents($outDir . '/idempotency-check.json', json_encode($out, JSON_PRETTY_PRINT) . "\n");

    return $out;
}
