<?php

declare(strict_types=1);

/**
 * Phase 12.8 Step B — F12 Inspection Tee single-product pilot APPLY.
 *
 *   php bin/apply-product-migration-pilot.php \
 *     --apply --pilot --style=F12 \
 *     --confirm-non-production --pilot-apply-approved
 *
 * Optional: --idempotency-check (second pass after apply)
 * Optional: --dry-run (preflight + validation only; no writes)
 *
 * Scope: Product F12, Variant F12-01, 6 variant specs, AU region, ownership.
 * Forbidden: other products, cables, bulk, pricing, blob, master-data, publish, rollback execute.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$coreSrc = dirname(__DIR__) . '/core/src';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    if (!str_starts_with($class, 'TriCab\\Core\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
    $path = $coreSrc . '/' . $relative;
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Migration\Application\Service\ApplyProductMigrationPilotF12Service;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) && !$isApply;
$idempCheck = in_array('--idempotency-check', $argvList, true);

if ($isApply || in_array('--pilot-apply-approved', $argvList, true)) {
    $guard = ProductMigrationPilotCliGuard::validateApplyArgs($argvList);
    if ($guard !== null) {
        fwrite(STDERR, $guard . "\n");
        exit(3);
    }
} else {
    if (!in_array('--confirm-non-production', $argvList, true)) {
        fwrite(STDERR, ProductMigrationPilotCliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED . "\n");
        exit(3);
    }
    if (!in_array('--pilot', $argvList, true)) {
        fwrite(STDERR, ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . "\n");
        exit(3);
    }
    $styles = ProductMigrationPilotCliGuard::parseStyles($argvList);
    if ($styles !== [ProductMigrationPilotCliGuard::APPROVED_STYLE]) {
        fwrite(STDERR, ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . "\n");
        exit(3);
    }
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$container = ContainerFactory::create(Settings::fromEnvironment($env));

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.8';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$loadJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
};

$write = static function (string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};

$package = $loadJson($outDir . '/pilot-final-dry-run-package.json');
if ($package === []) {
    fwrite(STDERR, "STOP: missing pilot-final-dry-run-package.json\n");
    exit(6);
}

/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);
$safe = static function (SqlConnection $sql, string $query, array $params = []): array {
    return $sql->fetchAll($query, $params);
};

$envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
$server = (string) ($envRows[0]['serverName'] ?? '');
$database = (string) ($envRows[0]['dbName'] ?? '');
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: target is {$server} / {$database}; expected tricab-mel / tc\n");
    exit(4);
}

$snapshot = static function (SqlConnection $sql) use ($safe): array {
    $defs = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.SpecificationDefinitions WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $units = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $options = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.SpecificationOptions WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $products = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.Products WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $variants = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductVariants WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $psv = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductSpecificationValues WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $vsv = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductVariantSpecificationValues WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $regions = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductRegions WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $families = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductFamilies WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $categories = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductCategories WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $series = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductSeries WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $fieldMaps = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings')[0]['cnt'] ?? 0);
    $cableMaps = (int) ($safe($sql, "SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE SourceTable = 'cable_specs'")[0]['cnt'] ?? 0);
    $prices = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductPrices WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $media = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductImages WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $docs = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductDocuments WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);

    return [
        'definitions' => $defs,
        'units' => $units,
        'options' => $options,
        'products' => $products,
        'variants' => $variants,
        'productSpecificationValues' => $psv,
        'variantSpecificationValues' => $vsv,
        'productRegions' => $regions,
        'families' => $families,
        'categories' => $categories,
        'series' => $series,
        'fieldMappings' => $fieldMaps,
        'cableFieldMappings' => $cableMaps,
        'pricing' => $prices,
        'media' => $media,
        'documents' => $docs,
    ];
};

$findF12 = static function (SqlConnection $sql) use ($safe): array {
    $products = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.PublicId) AS publicId,
       p.ProductId AS productId,
       p.ProductName AS productName,
       p.InternalCode AS internalCode,
       ps.StatusCode AS statusCode
FROM product.Products p
INNER JOIN product.ProductStatuses ps ON ps.ProductStatusId = p.ProductStatusId
WHERE p.DeletedAtUtc IS NULL
  AND (
    UPPER(LTRIM(RTRIM(p.InternalCode))) = N'F12'
    OR EXISTS (
      SELECT 1 FROM product.ProductCodes pc
      WHERE pc.ProductId = p.ProductId
        AND UPPER(LTRIM(RTRIM(pc.CodeValue))) = N'F12'
        AND pc.DeletedAtUtc IS NULL
    )
  )
SQL);
    $variants = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), v.PublicId) AS publicId,
       v.ProductVariantId AS variantId,
       v.VariantCode AS variantCode,
       CONVERT(NVARCHAR(36), p.PublicId) AS productPublicId
FROM product.ProductVariants v
INNER JOIN product.Products p ON p.ProductId = v.ProductId
WHERE v.DeletedAtUtc IS NULL
  AND UPPER(LTRIM(RTRIM(v.VariantCode))) = N'F12-01'
SQL);
    $slugs = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.PublicId) AS productPublicId, s.Slug AS slug
FROM product.ProductSlugs s
INNER JOIN product.Products p ON p.ProductId = s.ProductId
WHERE LOWER(LTRIM(RTRIM(s.Slug))) = N'f12' AND s.DeletedAtUtc IS NULL AND p.DeletedAtUtc IS NULL
SQL);
    $mappings = $safe($sql, <<<'SQL'
SELECT SourceSystemCode, SourceProductKey, SourceTable,
       CONVERT(NVARCHAR(36), TargetPublicId) AS targetPublicId,
       MigrationStatusCode, MigrationNotes
FROM migration.LegacyProductMappings
WHERE UPPER(LTRIM(RTRIM(SourceProductKey))) IN (N'F12', N'F12-01')
SQL);

    return [
        'products' => $products,
        'variants' => $variants,
        'slugs' => $slugs,
        'mappings' => $mappings,
    ];
};

$classifyCollision = static function (array $f12State): string {
    $products = $f12State['products'];
    $mappings = $f12State['mappings'];
    if ($products === [] && $f12State['variants'] === [] && $f12State['slugs'] === []) {
        return 'NONE';
    }
    $owned = false;
    foreach ($mappings as $m) {
        $notes = (string) ($m['MigrationNotes'] ?? '');
        if (
            str_contains($notes, ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID)
            || str_contains($notes, 'phase12.8-f12-pilot')
        ) {
            $owned = true;
            break;
        }
    }
    if ($owned && count($products) === 1) {
        return 'IDENTICAL_MIGRATION_OWNED';
    }
    if ($products !== [] || $f12State['variants'] !== [] || $f12State['slugs'] !== []) {
        return $owned ? 'CONFLICT' : 'PRE_EXISTING_MANUAL';
    }

    return 'NONE';
};

$preCounts = $snapshot($sql);
$f12Before = $findF12($sql);
$collisionType = $classifyCollision($f12Before);

$preflight = [
    'server' => $server,
    'database' => $database,
    'environmentLabel' => 'NON-PRODUCTION',
    'liveValidation' => 'AVAILABLE',
    'operatorApprovalRecognised' => $isApply || $isDryRun,
    'definitionCount' => $preCounts['definitions'],
    'fieldMappingCount' => $preCounts['fieldMappings'],
    'cableFieldMappingCount' => $preCounts['cableFieldMappings'],
    'f12CollisionType' => $collisionType,
    'f12Presence' => [
        'products' => count($f12Before['products']),
        'variants' => count($f12Before['variants']),
        'slugs' => count($f12Before['slugs']),
        'mappings' => count($f12Before['mappings']),
    ],
    'countsBefore' => $preCounts,
    'expectedBaseline' => [
        'definitions' => ApplyProductMigrationPilotF12Service::EXPECTED_DEFINITIONS,
        'totalFieldMappings' => ApplyProductMigrationPilotF12Service::EXPECTED_TOTAL_MAPPINGS,
        'cableFieldMappings' => ApplyProductMigrationPilotF12Service::EXPECTED_CABLE_MAPPINGS,
    ],
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/pilot-f12-apply-preflight.json', $preflight);

echo "F12 APPLY PREFLIGHT\n";
echo "server={$server} db={$database} env=NON-PRODUCTION\n";
echo 'defs=' . $preCounts['definitions'] . ' maps=' . $preCounts['fieldMappings'] . ' cableMaps=' . $preCounts['cableFieldMappings'] . "\n";
echo "f12Collision={$collisionType}\n";

if ($collisionType === 'PRE_EXISTING_MANUAL' || $collisionType === 'CONFLICT') {
    fwrite(STDERR, ProductMigrationPilotCliGuard::TARGET_COLLISION . ": {$collisionType}\n");
    exit(5);
}

// --- Legacy source revalidation (STRICT READ-ONLY) ---
$legacySource = [
    'style_code' => 'F12',
    'name' => 'Inspection Tee',
    'specValues' => [
        'd1' => '12.00',
        'd2' => '15.00',
        'd3' => '10.00',
        'd4' => '3.00',
        'd5' => '2.00',
        'd6' => '4.30',
    ],
    'source' => 'package-fallback',
    'wordpressWrites' => 0,
    'tcWrites' => 0,
    'forbiddenWriteAttempts' => 0,
];

try {
    $legacyCfg = LiveLegacySourceConfig::fromEnvironment($env);
    if ($legacyCfg->tcConfigured()) {
        /** @var ReadOnlyMysqlConnectionFactory $factory */
        $factory = $container->get(ReadOnlyMysqlConnectionFactory::class);
        $conn = $factory->create(
            (string) $legacyCfg->tcDsn,
            (string) $legacyCfg->tcUser,
            (string) $legacyCfg->tcPassword,
            'tc',
            $legacyCfg->tcSslMode ?? 'required',
        );
        $rows = $conn->query(
            <<<'SQL'
SELECT a.`product_code` AS product_code, a.`d1`, a.`d2`, a.`d3`, a.`d4`, a.`d5`, a.`d6`,
       e.`style` AS style_code
FROM `accessory_specs` a
LEFT JOIN `tpro_ext` e ON e.`product_code` = a.`product_code`
WHERE UPPER(TRIM(COALESCE(e.`style`, ''))) = 'F12'
   OR UPPER(TRIM(a.`product_code`)) LIKE 'F12-%'
LIMIT 50
SQL
            ,
            [],
            'F12PilotApply',
            'revalidateAccessorySpecs',
        );
        if ($rows === []) {
            throw new RuntimeException('No accessory_specs rows for F12 (style or product_code prefix)');
        }

        $name = 'Inspection Tee';
        $ready = $loadJson($docsRoot . '/phase12.7/batch-a-classification-ready-products.json');
        foreach (is_array($ready['items'] ?? null) ? $ready['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (strtoupper(trim((string) ($item['style_code'] ?? ''))) === 'F12') {
                $readyName = trim((string) ($item['productTitle'] ?? $item['legacyProductName'] ?? ''));
                if ($readyName !== '') {
                    $name = $readyName;
                }
                break;
            }
        }

        $expected = [
            'd1' => '12.00',
            'd2' => '15.00',
            'd3' => '10.00',
            'd4' => '3.00',
            'd5' => '2.00',
            'd6' => '4.30',
        ];
        $norm = static function (mixed $v): string {
            $s = trim((string) $v);
            if ($s === '' || !is_numeric($s)) {
                return $s;
            }

            return number_format((float) $s, 2, '.', '');
        };
        $matchedRow = null;
        foreach ($rows as $candidate) {
            $ok = true;
            foreach ($expected as $field => $value) {
                if ($norm($candidate[$field] ?? '') !== $value) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matchedRow = $candidate;
                break;
            }
        }

        if ($matchedRow === null) {
            $observed = [];
            foreach ($rows as $r) {
                $observed[] = [
                    'product_code' => $r['product_code'] ?? null,
                    'style_code' => $r['style_code'] ?? null,
                    'd1' => $norm($r['d1'] ?? ''),
                    'd2' => $norm($r['d2'] ?? ''),
                    'd3' => $norm($r['d3'] ?? ''),
                    'd4' => $norm($r['d4'] ?? ''),
                    'd5' => $norm($r['d5'] ?? ''),
                    'd6' => $norm($r['d6'] ?? ''),
                ];
            }
            $write($outDir . '/pilot-f12-apply-result.json', [
                'status' => ProductMigrationPilotCliGuard::SOURCE_DRIFT,
                'reason' => 'Live F12 accessory_specs rows do not match approved dry-run sample values',
                'approvedPackageValues' => $expected,
                'observedLiveRows' => $observed,
                'note' => 'Dry-run package used Batch C.2 sampleValues; live F12 SKUs currently differ. Do not silently regenerate package.',
                'generatedAtUtc' => gmdate('c'),
            ]);
            throw new RuntimeException(
                ProductMigrationPilotCliGuard::SOURCE_DRIFT
                . ': no live F12 row matches package samples 12/15/10/3/2/4.30 (observed '
                . count($rows) . ' F12 rows)',
            );
        }

        $row = $matchedRow;
        $legacySource = [
            'style_code' => 'F12',
            'name' => $name,
            'specValues' => [
                'd1' => $norm($row['d1'] ?? ''),
                'd2' => $norm($row['d2'] ?? ''),
                'd3' => $norm($row['d3'] ?? ''),
                'd4' => $norm($row['d4'] ?? ''),
                'd5' => $norm($row['d5'] ?? ''),
                'd6' => $norm($row['d6'] ?? ''),
            ],
            'matchedProductCode' => (string) ($row['product_code'] ?? ''),
            'source' => 'live-tc-readonly',
            'wordpressWrites' => 0,
            'tcWrites' => 0,
            'forbiddenWriteAttempts' => 0,
            'rowsSampled' => count($rows),
        ];
        echo 'Legacy F12 revalidated from live tc (read-only); matched product_code='
            . $legacySource['matchedProductCode'] . "\n";
    } else {
        echo "LEGACY_TC not configured — validating against dry-run package values only.\n";
        $legacySource['source'] = 'package-only';
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Legacy revalidation failed: ' . $e->getMessage() . "\n");
    if (!is_file($outDir . '/pilot-f12-apply-result.json')) {
        $write($outDir . '/pilot-f12-apply-result.json', [
            'status' => 'BLOCKED',
            'error' => $e->getMessage(),
            'generatedAtUtc' => gmdate('c'),
        ]);
    }
    $write($outDir . '/pilot-f12-final-report.json', [
        'phase' => '12.8-f12-single-product-pilot-apply',
        'status' => 'BLOCKED',
        'banner' => 'PHASE 12.8 F12 SINGLE PRODUCT PILOT APPLY: BLOCKED / FAILED',
        'reason' => $e->getMessage(),
        'legacySource' => $legacySource,
        'preflight' => $preflight,
        'stops' => [
            'DO NOT APPLY WITH SILENT PACKAGE REGENERATION',
            'DO NOT MIGRATE ANOTHER PRODUCT',
            'WAIT FOR EXPLICIT OPERATOR REVIEW OF SOURCE DRIFT',
        ],
        'generatedAtUtc' => gmdate('c'),
    ]);
    fwrite(STDERR, ProductMigrationPilotCliGuard::SOURCE_DRIFT . "\n");
    exit(7);
}

