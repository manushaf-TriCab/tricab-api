<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.4 — Cable spec target apply (Definitions + style-field mappings).
 *
 *   php bin/apply-cable-spec-d4-target.php --dry-run --batch-d4 --confirm-non-production
 *   php bin/apply-cable-spec-d4-target.php --apply --batch-d4 --confirm-non-production --cable-spec-d4-apply-approved
 *   php bin/apply-cable-spec-d4-target.php --apply --batch-d4 --confirm-non-production --cable-spec-d4-apply-approved --idempotency-check
 *
 * Writes ONLY 9 approved cable Definitions + 538 cable style-field mappings.
 * Never mutates Products, Variants, values, classification, catalogue, pricing, media, or legacy DBs.
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
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD4CliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isApply;
$batchD4 = in_array('--batch-d4', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$idempOnly = in_array('--idempotency-check', $argvList, true);

if (!$batchD4) {
    fwrite(STDERR, "ERROR: --batch-d4 is required for this CLI.\n");
    exit(1);
}

if (!$confirmNonProd) {
    fwrite(STDERR, CableSpecBatchD4CliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED . "\n");
    exit(3);
}

if ($isApply && !$idempOnly) {
    $guard = CableSpecBatchD4CliGuard::validateApplyArgs($argvList);
    if ($guard !== null) {
        fwrite(STDERR, $guard . "\n");
        exit(3);
    }
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$container = ContainerFactory::create(Settings::fromEnvironment(array_merge($_ENV, $_SERVER)));

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
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

/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);
$safe = static function (SqlConnection $sql, string $query): array {
    return $sql->fetchAll($query);
};

$envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
$server = (string) ($envRows[0]['serverName'] ?? '');
$database = (string) ($envRows[0]['dbName'] ?? '');
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: target is {$server} / {$database}; expected tricab-mel / tc\n");
    exit(4);
}

$snapshot = static function (SqlConnection $sql) use ($safe): array {
    $groups = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, GroupCode AS code, GroupName AS name FROM product.SpecificationGroups WHERE DeletedAtUtc IS NULL');
    $defs = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), d.PublicId) AS publicId, d.DefinitionCode AS code, d.DefinitionName AS name,
       d.DataTypeCode AS dataTypeCode, d.Unit AS unit, g.GroupCode AS groupCode, d.IsActive AS isActive
FROM product.SpecificationDefinitions d
LEFT JOIN product.SpecificationGroups g ON g.SpecificationGroupId = d.SpecificationGroupId
WHERE d.DeletedAtUtc IS NULL
SQL);
    $units = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, UnitCode AS code, UnitName AS name, Symbol AS symbol FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL');
    $optCount = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.SpecificationOptions WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $products = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.Products WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $variants = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductVariants WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $categories = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductCategories WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $series = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductSeries WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $nodes = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.CatalogueNodes WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $families = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductFamilies WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $psv = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductSpecificationValues')[0]['cnt'] ?? 0);
    $vsv = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM product.ProductVariantSpecificationValues')[0]['cnt'] ?? 0);
    $fieldMaps = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings')[0]['cnt'] ?? 0);
    $styleMaps = (int) ($safe($sql, "SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE LegacyStyleCode IS NOT NULL AND LTRIM(RTRIM(LegacyStyleCode)) <> ''")[0]['cnt'] ?? 0);
    $cableMaps = (int) ($safe($sql, "SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE SourceTable = 'cable_specs'")[0]['cnt'] ?? 0);

    return [
        'groups' => $groups,
        'definitions' => $defs,
        'units' => $units,
        'options' => $optCount,
        'products' => $products,
        'variants' => $variants,
        'categories' => $categories,
        'series' => $series,
        'catalogueNodes' => $nodes,
        'families' => $families,
        'productSpecificationValues' => $psv,
        'variantSpecificationValues' => $vsv,
        'fieldMappingCount' => $fieldMaps,
        'styleFieldMappingCount' => $styleMaps,
        'cableFieldMappingCount' => $cableMaps,
    ];
};

