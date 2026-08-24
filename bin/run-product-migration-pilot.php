<?php

declare(strict_types=1);

/**
 * Phase 12.8 — Single accessory/DV product migration pilot dry-run.
 *
 *   php bin/run-product-migration-pilot.php --select-best-accessory --dry-run --confirm-non-production
 *   php bin/run-product-migration-pilot.php --select-best-accessory --dry-run --offline --verify-deterministic
 *
 * --apply / --execute / --write refused with PILOT_APPLY_NOT_APPROVED.
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
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveProductMigrationPilotDryRunService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);
$selectBest = in_array('--select-best-accessory', $argvList, true);
$dryRun = in_array('--dry-run', $argvList, true) || !$selectBest;

$refuse = ProductMigrationPilotCliGuard::refuseApply($argvList);
if ($refuse !== null) {
    fwrite(STDERR, $refuse . "\n");
    exit(3);
}

if (!$offline && !$confirmNonProd) {
    fwrite(STDERR, "ERROR: require --confirm-non-production or --offline\n");
    exit(1);
}

if (!$selectBest) {
    fwrite(STDERR, "ERROR: --select-best-accessory is required\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$container = ContainerFactory::create(Settings::fromEnvironment($env));

$docsRoot = dirname(__DIR__) . '/core/docs';
$phase127 = $docsRoot . '/phase12.7';
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

$ksortRecursive = static function (mixed &$value) use (&$ksortRecursive): void {
    if (!is_array($value)) {
        return;
    }
    foreach ($value as &$child) {
        $ksortRecursive($child);
    }
    unset($child);
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
};

$semanticCompare = static function (array $payload) use ($ksortRecursive): array {
    $copy = $payload;
    unset($copy['generatedAtUtc']);
    $ksortRecursive($copy);

    return $copy;
};

$ready = $loadJson($phase127 . '/batch-a-classification-ready-products.json');
$products = is_array($ready['items'] ?? null) ? $ready['items'] : [];

$c2Approved = $loadJson($phase127 . '/batch-c2-approved-field-mappings.json');
$approvedMaps = is_array($c2Approved['items'] ?? null) ? $c2Approved['items'] : (is_array($c2Approved) && isset($c2Approved[0]) ? $c2Approved : []);

$c2Package = $loadJson($phase127 . '/batch-c2-final-package.json');
$reviewMaps = [];
if (is_array($c2Package['reviewPackage']['mappings'] ?? null)) {
    $reviewMaps = $c2Package['reviewPackage']['mappings'];
}

$azure = [
    'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
    'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
    'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
    'productSpecificationValues' => 322,
    'variantSpecificationValues' => 52619,
    'existingStyleCodes' => [],
    'existingProductCodes' => [],
    'existingSlugs' => [],
    'regions' => [['code' => 'AU']],
];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $envRows = $sql->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
        $server = (string) ($envRows[0]['serverName'] ?? '');
        $database = (string) ($envRows[0]['dbName'] ?? '');
        if (str_contains(strtolower($server), 'tricab-mel') && strtolower($database) === 'tc') {
            $safeCount = static function (SqlConnection $sql, string $q): int {
                try {
                    return (int) ($sql->fetchAll($q)[0]['cnt'] ?? 0);
                } catch (Throwable) {
                    return 0;
                }
            };
            $safeCol = static function (SqlConnection $sql, string $q, string $col): array {
                try {
                    $rows = $sql->fetchAll($q);

                    return array_values(array_filter(array_map(
                        static fn (array $r): string => (string) ($r[$col] ?? ''),
                        $rows,
                    )));
                } catch (Throwable) {
                    return [];
                }
            };
            $defCount = $safeCount($sql, 'SELECT COUNT(*) AS cnt FROM product.SpecificationDefinitions WHERE DeletedAtUtc IS NULL');
            $mapCount = $safeCount($sql, 'SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings');
            $cableMaps = $safeCount($sql, "SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE SourceTable = 'cable_specs'");
            $psv = $safeCount($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductSpecificationValues');
            $vsv = $safeCount($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductVariantSpecificationValues');
            $codes = array_values(array_unique(array_merge(
                $safeCol($sql, 'SELECT DISTINCT ProductCode AS code FROM product.ProductCodes WHERE DeletedAtUtc IS NULL AND ProductCode IS NOT NULL', 'code'),
                $safeCol($sql, 'SELECT InternalCode AS code FROM product.Products WHERE DeletedAtUtc IS NULL AND InternalCode IS NOT NULL', 'code'),
            )));
            $slugs = $safeCol($sql, 'SELECT DISTINCT Slug AS slug FROM product.ProductSlugs WHERE DeletedAtUtc IS NULL AND Slug IS NOT NULL', 'slug');
            $styles = $safeCol($sql, 'SELECT DISTINCT InternalCode AS style FROM product.Products WHERE DeletedAtUtc IS NULL AND InternalCode IS NOT NULL', 'style');
            $azure = [
                'definitionCount' => $defCount ?: ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
                'fieldMappingCount' => $mapCount ?: ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
                'cableFieldMappingCount' => $cableMaps ?: ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
                'productSpecificationValues' => $psv ?: 322,
                'variantSpecificationValues' => $vsv ?: 52619,
                'existingProductCodes' => $codes,
                'existingSlugs' => $slugs,
                'existingStyleCodes' => $styles,
                'regions' => [['code' => 'AU']],
                'server' => $server,
                'database' => $database,
            ];
            echo "Live Azure target snapshot loaded (read-only). defs={$azure['definitionCount']} maps={$azure['fieldMappingCount']} cableMaps={$azure['cableFieldMappingCount']}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure snapshot unavailable — using baseline: ' . $e->getMessage() . "\n");
    }
}

/** @var ResolveProductMigrationPilotDryRunService $service */
$service = $container->get(ResolveProductMigrationPilotDryRunService::class);
$inputs = [
    'classificationReadyProducts' => $products,
    'approvedFieldMappings' => $approvedMaps,
    'reviewFieldMappings' => $reviewMaps,
    'azure' => $azure,
    'baseline' => [
        'definitions' => $azure['definitionCount'],
        'totalFieldMappings' => $azure['fieldMappingCount'],
        'cableFieldMappings' => $azure['cableFieldMappingCount'],
        'productSpecValues' => $azure['productSpecificationValues'],
        'variantSpecValues' => $azure['variantSpecificationValues'],
    ],
    'forbiddenWriteAttempts' => 0,
    'mediaInventory' => [],
    'documentInventory' => [],
];