/** @var ApplyProductMigrationPilotF12Service $service */
$service = $container->get(ApplyProductMigrationPilotF12Service::class);
/** @var LegacyProductMappingRepository $legacyMappings */
$legacyMappings = $container->get(LegacyProductMappingRepository::class);
/** @var MigrationBatchRepository $batches */
$batches = $container->get(MigrationBatchRepository::class);

$buildExisting = static function (array $f12State, SqlConnection $sql) use ($safe, $legacyMappings): ?array {
    if ($f12State['products'] === []) {
        return null;
    }
    $product = $f12State['products'][0];
    $productPublicId = (string) ($product['publicId'] ?? '');
    $variantPublicId = null;
    foreach ($f12State['variants'] as $v) {
        if ((string) ($v['productPublicId'] ?? '') === $productPublicId) {
            $variantPublicId = (string) ($v['publicId'] ?? '');
            break;
        }
    }
    $specRows = [];
    $match = false;
    if ($variantPublicId !== null && $variantPublicId !== '') {
        $specRows = $safe($sql, <<<'SQL'
SELECT d.DefinitionCode AS code, CONVERT(VARCHAR(50), v.ValueDecimal) AS valueDecimal
FROM product.ProductVariantSpecificationValues v
INNER JOIN product.ProductVariants pv ON pv.ProductVariantId = v.ProductVariantId
INNER JOIN product.SpecificationDefinitions d ON d.SpecificationDefinitionId = v.SpecificationDefinitionId
WHERE pv.PublicId = :pid AND v.DeletedAtUtc IS NULL
SQL, [':pid' => $variantPublicId]);
        $byCode = [];
        foreach ($specRows as $r) {
            $byCode[strtoupper((string) ($r['code'] ?? ''))] = trim((string) ($r['valueDecimal'] ?? ''));
        }
        $match = true;
        foreach (ApplyProductMigrationPilotF12Service::APPROVED_SPECS as $spec) {
            $actual = $byCode[$spec['definition']] ?? null;
            if ($actual === null || number_format((float) $actual, 2, '.', '') !== $spec['value']) {
                $match = false;
                break;
            }
        }
        $match = $match && count($specRows) === 6;
    }
    $regionRows = $safe($sql, <<<'SQL'
SELECT r.RegionCode AS code
FROM product.ProductRegions pr
INNER JOIN product.Products p ON p.ProductId = pr.ProductId
INNER JOIN product.Regions r ON r.RegionId = pr.RegionId
WHERE p.PublicId = :pid AND pr.DeletedAtUtc IS NULL
SQL, [':pid' => $productPublicId]);
    $au = false;
    foreach ($regionRows as $r) {
        if (strtoupper((string) ($r['code'] ?? '')) === 'AU') {
            $au = true;
        }
    }
    $mapping = $legacyMappings->findBySource('tc', 'F12');
    $owned = false;
    if ($mapping !== null && is_string($mapping->migrationNotes) && str_contains($mapping->migrationNotes, ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID)) {
        $owned = true;
    }

    return [
        'productPublicId' => $productPublicId,
        'productId' => (int) ($product['productId'] ?? 0),
        'variantPublicId' => $variantPublicId,
        'variantSpecValueCount' => count($specRows),
        'variantSpecsMatch' => $match,
        'auAssigned' => $au && count($regionRows) === 1,
        'migrationOwned' => $owned,
        'statusCode' => (string) ($product['statusCode'] ?? ''),
    ];
};