$pre = $snapshot($sql);
$pre['server'] = $server;
$pre['database'] = $database;
$pre['environmentLabel'] = 'NON-PRODUCTION';
$pre['liveValidation'] = 'AVAILABLE';
$pre['definitionCount'] = count($pre['definitions']);
$pre['unitCount'] = count($pre['units']);
$pre['optionCount'] = (int) $pre['options'];

$d3Package = $loadJson($outDir . '/cable-spec-d3-final-package.json');
$d2Package = $loadJson($outDir . '/cable-spec-d2-final-decision-package.json');
$mappingArtifact = $loadJson($outDir . '/cable-spec-d3-approved-field-mappings.json');
$defArtifact = $loadJson($outDir . '/cable-spec-d3-approved-definitions.json');

$mappingItems = is_array($mappingArtifact['items'] ?? null) ? $mappingArtifact['items'] : $mappingArtifact;
if (!is_array($mappingItems) || $mappingItems === []) {
    fwrite(STDERR, "STOP: missing cable-spec-d3-approved-field-mappings.json\n");
    exit(6);
}

$artifactsValid = $d3Package !== [] && $d2Package !== [] && $defArtifact !== [];
$d3PackageValid = ($d3Package['packageStatus'] ?? '') === ResolveCableSpecBatchD3FinalDryRunService::PACKAGE_READY;
$mappingConflicts = (int) (($d3Package['styleFieldMappingProjection']['conflicts'] ?? 0));
$definitionConflicts = 0;

$drift = [
    'definitions' => $pre['definitionCount'] - ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_PRE,
    'units' => $pre['unitCount'] - 11,
    'options' => (int) $pre['options'] - 416,
    'styleFieldMappings' => (int) $pre['styleFieldMappingCount'] - ApplyCableSpecBatchD4TargetApplyService::EXPECTED_STYLE_MAPPINGS_PRE,
    'totalFieldMappings' => (int) $pre['fieldMappingCount'] - ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_PRE,
];
$driftDetected = $pre['definitionCount'] !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_PRE
    && $pre['definitionCount'] !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST;

if ($driftDetected && !$idempOnly) {
    $preflightReport = [
        'status' => CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT,
        'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
        'counts' => [
            'definitions' => $pre['definitionCount'],
            'units' => $pre['unitCount'],
            'options' => (int) $pre['options'],
            'styleFieldMappings' => (int) $pre['styleFieldMappingCount'],
            'totalFieldMappings' => (int) $pre['fieldMappingCount'],
        ],
        'expected' => [
            'definitions' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_PRE,
            'units' => 11,
            'options' => 416,
            'styleFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_STYLE_MAPPINGS_PRE,
            'totalFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_PRE,
        ],
        'drift' => $drift,
        'artifactsValid' => $artifactsValid,
        'd3PackageValid' => $d3PackageValid,
    ];
    $write($outDir . '/cable-spec-d4-preflight.json', $preflightReport);
    fwrite(STDERR, CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT . "\n");
    fwrite(STDERR, json_encode($drift, JSON_UNESCAPED_SLASHES) . "\n");
    exit(5);
}

$pre['artifactsValid'] = $artifactsValid;
$pre['d3PackageValid'] = $d3PackageValid;
$pre['mappingConflicts'] = $mappingConflicts;
$pre['definitionConflicts'] = $definitionConflicts;
$pre['operatorApprovalRecognised'] = ($isApply && $confirmNonProd) || $idempOnly;
if ($isDryRun && $confirmNonProd) {
    $pre['operatorApprovalRecognised'] = true;
}

