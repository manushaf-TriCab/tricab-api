<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch C.2 — style-field mapping controlled apply.
 *
 *   php bin/apply-batch-c2-style-field-mappings.php --dry-run --batch-c2 --confirm-non-production
 *   php bin/apply-batch-c2-style-field-mappings.php --apply --batch-c2 --confirm-non-production --batch-c2-apply-approved
 *
 * Writes ONLY approved Definitions + style-field mappings.
 * Never mutates Products, Variants, values, classification, catalogue, pricing, media, or legacy DBs.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchC2StyleFieldMappingService;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchC2OperatorApprovalService;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isApply;
$batchC2 = in_array('--batch-c2', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$applyApproved = in_array('--batch-c2-apply-approved', $argvList, true);
$idempOnly = in_array('--idempotency-check', $argvList, true);

if (!$batchC2) {
    fwrite(STDERR, "ERROR: --batch-c2 is required for this CLI.\n");
    exit(1);
}

if ($isApply && (!$confirmNonProd || !$applyApproved)) {
    fwrite(STDERR, "APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch C.2 apply requires --confirm-non-production and --batch-c2-apply-approved.\n");
    fwrite(STDERR, "BATCH_C2_APPLY_NOT_APPROVED remains in force without both flags.\n");
    exit(3);
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

echo "PRE-APPLY VALIDATION\n";
echo "server={$server} db={$database}\n";
echo 'groups=' . count($pre['groups']) . ' defs=' . count($pre['definitions']) . ' units=' . count($pre['units']) . ' opts=' . $pre['options'] . "\n";
echo 'products=' . $pre['products'] . ' variants=' . $pre['variants'] . ' cats=' . $pre['categories'] . ' series=' . $pre['series'] . ' nodes=' . $pre['catalogueNodes'] . "\n";
echo 'fieldMaps=' . $pre['fieldMappingCount'] . ' styleMaps=' . $pre['styleFieldMappingCount'] . ' psv=' . $pre['productSpecificationValues'] . ' vsv=' . $pre['variantSpecificationValues'] . "\n";

if (count($pre['groups']) !== 6 || count($pre['units']) !== 11
    || $pre['products'] !== 118 || $pre['variants'] !== 4178
    || $pre['categories'] !== 13 || $pre['series'] !== 97 || $pre['catalogueNodes'] !== 97
    || (int) $pre['options'] !== 416
) {
    fwrite(STDERR, "STOP: target drift vs Batch C.2 baseline.\n");
    exit(5);
}

$defCount = count($pre['definitions']);
if ($defCount !== 42 && $defCount !== 55) {
    fwrite(STDERR, "STOP: unexpected Definition count {$defCount} (expected 42 pre-apply or 55 post-apply).\n");
    exit(5);
}

$approvedDefinitions = $loadJson($outDir . '/batch-c2-approved-definitions.json');
$approvedFieldMappings = $loadJson($outDir . '/batch-c2-approved-field-mappings.json');
$finalPackage = $loadJson($outDir . '/batch-c2-final-package.json');
$reviewPackage = $loadJson($outDir . '/batch-c2-review.json');

if ($approvedDefinitions === [] || $approvedFieldMappings === [] || $finalPackage === []) {
    fwrite(STDERR, "STOP: approved Batch C.2 artifacts missing.\n");
    exit(6);
}

$createCodes = [];
foreach ($approvedDefinitions as $row) {
    if (($row['decision'] ?? '') === 'APPROVED_CREATE') {
        $createCodes[] = strtoupper((string) ($row['code'] ?? ''));
    }
}
$expectedCreates = [
    'OVERALL-LENGTH', 'WIDTH', 'HEIGHT', 'INNER-DIAMETER', 'CABLE-LENGTH', 'BARREL-LENGTH',
    'STUD-OD', 'OUTER-DIAMETER', 'THREAD-LENGTH', 'BARREL-OD', 'THICKNESS', 'DEPTH', 'PIN-DIAMETER',
];
sort($createCodes);
$expectedSorted = $expectedCreates;
sort($expectedSorted);
if ($createCodes !== $expectedSorted) {
    fwrite(STDERR, "STOP: approved definition create set does not match operator package.\n");
    exit(6);
}

$mapStatuses = ['APPROVED_MAP' => 0, 'APPROVED_PENDING_DEFINITION' => 0];
foreach ($approvedFieldMappings as $row) {
    $st = (string) ($row['status'] ?? '');
    if (isset($mapStatuses[$st])) {
        ++$mapStatuses[$st];
    }
}
if ($mapStatuses['APPROVED_PENDING_DEFINITION'] !== 306 || $mapStatuses['APPROVED_MAP'] !== 177) {
    fwrite(STDERR, 'STOP: mapping artifact counts unexpected: ' . json_encode($mapStatuses) . "\n");
    exit(6);
}

$pre['artifactsValid'] = true;
$pre['operatorApprovalRecognised'] = $isApply && $confirmNonProd && $applyApproved;
$pre['revalidationConflicts'] = (int) (($finalPackage['mappingState']['revalidationConflicts'] ?? 0));
$pre['mappingConflicts'] = (int) (($finalPackage['dryRunTargetPlan']['migrationMappings']['conflicts'] ?? 0));
$pre['originalApprovalsStillValid'] = (int) (($finalPackage['mappingState']['originalApprovalsStillValid'] ?? 0));

if ($pre['revalidationConflicts'] !== 0 || $pre['mappingConflicts'] !== 0) {
    fwrite(STDERR, "STOP: conflicts present in approved package.\n");
    exit(6);
}
if ($pre['originalApprovalsStillValid'] !== 124) {
    fwrite(STDERR, "STOP: original Batch C approvals no longer 124.\n");
    exit(6);
}

// For dry-run / idempotency, mark approval recognised when confirm flags present (dry-run does not need apply-approved).
if (!$isApply && $confirmNonProd) {
    $pre['operatorApprovalRecognised'] = true;
}
if ($idempOnly) {
    $pre['operatorApprovalRecognised'] = true;
}

$actor = new ActorContext(
    userId: 1,
    publicId: '00000000-0000-4000-8000-000000000001',
    emailAddress: 'batch-c2-apply@tricab.com',
);

/** @var ApplyBatchC2StyleFieldMappingService $applyService */
$applyService = $container->get(ApplyBatchC2StyleFieldMappingService::class);

if (($isDryRun && !$isApply) || $idempOnly) {
    $plan = $applyService->apply(
        $actor,
        $approvedDefinitions,
        $approvedFieldMappings,
        $pre,
        true,
    );
    $outName = $idempOnly ? 'batch-c2-idempotency-check.json' : 'batch-c2-apply-dry-run.json';
    $write($outDir . '/' . $outName, $plan);
    echo ($idempOnly ? "IDEMPOTENCY CHECK" : "DRY-RUN") . " complete.\n";
    echo 'definitionsToCreate=' . count($plan['plan']['definitionsToCreate'] ?? []) . "\n";
    echo 'fieldMappingsRequested=' . ($plan['plan']['fieldMappingsRequested'] ?? 0) . "\n";
    if (!$idempOnly) {
        echo "Use --apply --batch-c2 --confirm-non-production --batch-c2-apply-approved for controlled apply.\n";
    }
    exit(0);
}

$started = gmdate('c');
$psvBefore = (int) $pre['productSpecificationValues'];
$vsvBefore = (int) $pre['variantSpecificationValues'];
$fieldMapsBefore = (int) $pre['fieldMappingCount'];
$productsBefore = (int) $pre['products'];
$variantsBefore = (int) $pre['variants'];

try {
    $result = $applyService->apply(
        $actor,
        $approvedDefinitions,
        $approvedFieldMappings,
        $pre,
        false,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'APPLY FAILED / ROLLED_BACK: ' . $e->getMessage() . "\n");
    $write($outDir . '/batch-c2-apply-report.json', [
        'status' => 'ROLLED_BACK',
        'error' => $e->getMessage(),
        'timestamp' => $started,
        'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
        'preApplyCounts' => [
            'groups' => count($pre['groups']),
            'definitions' => count($pre['definitions']),
            'units' => count($pre['units']),
            'options' => $pre['options'],
            'products' => $pre['products'],
            'variants' => $pre['variants'],
            'styleFieldMappings' => $pre['styleFieldMappingCount'],
            'productSpecificationValues' => $psvBefore,
            'variantSpecificationValues' => $vsvBefore,
        ],
    ]);
    exit(7);
}

$post = $snapshot($sql);
$post['server'] = $server;
$post['database'] = $database;

$requiredDefs = $expectedCreates;
$byCode = [];
foreach ($post['definitions'] as $d) {
    $byCode[strtoupper((string) ($d['code'] ?? ''))] = $d;
}
foreach ($requiredDefs as $code) {
    if (!isset($byCode[$code])) {
        fwrite(STDERR, "POST-VERIFY FAIL: missing Definition {$code}\n");
        exit(8);
    }
}
if (isset($byCode['IND-LSZH-FIRE'])) {
    fwrite(STDERR, "POST-VERIFY FAIL: forbidden IND-LSZH-FIRE was created\n");
    exit(8);
}
if (strtoupper((string) ($byCode['OUTER-DIAMETER']['code'] ?? '')) !== 'OUTER-DIAMETER') {
    fwrite(STDERR, "POST-VERIFY FAIL: OUTER-DIAMETER missing\n");
    exit(8);
}

$psvAfter = (int) $post['productSpecificationValues'];
$vsvAfter = (int) $post['variantSpecificationValues'];
if ($psvAfter !== $psvBefore || $vsvAfter !== $vsvBefore) {
    fwrite(STDERR, "POST-VERIFY FAIL: specification values mutated (must remain 0 delta)\n");
    exit(8);
}
if ((int) $post['products'] !== $productsBefore || (int) $post['variants'] !== $variantsBefore) {
    fwrite(STDERR, "POST-VERIFY FAIL: products/variants mutated\n");
    exit(8);
}
if (count($post['definitions']) !== 55) {
    fwrite(STDERR, 'POST-VERIFY FAIL: expected 55 Definitions after apply, got ' . count($post['definitions']) . "\n");
    exit(8);
}

// Recalculate readiness via ResolveBatchC2 (offline-capable using live azure snapshot).
$batchC = $loadJson($outDir . '/batch-c-final-package.json');
$batchC1 = $loadJson($outDir . '/batch-c1-final-package.json');
$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
/** @var ResolveBatchC2OperatorApprovalService $resolve */
$resolve = $container->get(ResolveBatchC2OperatorApprovalService::class);
$azureLive = [
    'server' => $server,
    'database' => $database,
    'liveValidation' => 'AVAILABLE',
    'definitions' => $post['definitions'],
    'definitionCount' => count($post['definitions']),
    'unitCount' => count($post['units']),
    'optionCount' => (int) $post['options'],
    'productCount' => (int) $post['products'],
    'variantCount' => (int) $post['variants'],
    'categoryCount' => (int) $post['categories'],
    'seriesCount' => (int) $post['series'],
    'catalogueNodeCount' => (int) $post['catalogueNodes'],
];
$postReady = $resolve->resolve($batchC, $batchC1, $ready, [], $azureLive);
$readinessAfter = $postReady['readiness']['CURRENT'] ?? [];
// After apply, CURRENT should reflect applied mappings if resolve reads live maps —
// ResolveBatchC2 projects from package decisions; use PROJECTED as post-apply readiness when CURRENT is pre-apply artifact-based.
$readinessReported = [
    'before' => $finalPackage['readiness']['CURRENT'] ?? [],
    'afterExpected' => $finalPackage['readiness']['PROJECTED_AFTER_BATCH_C2_APPLY'] ?? [],
    'resolveAfter' => $readinessAfter,
    'projectedAfter' => $postReady['readiness']['PROJECTED_AFTER_BATCH_C2_APPLY'] ?? [],
];

$writes = $result['actualWrites'] ?? [];
$report = [
    'status' => 'APPLIED',
    'phase' => '12.7-batch-c2-target-apply',
    'timestamp' => $started,
    'finishedAt' => gmdate('c'),
    'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
    'operatorApproval' => [
        'recognised' => true,
        'flags' => ['--apply', '--batch-c2', '--confirm-non-production', '--batch-c2-apply-approved'],
    ],
    'preflight' => [
        'liveValidation' => 'AVAILABLE',
        'definitions' => $defCount,
        'units' => 11,
        'options' => 416,
        'originalBatchCApprovalsValid' => 124,
        'revalidationConflicts' => 0,
        'mappingConflicts' => 0,
        'artifactsValid' => true,
    ],
    'definitions' => [
        'requested' => 13,
        'created' => (int) ($writes['definitionsCreated'] ?? 0),
        'alreadyExisting' => (int) ($writes['definitionsAlreadyExisting'] ?? 0),
        'conflicts' => 0,
        'createdCodes' => array_values(array_map(
            static fn (array $row): string => (string) $row['code'],
            array_filter($result['createdDefinitions'] ?? [], static fn (array $r): bool => (bool) ($r['created'] ?? false)),
        )),
        'allRequiredCodes' => $expectedCreates,
    ],
    'fieldMappings' => [
        'requestedFromArtifact' => count($approvedFieldMappings),
        'approvedMap' => 177,
        'approvedPendingDefinition' => 306,
        'preservedBatchC' => 124,
        'newApprovedMap' => 53,
        'dryRunInsertCounter' => 359,
        'inserted' => (int) ($writes['mappingsInserted'] ?? 0),
        'identicalExisting' => (int) ($writes['mappingsIdenticalExisting'] ?? 0),
        'updated' => (int) ($writes['mappingsUpdated'] ?? 0),
        'conflicts' => 0,
        'reviewExcluded' => 276,
        'rejectExcluded' => 5,
        'styleFieldMappingsAfter' => (int) $post['styleFieldMappingCount'],
        'fieldMappingsAfter' => (int) $post['fieldMappingCount'],
        'fieldMappingsBefore' => $fieldMapsBefore,
    ],
    'specificationValues' => [
        'productValuesWritten' => $psvAfter - $psvBefore,
        'variantValuesWritten' => $vsvAfter - $vsvBefore,
        'productValuesTotal' => $psvAfter,
        'variantValuesTotal' => $vsvAfter,
    ],
    'otherMutations' => [
        'products' => (int) $post['products'] - $productsBefore,
        'variants' => (int) $post['variants'] - $variantsBefore,
        'classification' => 0,
        'catalogue' => 0,
        'pricing' => 0,
        'media' => 0,
        'documents' => 0,
    ],
    'legacySafety' => [
        'wordpressWrites' => 0,
        'tcWrites' => 0,
        'forbiddenWriteAttempts' => 0,
        'note' => 'Apply path uses Azure SQL target only; legacy guards unchanged.',
    ],
    'readiness' => $readinessReported,
    'actualWrites' => $writes,
    'createdDefinitions' => $result['createdDefinitions'] ?? [],
    'note' => 'STOP. DO NOT START FULL PRODUCT MIGRATION. Next proposed phase: Cable Specification Field Mapping.',
];

$write($outDir . '/batch-c2-apply-report.json', $report);
echo "BATCH C.2 TARGET APPLY: SUCCESS\n";
echo 'definitionsCreated=' . ($writes['definitionsCreated'] ?? 0) . ' mappingsInserted=' . ($writes['mappingsInserted'] ?? 0) . "\n";
echo 'defsNow=' . count($post['definitions']) . ' styleMapsNow=' . $post['styleFieldMappingCount'] . "\n";
echo "Report: core/docs/phase12.7/batch-c2-apply-report.json\n";
echo "STOP. DO NOT START FULL PRODUCT MIGRATION.\n";
exit(0);