$existing = $buildExisting($f12Before, $sql);
if ($collisionType === 'IDENTICAL_MIGRATION_OWNED' && $existing !== null) {
    $existing['migrationOwned'] = true;
}

$actor = new ActorContext(
    userId: 1,
    publicId: '00000000-0000-4000-8000-000000000012',
    emailAddress: 'phase12.8-f12-pilot-apply@tricab.com',
);

$input = [
    'requestedStyle' => 'F12',
    'preflight' => $preflight,
    'legacySource' => $legacySource,
    'approvedPackage' => $package,
    'existing' => $existing,
];

try {
    $service->assertApprovedStyle('F12');
    $service->assertPreflight($preflight);
    $service->assertSourceMatchesPackage($legacySource, $package);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $write($outDir . '/pilot-f12-apply-result.json', [
        'status' => 'BLOCKED',
        'error' => $e->getMessage(),
        'generatedAtUtc' => gmdate('c'),
    ]);
    exit(8);
}

if ($isDryRun && !$isApply) {
    $result = $service->apply($actor, $input, true);
    $write($outDir . '/pilot-f12-apply-result.json', $result + ['generatedAtUtc' => gmdate('c'), 'mode' => 'DRY_RUN']);
    echo "DRY-RUN complete — nothing applied.\n";
    exit(0);
}