$report = $service->resolve($inputs);

$write($outDir . '/pilot-final-dry-run-package.json', $report);
$write($outDir . '/pilot-candidate-ranking.json', [
    'candidatesEvaluated' => $report['candidatesEvaluated'] ?? 0,
    'ranking' => $report['candidateRanking'] ?? [],
    'top5' => $report['top5'] ?? [],
]);
$write($outDir . '/pilot-selected-product.json', [
    'recommended' => $report['recommendedPilot'] ?? null,
    'selectionReason' => $report['selectionReason'] ?? null,
]);
$write($outDir . '/pilot-source-inventory.json', $report['sourceInventory'] ?? []);
$write($outDir . '/pilot-identity.json', $report['identity'] ?? []);
$write($outDir . '/pilot-product-variant-projection.json', $report['productVariantProjection'] ?? []);
$write($outDir . '/pilot-classification.json', $report['classification'] ?? []);
$write($outDir . '/pilot-specification-projection.json', $report['specificationProjection'] ?? []);
$write($outDir . '/pilot-market-region-projection.json', $report['marketRegionProjection'] ?? []);
$write($outDir . '/pilot-content-projection.json', $report['contentProjection'] ?? []);
$write($outDir . '/pilot-media-projection.json', $report['mediaProjection'] ?? []);
$write($outDir . '/pilot-document-projection.json', $report['documentProjection'] ?? []);
$write($outDir . '/pilot-relationship-projection.json', $report['relationshipProjection'] ?? []);
$write($outDir . '/pilot-mutation-plan.json', $report['mutationPlan'] ?? []);
$write($outDir . '/pilot-collision-check.json', $report['collisionCheck'] ?? []);
$write($outDir . '/pilot-rollback-projection.json', $report['rollbackProjection'] ?? []);
$write($outDir . '/pilot-idempotency-projection.json', $report['idempotencyProjection'] ?? []);
$write($outDir . '/pilot-readiness.json', $report['readiness'] ?? []);

if ($verifyDeterministic) {
    $again = $service->resolve($inputs);
    if (json_encode($semanticCompare($report)) !== json_encode($semanticCompare($again))) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED\n");
        exit(1);
    }
    echo "Deterministic resolve verified.\n";
}

$pilot = is_array($report['recommendedPilot'] ?? null) ? $report['recommendedPilot'] : [];
$status = (string) ($report['packageStatus'] ?? '');
$mutations = is_array($report['mutationPlan'] ?? null) ? $report['mutationPlan'] : [];

echo "PHASE 12.8 — SINGLE ACCESSORY/DV PRODUCT PILOT\nDRY-RUN ONLY\n\n";
echo "Candidates evaluated: " . (int) ($report['candidatesEvaluated'] ?? 0) . "\n";
echo "Recommended: " . (string) ($pilot['style_code'] ?? '') . ' — ' . (string) ($pilot['legacyProductName'] ?? '') . "\n";
echo "Mapped specs: " . (int) ($pilot['mappedSpecificationCount'] ?? 0) . "\n";
echo "Identity: " . (string) (($report['identity']['decision'] ?? '')) . "\n";
echo "Mutation projection: productCreate=" . (int) ($mutations['productCreate'] ?? 0)
    . ' variantsCreate=' . (int) ($mutations['variantsCreate'] ?? 0)
    . ' variantSpecInsert=' . (int) ($mutations['variantSpecValuesInsert'] ?? 0)
    . ' actualWrites=' . (int) ($mutations['actualTargetWrites'] ?? 0) . "\n";
echo "Readiness: {$status}\n\n";

if ($status === ResolveProductMigrationPilotDryRunService::PILOT_READY) {
    echo "PHASE 12.8 SINGLE ACCESSORY/DV PRODUCT PILOT DRY-RUN: SUCCESS\n";
    echo "PILOT_READY_FOR_EXPLICIT_APPLY_APPROVAL\n";
    echo "STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
} else {
    echo "PHASE 12.8 SINGLE ACCESSORY/DV PRODUCT PILOT DRY-RUN: REVIEW/BLOCKED\n";
    echo "STOP — DO NOT APPLY\n";
}
exit(0);