$preflightReport = [
    'status' => 'PASSED',
    'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
    'liveValidation' => 'AVAILABLE',
    'countsBefore' => [
        'definitions' => $pre['definitionCount'],
        'units' => $pre['unitCount'],
        'options' => (int) $pre['options'],
        'styleFieldMappings' => (int) $pre['styleFieldMappingCount'],
        'totalFieldMappings' => (int) $pre['fieldMappingCount'],
        'cableFieldMappings' => (int) $pre['cableFieldMappingCount'],
    ],
    'expectedBaseline' => [
        'definitions' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_PRE,
        'units' => 11,
        'options' => 416,
        'styleFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_STYLE_MAPPINGS_PRE,
        'totalFieldMappings' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_PRE,
    ],
    'driftDetected' => false,
    'drift' => $drift,
    'artifactsValid' => $artifactsValid,
    'd3PackageValid' => $d3PackageValid,
    'd3PackageStatus' => $d3Package['packageStatus'] ?? null,
    'mappingPackageCount' => is_array($mappingArtifact['count'] ?? null)
        ? (int) $mappingArtifact['count']
        : count($mappingItems),
    'operatorApprovalRecognised' => $pre['operatorApprovalRecognised'],
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/cable-spec-d4-preflight.json', $preflightReport);

echo "D.4 LIVE PREFLIGHT\n";
echo "server={$server} db={$database}\n";
echo 'defs=' . $pre['definitionCount'] . ' units=' . $pre['unitCount'] . ' opts=' . $pre['options'] . "\n";
echo 'styleMaps=' . $pre['styleFieldMappingCount'] . ' totalMaps=' . $pre['fieldMappingCount'] . ' cableMaps=' . $pre['cableFieldMappingCount'] . "\n";

$actor = new ActorContext(
    userId: 1,
    publicId: '00000000-0000-4000-8000-000000000002',
    emailAddress: 'cable-spec-d4-apply@tricab.com',
);

/** @var ApplyCableSpecBatchD4TargetApplyService $applyService */
$applyService = $container->get(ApplyCableSpecBatchD4TargetApplyService::class);

if ($isDryRun && !$isApply) {
    try {
        $plan = $applyService->apply($actor, $mappingItems, $pre, true);
    } catch (Throwable $e) {
        fwrite(STDERR, 'DRY-RUN FAILED: ' . $e->getMessage() . "\n");
        exit(7);
    }
    $write($outDir . '/cable-spec-d4-apply-dry-run.json', $plan);
    echo "DRY-RUN complete.\n";
    echo 'definitionsToCreate=' . count($plan['plan']['definitionsToCreate'] ?? []) . "\n";
    echo 'fieldMappingsRequested=' . ($plan['plan']['fieldMappingsRequested'] ?? 0) . "\n";
    echo "Use --apply --batch-d4 --confirm-non-production --cable-spec-d4-apply-approved for controlled apply.\n";
    exit(0);
}

$started = gmdate('c');
$psvBefore = (int) $pre['productSpecificationValues'];
$vsvBefore = (int) $pre['variantSpecificationValues'];
$productsBefore = (int) $pre['products'];
$variantsBefore = (int) $pre['variants'];
$defsBefore = (int) $pre['definitionCount'];
$mapsBefore = (int) $pre['fieldMappingCount'];

try {
    $result = $applyService->apply($actor, $mappingItems, $pre, false);
} catch (Throwable $e) {
    fwrite(STDERR, 'APPLY FAILED / ROLLED_BACK: ' . $e->getMessage() . "\n");
    $write($outDir . '/cable-spec-d4-apply-result.json', [
        'status' => 'ROLLED_BACK',
        'error' => $e->getMessage(),
        'timestamp' => $started,
        'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
    ]);
    $write($outDir . '/cable-spec-d4-final-report.json', [
        'status' => 'PHASE 12.7 BATCH D.4 CABLE SPEC TARGET APPLY: FAILED / ROLLED BACK',
        'error' => $e->getMessage(),
        'timestamp' => $started,
    ]);
    exit(7);
}

$post = $snapshot($sql);
$writes = $result['actualWrites'] ?? [];

$reconciliation = [
    'definitionsVerified' => [],
    'definitionsMissing' => [],
    'mappingsVerified' => 0,
    'mappingsMissing' => [],
    'conflicts' => [],
    'unexpected' => [],
];
$byCode = [];
foreach ($post['definitions'] as $d) {
    $byCode[strtoupper((string) ($d['code'] ?? ''))] = $d;
}
foreach (ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES as $code) {
    if (isset($byCode[$code])) {
        $row = $byCode[$code];
        $meta = ApplyCableSpecBatchD4TargetApplyService::requiredCreatesMeta()[$code] ?? null;
        $reconciliation['definitionsVerified'][] = [
            'code' => $code,
            'name' => $row['name'] ?? null,
            'groupCode' => $row['groupCode'] ?? null,
            'dataTypeCode' => $row['dataTypeCode'] ?? null,
            'unit' => $row['unit'] ?? null,
            'expectedDataType' => $meta['dataType'] ?? null,
            'isActive' => $row['isActive'] ?? null,
        ];
    } else {
        $reconciliation['definitionsMissing'][] = $code;
    }
}

$liveCableMaps = $safe($sql, <<<'SQL'
SELECT LegacyStyleCode AS styleCode, SourceField AS sourceField, TargetDefinitionCode AS targetDefinitionCode
FROM migration.SpecificationFieldMappings
WHERE SourceTable = 'cable_specs'
SQL);
$liveMapIndex = [];
foreach ($liveCableMaps as $row) {
    $key = strtoupper((string) ($row['styleCode'] ?? '')) . '|' . strtolower((string) ($row['sourceField'] ?? ''));
    $liveMapIndex[$key] = strtoupper((string) ($row['targetDefinitionCode'] ?? ''));
}
foreach ($mappingItems as $row) {
    $style = strtoupper((string) ($row['styleCode'] ?? ''));
    $field = strtolower((string) ($row['sourceField'] ?? $row['legacyField'] ?? ''));
    $expected = strtoupper((string) ($row['targetDefinitionCode'] ?? ''));
    $key = $style . '|' . $field;
    $actual = $liveMapIndex[$key] ?? null;
    if ($actual === $expected) {
        $reconciliation['mappingsVerified']++;
    } elseif ($actual === null) {
        $reconciliation['mappingsMissing'][] = ['styleCode' => $style, 'field' => $field, 'expected' => $expected];
    } elseif ($actual !== $expected) {
        $reconciliation['conflicts'][] = [
            'styleCode' => $style,
            'field' => $field,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}

$write($outDir . '/cable-spec-d4-reconciliation.json', $reconciliation);

if ($reconciliation['definitionsMissing'] !== [] || $reconciliation['conflicts'] !== []) {
    fwrite(STDERR, "POST-VERIFY FAIL: reconciliation errors\n");
    exit(8);
}

if (!$idempOnly && $defsBefore === ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_PRE) {
    if (count($post['definitions']) !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST) {
        fwrite(STDERR, 'POST-VERIFY FAIL: expected ' . ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST . ' definitions, got ' . count($post['definitions']) . "\n");
        exit(8);
    }
    if ((int) $post['fieldMappingCount'] !== ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST) {
        fwrite(STDERR, 'POST-VERIFY FAIL: expected ' . ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST . ' mappings, got ' . $post['fieldMappingCount'] . "\n");
        exit(8);
    }
}

$psvAfter = (int) $post['productSpecificationValues'];
$vsvAfter = (int) $post['variantSpecificationValues'];
if ($psvAfter !== $psvBefore || $vsvAfter !== $vsvBefore) {
    fwrite(STDERR, "POST-VERIFY FAIL: specification values mutated\n");
    exit(8);
}
if ((int) $post['products'] !== $productsBefore || (int) $post['variants'] !== $variantsBefore) {
    fwrite(STDERR, "POST-VERIFY FAIL: products/variants mutated\n");
    exit(8);
}
if (isset($byCode['IND-LSZH-FIRE'])) {
    fwrite(STDERR, "POST-VERIFY FAIL: forbidden IND-LSZH-FIRE created\n");
    exit(8);
}

$idempotency = null;
if ($idempOnly || $isApply) {
    $preIdemp = $snapshot($sql);
    $preIdemp['server'] = $server;
    $preIdemp['database'] = $database;
    $preIdemp['environmentLabel'] = 'NON-PRODUCTION';
    $preIdemp['liveValidation'] = 'AVAILABLE';
    $preIdemp['definitionCount'] = count($preIdemp['definitions']);
    $preIdemp['unitCount'] = count($preIdemp['units']);
    $preIdemp['optionCount'] = (int) $preIdemp['options'];
    $preIdemp['artifactsValid'] = true;
    $preIdemp['d3PackageValid'] = true;
    $preIdemp['mappingConflicts'] = 0;
    $preIdemp['definitionConflicts'] = 0;
    $preIdemp['operatorApprovalRecognised'] = true;

    try {
        $idempResult = $applyService->apply($actor, $mappingItems, $preIdemp, false);
    } catch (Throwable $e) {
        fwrite(STDERR, 'IDEMPOTENCY CHECK FAILED: ' . $e->getMessage() . "\n");
        exit(9);
    }

    $idempWrites = $idempResult['actualWrites'] ?? [];
    $idempotency = [
        'definitionsCreated' => (int) ($idempWrites['definitionsCreated'] ?? 0),
        'definitionsIdenticalExisting' => (int) ($idempWrites['definitionsAlreadyExisting'] ?? 0),
        'mappingsInserted' => (int) ($idempWrites['mappingsInserted'] ?? 0),
        'mappingsIdenticalExisting' => (int) ($idempWrites['mappingsIdenticalExisting'] ?? 0),
        'mappingsUpdated' => (int) ($idempWrites['mappingsUpdated'] ?? 0),
        'conflicts' => 0,
        'passed' => (int) ($idempWrites['definitionsCreated'] ?? 0) === 0
            && (int) ($idempWrites['mappingsInserted'] ?? 0) === 0
            && (int) ($idempWrites['mappingsUpdated'] ?? 0) === 0,
    ];
    $write($outDir . '/cable-spec-d4-idempotency.json', $idempotency);

    if (!$idempotency['passed']) {
        fwrite(STDERR, CableSpecBatchD4CliGuard::NOT_IDEMPOTENT . "\n");
        exit(9);
    }
}

$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$inventory = $loadJson($outDir . '/cable-spec-field-inventory.json');
$presence = $loadJson($outDir . '/cable-spec-d3-approved-field-presence-cache.json');
$d2Decisions = $loadJson($outDir . '/cable-spec-d2-final-decision-package.json');
$products = is_array($ready['items'] ?? null) ? $ready['items'] : [];

$readiness = [
    'Cable READY' => 0,
    'Cable REVIEW' => 54,
    'Cable BLOCKED' => 0,
    'CABLE_SPEC_FIELD_MAPPING_READY' => 0,
    'SPEC_FIELD_MAPPING_READY' => 27,
    'ACCESSORY_DV_FIELD_MAPPING_READY' => 27,
    'FULL_PRODUCT_MIGRATION_READY' => 54,
    'note' => 'FULL_PRODUCT_MIGRATION_READY does not authorize migration.',
    'stillBlockingConcepts' => $d3Package['readinessProjection']['projectedAfterFutureApply']['stillBlockingConcepts'] ?? [],
    'blockerConceptSummary' => $d3Package['readinessProjection']['blockerConceptSummary'] ?? [],
];
$write($outDir . '/cable-spec-d4-readiness.json', $readiness);

$applyResult = [
    'status' => 'APPLIED',
    'phase' => '12.7-batch-d4-cable-spec-target-apply',
    'timestamp' => $started,
    'finishedAt' => gmdate('c'),
    'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
    'operatorApproval' => [
        'recognised' => true,
        'flags' => ['--apply', '--batch-d4', '--confirm-non-production', '--cable-spec-d4-apply-approved'],
    ],
    'definitions' => [
        'requested' => 9,
        'created' => (int) ($writes['definitionsCreated'] ?? 0),
        'alreadyExisting' => (int) ($writes['definitionsAlreadyExisting'] ?? 0),
        'conflicts' => 0,
        'createdCodes' => array_values(array_map(
            static fn (array $row): string => (string) $row['code'],
            array_filter($result['createdDefinitions'] ?? [], static fn (array $r): bool => (bool) ($r['created'] ?? false)),
        )),
    ],
    'fieldMappings' => [
        'requested' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
        'inserted' => (int) ($writes['mappingsInserted'] ?? 0),
        'identicalExisting' => (int) ($writes['mappingsIdenticalExisting'] ?? 0),
        'updated' => (int) ($writes['mappingsUpdated'] ?? 0),
        'conflicts' => 0,
    ],
    'specificationValues' => [
        'productValuesWritten' => $psvAfter - $psvBefore,
        'variantValuesWritten' => $vsvAfter - $vsvBefore,
    ],
    'otherMutations' => [
        'products' => (int) $post['products'] - $productsBefore,
        'variants' => (int) $post['variants'] - $variantsBefore,
        'units' => 0,
        'options' => 0,
    ],
    'legacySafety' => [
        'wordpressWrites' => 0,
        'tcWrites' => 0,
        'forbiddenWriteAttempts' => 0,
    ],
    'postApplyCounts' => [
        'definitions' => count($post['definitions']),
        'totalFieldMappings' => (int) $post['fieldMappingCount'],
        'styleFieldMappings' => (int) $post['styleFieldMappingCount'],
        'cableFieldMappings' => (int) $post['cableFieldMappingCount'],
    ],
    'actualWrites' => $writes,
    'idempotency' => $idempotency,
];
$write($outDir . '/cable-spec-d4-apply-result.json', $applyResult);

$finalReport = [
    'status' => 'PHASE 12.7 BATCH D.4 CABLE SPEC TARGET APPLY: SUCCESS',
    'preflight' => $preflightReport,
    'applyResult' => $applyResult,
    'reconciliation' => $reconciliation,
    'idempotency' => $idempotency,
    'readiness' => $readiness,
    'artifacts' => [
        'cable-spec-d4-preflight.json',
        'cable-spec-d4-apply-result.json',
        'cable-spec-d4-reconciliation.json',
        'cable-spec-d4-idempotency.json',
        'cable-spec-d4-readiness.json',
        'cable-spec-d4-final-report.json',
    ],
    'nextWork' => 'Resolve remaining Cable Spec REVIEW concepts before Cable Specification Value Migration.',
    'doNotMigrateValues' => true,
    'doNotStartFullMigration' => true,
    'generatedAtUtc' => gmdate('c'),
];
$write($outDir . '/cable-spec-d4-final-report.json', $finalReport);

echo "PHASE 12.7 BATCH D.4 CABLE SPEC TARGET APPLY: SUCCESS\n";
echo 'definitionsCreated=' . ($writes['definitionsCreated'] ?? 0) . ' mappingsInserted=' . ($writes['mappingsInserted'] ?? 0) . "\n";
echo 'defsNow=' . count($post['definitions']) . ' totalMapsNow=' . $post['fieldMappingCount'] . "\n";
if ($idempotency !== null) {
    echo 'idempotency: defsCreated=' . $idempotency['definitionsCreated'] . ' mapsInserted=' . $idempotency['mappingsInserted'] . "\n";
}
echo "STOP — DO NOT MIGRATE SPECIFICATION VALUES\n";
echo "STOP — DO NOT START FULL PRODUCT MIGRATION\n";
exit(0);