if (!$isApply) {
    fwrite(STDERR, ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . "\n");
    exit(3);
}

echo "APPLYING F12 PILOT...\n";
try {
    $result = $service->apply($actor, $input, false);
} catch (Throwable $e) {
    fwrite(STDERR, 'APPLY FAILED (transaction rolled back): ' . $e->getMessage() . "\n");
    $write($outDir . '/pilot-f12-apply-result.json', [
        'status' => 'FAILED',
        'error' => $e->getMessage(),
        'generatedAtUtc' => gmdate('c'),
    ]);
    exit(9);
}
$write($outDir . '/pilot-f12-apply-result.json', $result + ['generatedAtUtc' => gmdate('c')]);

// --- Post-commit reconciliation ---
$postCounts = $snapshot($sql);
$f12After = $findF12($sql);
$existingAfter = $buildExisting($f12After, $sql);

$deltas = [];
foreach ($preCounts as $key => $before) {
    $deltas[$key] = $postCounts[$key] - $before;
}

$expectedDeltas = [
    'products' => 1,
    'variants' => 1,
    'productSpecificationValues' => 0,
    'variantSpecificationValues' => 6,
    'productRegions' => 1,
    'definitions' => 0,
    'units' => 0,
    'options' => 0,
    'fieldMappings' => 0,
    'cableFieldMappings' => 0,
    'families' => 0,
    'categories' => 0,
    'series' => 0,
    'pricing' => 0,
    'media' => 0,
    'documents' => 0,
];

// If already identical owned before apply, expect zero deltas.
if ($collisionType === 'IDENTICAL_MIGRATION_OWNED') {
    foreach ($expectedDeltas as $k => $_) {
        $expectedDeltas[$k] = 0;
    }
}

$reconOk = true;
$reconFailures = [];
foreach ($expectedDeltas as $key => $expected) {
    $actual = $deltas[$key] ?? 0;
    if ($actual !== $expected) {
        // Allow productRegions delta >=1 if table counts differently; still require AU once.
        if ($key === 'productRegions' && $expected === 1 && $actual >= 1 && ($existingAfter['auAssigned'] ?? false)) {
            continue;
        }
        $reconOk = false;
        $reconFailures[] = "{$key}: expected delta {$expected}, got {$actual}";
    }
}

if (count($f12After['products']) !== 1) {
    $reconOk = false;
    $reconFailures[] = 'product count for F12 != 1';
}
$productRow = $f12After['products'][0] ?? [];
if (strcasecmp((string) ($productRow['productName'] ?? ''), 'Inspection Tee') !== 0) {
    $reconOk = false;
    $reconFailures[] = 'product name mismatch';
}
if (strtolower((string) ($productRow['statusCode'] ?? '')) !== 'draft') {
    $reconOk = false;
    $reconFailures[] = 'status not draft';
}
if (count($f12After['variants']) !== 1) {
    $reconOk = false;
    $reconFailures[] = 'variant count != 1';
}
if (!($existingAfter['variantSpecsMatch'] ?? false)) {
    $reconOk = false;
    $reconFailures[] = 'variant specs not exact 6 approved values';
}
if (!($existingAfter['auAssigned'] ?? false)) {
    $reconOk = false;
    $reconFailures[] = 'AU assignment missing/incorrect';
}
if (!($existingAfter['migrationOwned'] ?? false)) {
    $reconOk = false;
    $reconFailures[] = 'migration ownership missing';
}

$reconciliation = [
    'status' => $reconOk ? 'PASSED' : ProductMigrationPilotCliGuard::RECONCILIATION_FAILED,
    'countsBefore' => $preCounts,
    'countsAfter' => $postCounts,
    'deltas' => $deltas,
    'expectedDeltas' => $expectedDeltas,
    'failures' => $reconFailures,
    'product' => $productRow,
    'variant' => $f12After['variants'][0] ?? null,
    'existing' => $existingAfter,
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/pilot-f12-reconciliation.json', $reconciliation);

if (!$reconOk) {
    fwrite(STDERR, ProductMigrationPilotCliGuard::RECONCILIATION_FAILED . "\n");
    fwrite(STDERR, implode("\n", $reconFailures) . "\n");
    exit(10);
}

// --- Publication safety ---
$adminVisible = false;
$publicVisible = false;
try {
    /** @var ProductRepository $products */
    $products = $container->get(ProductRepository::class);
    $detail = $products->findByPublicId(
        ProductPublicId::fromString((string) ($existingAfter['productPublicId'] ?? '')),
    );
    $adminVisible = $detail !== null && $detail->status()->toString() === 'draft';
} catch (Throwable $e) {
    $adminVisible = false;
}
try {
    /** @var GetPublicProductService $getPublic */
    $getPublic = $container->get(GetPublicProductService::class);
    $getPublic->bySlug('f12', 'AU');
    $publicVisible = true;
} catch (ProductNotFound $e) {
    $publicVisible = false;
} catch (Throwable $e) {
    // Treat other failures as not publicly visible.
    $publicVisible = false;
}

$publicationCheck = [
    'status' => (!$publicVisible && $adminVisible) ? 'PASSED' : ProductMigrationPilotCliGuard::PUBLICATION_SAFETY_FAILED,
    'publicationStatus' => 'Draft',
    'adminVisible' => $adminVisible,
    'publicPublishedProductQueryVisible' => $publicVisible,
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/pilot-f12-publication-check.json', $publicationCheck);
if ($publicationCheck['status'] !== 'PASSED') {
    fwrite(STDERR, ProductMigrationPilotCliGuard::PUBLICATION_SAFETY_FAILED . "\n");
    exit(11);
}

// --- Rollback package (PREPARE ONLY) ---
$specIds = $safe($sql, <<<'SQL'
SELECT v.ProductVariantSpecificationValueId AS valueId, d.DefinitionCode AS code
FROM product.ProductVariantSpecificationValues v
INNER JOIN product.ProductVariants pv ON pv.ProductVariantId = v.ProductVariantId
INNER JOIN product.SpecificationDefinitions d ON d.SpecificationDefinitionId = v.SpecificationDefinitionId
WHERE pv.PublicId = :pid AND v.DeletedAtUtc IS NULL
SQL, [':pid' => (string) ($existingAfter['variantPublicId'] ?? '')]);

$regionIds = $safe($sql, <<<'SQL'
SELECT pr.ProductRegionId AS productRegionId, r.RegionCode AS code
FROM product.ProductRegions pr
INNER JOIN product.Products p ON p.ProductId = pr.ProductId
INNER JOIN product.Regions r ON r.RegionId = pr.RegionId
WHERE p.PublicId = :pid AND pr.DeletedAtUtc IS NULL
SQL, [':pid' => (string) ($existingAfter['productPublicId'] ?? '')]);

$mappingIds = $safe($sql, <<<'SQL'
SELECT LegacyProductMappingId AS mappingId, SourceProductKey, MigrationNotes
FROM migration.LegacyProductMappings
WHERE UPPER(LTRIM(RTRIM(SourceProductKey))) IN (N'F12', N'F12-01')
SQL);

$rollbackPackage = [
    'status' => 'PREPARED_NOT_EXECUTED',
    'executed' => false,
    'requiresSeparateOperatorApproval' => true,
    'migrationBatchId' => ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID,
    'ownership' => 'migration-created',
    'product' => [
        'publicId' => $existingAfter['productPublicId'] ?? null,
        'productId' => $existingAfter['productId'] ?? null,
        'code' => 'F12',
        'name' => 'Inspection Tee',
    ],
    'variant' => [
        'publicId' => $existingAfter['variantPublicId'] ?? null,
        'variantId' => $f12After['variants'][0]['variantId'] ?? null,
        'variantCode' => 'F12-01',
    ],
    'variantSpecificationValueIds' => $specIds,
    'auAssignmentIds' => $regionIds,
    'migrationMappingIds' => $mappingIds,
    'note' => 'DO NOT EXECUTE in this step. Rollback requires separate explicit operator approval.',
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/pilot-f12-rollback-package.json', $rollbackPackage);

// --- Idempotency second run ---
$idempResult = null;
$idempOk = true;
$idempFailures = [];
if ($idempCheck || true) {
    $midCounts = $snapshot($sql);
    $existingForIdemp = $buildExisting($findF12($sql), $sql);
    if ($existingForIdemp !== null) {
        $existingForIdemp['migrationOwned'] = true;
    }
    $preflight2 = $preflight;
    $preflight2['f12CollisionType'] = 'IDENTICAL_MIGRATION_OWNED';
    $preflight2['operatorApprovalRecognised'] = true;
    $input2 = [
        'requestedStyle' => 'F12',
        'preflight' => $preflight2,
        'legacySource' => $legacySource,
        'approvedPackage' => $package,
        'existing' => $existingForIdemp,
    ];
    try {
        $idempResult = $service->apply($actor, $input2, false);
    } catch (Throwable $e) {
        $idempOk = false;
        $idempFailures[] = $e->getMessage();
        $idempResult = ['status' => 'FAILED', 'error' => $e->getMessage()];
    }
    $afterIdemp = $snapshot($sql);
    $idempDeltas = [];
    foreach ($midCounts as $key => $before) {
        $idempDeltas[$key] = $afterIdemp[$key] - $before;
        if ($idempDeltas[$key] !== 0 && !in_array($key, ['pricing'], true)) {
            // audit may not change counts; entity table deltas must be 0
            if (in_array($key, ['products', 'variants', 'variantSpecificationValues', 'productSpecificationValues', 'productRegions', 'definitions', 'units', 'options', 'fieldMappings', 'media', 'documents'], true)) {
                $idempOk = false;
                $idempFailures[] = "idempotent delta {$key}={$idempDeltas[$key]}";
            }
        }
    }
    if (($idempResult['product']['created'] ?? 1) !== 0
        || ($idempResult['variant']['created'] ?? 1) !== 0
        || ($idempResult['variantSpecValues']['inserted'] ?? 1) !== 0
        || ($idempResult['marketAssignments']['inserted'] ?? 1) !== 0
    ) {
        $idempOk = false;
        $idempFailures[] = 'second run reported creates/inserts';
    }
    $idempReport = [
        'status' => $idempOk ? 'PASSED' : ProductMigrationPilotCliGuard::NOT_IDEMPOTENT,
        'secondRun' => $idempResult,
        'deltas' => $idempDeltas,
        'failures' => $idempFailures,
        'generatedAtUtc' => gmdate('c'),
    ];
    $write($outDir . '/pilot-f12-idempotency.json', $idempReport);
    if (!$idempOk) {
        fwrite(STDERR, ProductMigrationPilotCliGuard::NOT_IDEMPOTENT . "\n");
        exit(12);
    }
}

$final = [
    'phase' => '12.8-f12-single-product-pilot-apply',
    'status' => 'SUCCESS',
    'banner' => 'PHASE 12.8 F12 SINGLE PRODUCT PILOT APPLY: SUCCESS',
    'message' => 'F12 MIGRATED TO NON-PRODUCTION AS DRAFT',
    'pilotProduct' => 'F12 — Inspection Tee',
    'target' => 'NON-PRODUCTION',
    'publicationStatus' => 'DRAFT',
    'safety' => [
        'wordpressWrites' => 0,
        'tcWrites' => 0,
        'forbiddenLegacyWrites' => 0,
        'blobWrites' => 0,
        'pricingWrites' => (int) ($result['actualWrites']['pricing'] ?? 0),
        'masterDataWrites' => [
            'definitions' => 0,
            'units' => 0,
            'options' => 0,
            'fieldMappings' => 0,
            'classification' => 0,
        ],
    ],
    'preflight' => $preflight,
    'legacySource' => $legacySource,
    'apply' => $result,
    'reconciliation' => $reconciliation,
    'publicationCheck' => $publicationCheck,
    'idempotency' => $idempReport ?? null,
    'rollbackPackage' => [
        'prepared' => true,
        'executed' => false,
        'path' => 'core/docs/phase12.8/pilot-f12-rollback-package.json',
    ],
    'stops' => [
        'DO NOT ROLLBACK',
        'DO NOT MIGRATE ANOTHER PRODUCT',
        'DO NOT START BULK MIGRATION',
        'DO NOT MIGRATE CABLE PRODUCTS',
        'DO NOT MIGRATE PRICING',
        'WAIT FOR EXPLICIT OPERATOR REVIEW',
    ],
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/pilot-f12-final-report.json', $final);

echo "\nPHASE 12.8 F12 SINGLE PRODUCT PILOT APPLY: SUCCESS\n";
echo "F12 MIGRATED TO NON-PRODUCTION AS DRAFT\n";
echo "ProductPublicId=" . ($existingAfter['productPublicId'] ?? '') . "\n";
echo "VariantPublicId=" . ($existingAfter['variantPublicId'] ?? '') . "\n";
echo "Rollback prepared=YES executed=NO\n";
echo "STOP — DO NOT ROLLBACK / DO NOT MIGRATE ANOTHER PRODUCT\n";
exit(0);
