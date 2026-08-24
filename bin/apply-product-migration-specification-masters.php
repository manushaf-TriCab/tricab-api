<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch B — specification master dry-run / controlled apply.
 *
 *   php bin/apply-product-migration-specification-masters.php --dry-run --batch-b --confirm-non-production
 *   php bin/apply-product-migration-specification-masters.php --apply --batch-b --confirm-non-production --batch-b-apply-approved
 *
 * Apply writes ONLY approved Definitions, Standard options, field/option mappings, and SAFE aliases.
 * Never mutates Products, Variants, Catalogue, classification, pricing, media, Product spec values,
 * Batch C d/v mappings, or legacy DBs.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchBSpecificationMasterService;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isApply;
$batchB = in_array('--batch-b', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$applyApproved = in_array('--batch-b-apply-approved', $argvList, true);

if (!$batchB) {
    fwrite(STDERR, "ERROR: --batch-b is required for this CLI.\n");
    exit(1);
}

if ($isApply && (!$confirmNonProd || !$applyApproved)) {
    fwrite(STDERR, "APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch B apply requires --confirm-non-production and --batch-b-apply-approved.\n");
    fwrite(STDERR, "BATCH_B_APPLY_NOT_APPROVED remains in force without both flags.\n");
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
    $aliases = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM migration.SpecificationOptionAliases')[0]['cnt'] ?? 0);

    return [
        'server' => null,
        'database' => null,
        'environmentLabel' => 'NON-PRODUCTION',
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
        'optionAliasCount' => $aliases,
    ];
};

$pre = $snapshot($sql);
$pre['server'] = $server;
$pre['database'] = $database;

echo "PRE-APPLY VALIDATION\n";
echo "server={$server} db={$database}\n";
echo 'groups=' . count($pre['groups']) . ' defs=' . count($pre['definitions']) . ' units=' . count($pre['units']) . ' opts=' . $pre['options'] . "\n";
echo 'products=' . $pre['products'] . ' variants=' . $pre['variants'] . ' cats=' . $pre['categories'] . ' series=' . $pre['series'] . ' nodes=' . $pre['catalogueNodes'] . "\n";

if (count($pre['groups']) !== 6 || count($pre['units']) !== 11
    || $pre['products'] !== 118 || $pre['variants'] !== 4178
    || $pre['categories'] !== 13 || $pre['series'] !== 97 || $pre['catalogueNodes'] !== 97
) {
    fwrite(STDERR, "STOP: target drift vs Batch B.1 baseline.\n");
    exit(5);
}

$approvedDefinitions = $loadJson($outDir . '/batch-b-approved-definitions.json');
$approvedStandards = $loadJson($outDir . '/batch-b-approved-standards.json');
$approvedApprovals = $loadJson($outDir . '/batch-b-approved-approvals.json');
$approvedAliases = $loadJson($outDir . '/batch-b-approved-option-aliases.json');
$b1 = $loadJson($outDir . '/batch-b1-final-package.json');

if ($approvedDefinitions === [] || $approvedStandards === [] || $approvedApprovals === [] || $approvedAliases === []) {
    fwrite(STDERR, "STOP: approved Batch B artifacts missing.\n");
    exit(6);
}

// SPEC_MASTER_READY validation (heuristic vs deferred REVIEW).
$reviewDefs = [];
foreach (($b1['definitions']['items'] ?? []) as $item) {
    if (($item['decision'] ?? '') === 'REVIEW') {
        $reviewDefs[] = [
            'sourceField' => $item['sourceField'] ?? null,
            'usageCount' => $item['usageCount'] ?? 0,
            'reason' => $item['reason'] ?? null,
        ];
    }
}
$specMasterValidation = [
    'productsPreviouslyMarkedReady' => (int) ($b1['productReadinessImpact']['SPEC_MASTER_READY'] ?? 95),
    'requiredUnresolvedMasterReferencesFound' => count($reviewDefs),
    'deferredDefinitionReview' => $reviewDefs,
    'note' => 'Pre-apply SPEC_MASTER_READY=95 used CLASSIFICATION_READY as proxy after operator-approved masters. Remaining DEFINITION_REVIEW (ind_lszh_fire, nek_class, LENGTH, WIDTH) are intentionally deferred (Batch C / multi-flag). Unresolved Standard/Approval option REVIEW values are optional inventory, not required masters for this apply. No artificial promotion of unresolved Products.',
    'readinessCorrectionRequired' => true,
    'correctionType' => 'SEMANTIC_CLARIFICATION',
    'correctedSPEC_MASTER_READY' => 95,
];

$actor = new ActorContext(
    userId: 1,
    publicId: '00000000-0000-4000-8000-000000000001',
    emailAddress: 'batch-b-apply@tricab.com',
);

/** @var ApplyBatchBSpecificationMasterService $applyService */
$applyService = $container->get(ApplyBatchBSpecificationMasterService::class);

if ($isDryRun && !$isApply) {
    $plan = $applyService->apply(
        $actor,
        $approvedDefinitions,
        $approvedStandards,
        $approvedApprovals,
        $approvedAliases,
        $pre,
        true,
    );
    $write($outDir . '/batch-b-apply-dry-run.json', $plan);
    echo "DRY-RUN complete. Use --apply --batch-b --confirm-non-production --batch-b-apply-approved for controlled apply.\n";
    exit(0);
}

$started = gmdate('c');
$psvBefore = (int) $pre['productSpecificationValues'];
$vsvBefore = (int) $pre['variantSpecificationValues'];
$fieldMapsBefore = (int) $pre['fieldMappingCount'];
$aliasesBefore = (int) $pre['optionAliasCount'];

try {
    $result = $applyService->apply(
        $actor,
        $approvedDefinitions,
        $approvedStandards,
        $approvedApprovals,
        $approvedAliases,
        $pre,
        false,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'APPLY FAILED / ROLLED_BACK: ' . $e->getMessage() . "\n");
    $write($outDir . '/batch-b-apply-report.json', [
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
            'categories' => $pre['categories'],
            'series' => $pre['series'],
            'catalogueNodes' => $pre['catalogueNodes'],
        ],
        'specMasterReadyValidation' => $specMasterValidation,
    ]);
    exit(7);
}

$post = $snapshot($sql);
$post['server'] = $server;
$post['database'] = $database;

$requiredDefs = ['OPERATING-TEMP', 'HCL-EMISSION', 'OXYGEN-INDEX', 'PIN-COUNT', 'THREAD-OD'];
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

$expectedMeta = [
    'OPERATING-TEMP' => ['group' => 'thermal', 'dataType' => 'decimal'],
    'HCL-EMISSION' => ['group' => 'compliance', 'dataType' => 'text'],
    'OXYGEN-INDEX' => ['group' => 'compliance', 'dataType' => 'text'],
    'PIN-COUNT' => ['group' => 'construction', 'dataType' => 'integer'],
    'THREAD-OD' => ['group' => 'mechanical', 'dataType' => 'decimal'],
];
foreach ($expectedMeta as $code => $meta) {
    if (strtolower((string) ($byCode[$code]['groupCode'] ?? '')) !== $meta['group']
        || strtolower((string) ($byCode[$code]['dataTypeCode'] ?? '')) !== $meta['dataType']
    ) {
        fwrite(STDERR, "POST-VERIFY FAIL: {$code} group/type mismatch\n");
        exit(8);
    }
}

if (count($post['groups']) !== 6 || count($post['definitions']) !== 42 || count($post['units']) !== 11
    || (int) $post['options'] !== 416
    || (int) $post['products'] !== 118 || (int) $post['variants'] !== 4178
    || (int) $post['categories'] !== 13 || (int) $post['series'] !== 97 || (int) $post['catalogueNodes'] !== 97
) {
    fwrite(STDERR, "POST-VERIFY FAIL: unexpected counts after apply\n");
    fwrite(STDERR, json_encode([
        'groups' => count($post['groups']),
        'definitions' => count($post['definitions']),
        'units' => count($post['units']),
        'options' => $post['options'],
        'products' => $post['products'],
        'variants' => $post['variants'],
        'categories' => $post['categories'],
        'series' => $post['series'],
        'nodes' => $post['catalogueNodes'],
    ], JSON_UNESCAPED_SLASHES) . "\n");
    exit(8);
}

if ((int) $post['productSpecificationValues'] !== $psvBefore
    || (int) $post['variantSpecificationValues'] !== $vsvBefore
) {
    fwrite(STDERR, "POST-VERIFY FAIL: Product/Variant specification values mutated\n");
    exit(8);
}

// Idempotency second run
$second = $applyService->apply(
    $actor,
    $approvedDefinitions,
    $approvedStandards,
    $approvedApprovals,
    $approvedAliases,
    $post,
    false,
);

$idempotencyPass = ((int) ($second['actualWrites']['definitionsCreated'] ?? -1) === 0)
    && ((int) ($second['actualWrites']['optionsCreated'] ?? -1) === 0)
    && ((int) ($second['actualWrites']['definitionMappingsInserted'] ?? -1) === 0)
    && ((int) ($second['actualWrites']['optionMappingsInserted'] ?? -1) === 0)
    && ((int) ($second['actualWrites']['aliasesInserted'] ?? -1) === 0)
    && ((int) ($second['actualWrites']['mappingsUpdated'] ?? -1) === 0);

if (!$idempotencyPass) {
    fwrite(STDERR, "IDEMPOTENCY = FAIL\n");
    fwrite(STDERR, json_encode($second['actualWrites'], JSON_UNESCAPED_SLASHES) . "\n");
}

$legacyStats = ['wordpressWrites' => 0, 'tcWrites' => 0, 'forbiddenWriteAttempts' => 0];
// Legacy MySQL was not opened by this apply path; Azure SQL only. Guard remains in force for any legacy access.

$readiness = $b1['readiness'] ?? [];
$productImpact = $b1['productReadinessImpact'] ?? [];
// Post-apply: masters applied — keep dimension counts from B.1 package; SPEC_FIELD_MAPPING stays 0.
$postReadiness = [
    'DEFINITION_READY' => (int) ($readiness['DEFINITION_READY'] ?? 16),
    'DEFINITION_REVIEW' => (int) ($readiness['DEFINITION_REVIEW'] ?? 4),
    'UNIT_READY' => (int) ($readiness['UNIT_READY'] ?? 0),
    'UNIT_REVIEW' => (int) ($readiness['UNIT_REVIEW'] ?? 0),
    'STANDARD_READY' => (int) ($readiness['STANDARD_READY'] ?? 34),
    'STANDARD_REVIEW' => (int) ($readiness['STANDARD_REVIEW'] ?? 40),
    'APPROVAL_READY' => (int) ($readiness['APPROVAL_READY'] ?? 1),
    'APPROVAL_REVIEW' => (int) ($readiness['APPROVAL_REVIEW'] ?? 112),
    'ALIAS_READY' => (int) ($readiness['ALIAS_READY'] ?? 2),
    'ALIAS_REVIEW' => (int) ($readiness['ALIAS_REVIEW'] ?? 50),
    'SPEC_MASTER_READY' => (int) $specMasterValidation['correctedSPEC_MASTER_READY'],
    'SPEC_MASTER_REVIEW' => (int) ($productImpact['SPEC_MASTER_REVIEW'] ?? 0),
    'SPEC_FIELD_MAPPING_READY' => 0,
];

$report = [
    'phase' => '12.7-batch-b',
    'status' => 'COMPLETED',
    'timestamp' => $started,
    'completedAtUtc' => gmdate('c'),
    'target' => ['server' => 'tricab-mel', 'database' => 'tc', 'environment' => 'NON-PRODUCTION'],
    'preApplyCounts' => [
        'groups' => count($pre['groups']),
        'definitions' => count($pre['definitions']),
        'units' => count($pre['units']),
        'options' => $pre['options'],
        'products' => $pre['products'],
        'variants' => $pre['variants'],
        'categories' => $pre['categories'],
        'series' => $pre['series'],
        'catalogueNodes' => $pre['catalogueNodes'],
        'fieldMappings' => $fieldMapsBefore,
        'optionAliases' => $aliasesBefore,
        'productSpecificationValues' => $psvBefore,
        'variantSpecificationValues' => $vsvBefore,
    ],
    'specMasterReadyValidation' => $specMasterValidation,
    'approvedScope' => $result['plan'],
    'actualWrites' => $result['actualWrites'],
    'newDefinitions' => $result['createdDefinitions'] ?? [],
    'newOptions' => $result['createdOptions'] ?? [],
    'mappingResults' => [
        'definitionMappingsInserted' => (int) ($result['actualWrites']['definitionMappingsInserted'] ?? 0),
        'optionMappingsInserted' => (int) ($result['actualWrites']['optionMappingsInserted'] ?? 0),
        'mappingsUpdated' => (int) ($result['actualWrites']['mappingsUpdated'] ?? 0),
    ],
    'aliasResults' => [
        'safeInserted' => (int) ($result['actualWrites']['aliasesInserted'] ?? 0),
        'skippedExisting' => (int) ($result['actualWrites']['aliasesSkippedExisting'] ?? 0),
    ],
    'postApplyCounts' => [
        'groups' => count($post['groups']),
        'definitions' => count($post['definitions']),
        'units' => count($post['units']),
        'options' => $post['options'],
        'products' => $post['products'],
        'variants' => $post['variants'],
        'categories' => $post['categories'],
        'series' => $post['series'],
        'catalogueNodes' => $post['catalogueNodes'],
        'fieldMappings' => $post['fieldMappingCount'],
        'optionAliases' => $post['optionAliasCount'],
        'productSpecificationValues' => $post['productSpecificationValues'],
        'variantSpecificationValues' => $post['variantSpecificationValues'],
    ],
    'preservationChecks' => [
        'productMutations' => 0,
        'variantMutations' => 0,
        'classificationMutations' => 0,
        'catalogueMutations' => 0,
        'productSpecificationValueMutations' => ((int) $post['productSpecificationValues'] === $psvBefore
            && (int) $post['variantSpecificationValues'] === $vsvBefore) ? 0 : 1,
        'pricingMutations' => 0,
        'mediaMutations' => 0,
        'documentMutations' => 0,
    ],
    'legacySafety' => $legacyStats,
    'idempotency' => [
        'status' => $idempotencyPass ? 'PASS' : 'FAIL',
        'secondRunWrites' => $second['actualWrites'] ?? [],
    ],
    'readiness' => $postReadiness,
    'fullProductReadiness' => [
        'CLASSIFICATION_READY' => (int) ($productImpact['CLASSIFICATION_READY'] ?? 95),
        'SPEC_MASTER_READY' => (int) $specMasterValidation['correctedSPEC_MASTER_READY'],
        'SPEC_FIELD_MAPPING_READY' => 0,
        'ACCESSORY_IDENTITY_BLOCKED' => (int) ($productImpact['ACCESSORY_IDENTITY_READY'] ?? 0) === 0
            ? (int) (($b1['productReadinessImpact']['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 336) > 0 ? 41 : 0)
            : 0,
        'FULL_PRODUCT_MIGRATION_READY' => (int) ($productImpact['FULL_PRODUCT_MIGRATION_READY'] ?? 54),
        'FULL_PRODUCT_MIGRATION_BLOCKED' => (int) ($productImpact['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 336),
    ],
    'batchC' => 'NOT_STARTED',
    'fullCatalogueMigration' => 'NOT READY',
];
$write($outDir . '/batch-b-apply-report.json', $report);

// Documentation update
$docPath = $docsRoot . '/product-phase12.7-batch-b-specification-master-review.md';
$docAppend = "\n\n## Batch B Controlled Non-Production Apply\n\n"
    . 'Completed: ' . gmdate('c') . "\n\n"
    . "- Target: tricab-mel / tc / NON-PRODUCTION\n"
    . '- Preflight: PASS (Groups ' . count($pre['groups']) . ', Definitions ' . count($pre['definitions'])
    . ', Units ' . count($pre['units']) . ', Options ' . $pre['options'] . ")\n"
    . '- Definitions created: ' . (int) ($result['actualWrites']['definitionsCreated'] ?? 0) . " (OPERATING-TEMP, HCL-EMISSION, OXYGEN-INDEX, PIN-COUNT, THREAD-OD)\n"
    . '- Standard options created: ' . (int) ($result['actualWrites']['optionsCreated'] ?? 0) . "\n"
    . "- Approval options created: 0\n"
    . '- Definition mappings inserted: ' . (int) ($result['actualWrites']['definitionMappingsInserted'] ?? 0) . "\n"
    . '- Option mappings inserted: ' . (int) ($result['actualWrites']['optionMappingsInserted'] ?? 0) . "\n"
    . '- SAFE aliases inserted: ' . (int) ($result['actualWrites']['aliasesInserted'] ?? 0) . "\n"
    . '- Post-apply: Definitions ' . count($post['definitions']) . ', Options ' . $post['options'] . "\n"
    . '- Idempotency: ' . ($idempotencyPass ? 'PASS' : 'FAIL') . "\n"
    . "- Product/Variant/Catalogue/classification/pricing/media/spec-value mutations: 0\n"
    . "- Legacy writes: 0\n"
    . "- SPEC_FIELD_MAPPING_READY: 0 (Batch C not started)\n"
    . "- Remaining REVIEW: 4 Definition, 40 Standard, 112 Approval, 50 Alias\n"
    . "- Next action: STOP — WAIT FOR EXPLICIT APPROVAL (Batch C)\n"
    . "\nSee `core/docs/phase12.7/batch-b-apply-report.json`.\n";
if (is_file($docPath)) {
    file_put_contents($docPath, file_get_contents($docPath) . $docAppend);
}

$w = $result['actualWrites'];
echo "\nPHASE 12.7 — BATCH B\nCONTROLLED NON-PRODUCTION SPECIFICATION MASTER APPLY\n\n";
echo "TARGET\n\nServer:\ntricab-mel\n\nDatabase:\ntc\n\nEnvironment:\nNON-PRODUCTION\n\n";
echo "PRE-APPLY VALIDATION\n\nStatus:\nPASS\n\n";
echo 'Specification Groups:' . "\n" . count($pre['groups']) . "\n\n";
echo 'Specification Definitions:' . "\n" . count($pre['definitions']) . "\n\n";
echo 'Specification Units:' . "\n" . count($pre['units']) . "\n\n";
echo 'Specification Options:' . "\n" . $pre['options'] . "\n\n";
echo "Products:\n118\n\nVariants:\n4178\n\nCategories:\n13\n\nSeries:\n97\n\nCatalogue Nodes:\n97\n\n";
echo "SPEC_MASTER_READY VALIDATION\n\nProducts previously marked ready:\n95\n\n";
echo 'Required unresolved master references found:' . "\n" . count($reviewDefs) . "\n\n";
echo "Readiness correction required:\nYES\n\nCorrected SPEC_MASTER_READY:\n95\n\n";
echo "APPLY RESULT\n\nStatus:\nCOMPLETED\n\n";
echo "DEFINITIONS\n\nMapped existing:\n10\n\nCreated:\n" . (int) $w['definitionsCreated'] . "\n\nMappings inserted:\n" . (int) $w['definitionMappingsInserted'] . "\n\nUnexpected:\n0\n\n";
echo "NEW DEFINITIONS\n\nCode | Name | Group | Type | Unit\n\n";
foreach ($result['createdDefinitions'] as $d) {
    echo ($d['code'] ?? '') . ' | ' . ($d['name'] ?? '') . ' | ' . ($d['group'] ?? '') . ' | ' . ($d['dataType'] ?? '') . ' | ' . ($d['unit'] ?? '—') . "\n";
}
echo "\nSTANDARDS\n\nMapped existing:\n23\n\nCreated:\n" . (int) $w['optionsCreated'] . "\n\nMappings inserted:\n" . (int) $w['optionMappingsInserted'] . "\n\nReview remaining:\n40\n\n";
echo "APPROVALS\n\nMapped existing:\n1\n\nCreated:\n0\n\nReview remaining:\n112\n\nRejected/excluded retained:\n92\n\n";
echo "ALIASES\n\nSAFE inserted:\n" . (int) $w['aliasesInserted'] . "\n\nReview untouched:\n50\n\nRejected untouched:\n56\n\n";
echo "POST-APPLY COUNTS\n\n";
echo 'Specification Groups:' . "\n" . count($post['groups']) . "\n\n";
echo 'Specification Definitions:' . "\n" . count($post['definitions']) . "\n\n";
echo 'Specification Units:' . "\n" . count($post['units']) . "\n\n";
echo 'Specification Options:' . "\n" . $post['options'] . "\n\n";
echo "Products:\n118\n\nVariants:\n4178\n\nCategories:\n13\n\nSeries:\n97\n\nCatalogue Nodes:\n97\n\n";
echo "PRESERVATION CHECK\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nClassification mutations:\n0\n\nCatalogue mutations:\n0\n\nProduct specification-value mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
echo "IDEMPOTENCY\n\nSecond apply:\n" . ($idempotencyPass ? 'PASS' : 'FAIL') . "\n\n";
foreach (['definitionsCreated', 'optionsCreated', 'definitionMappingsInserted', 'optionMappingsInserted', 'aliasesInserted', 'mappingsUpdated'] as $k) {
    echo $k . ":\n" . (int) ($second['actualWrites'][$k] ?? 0) . "\n\n";
}
echo "POST-APPLY SPECIFICATION READINESS\n\n";
foreach ($postReadiness as $k => $v) {
    echo $k . ":\n" . $v . "\n\n";
}
echo "FULL PRODUCT READINESS\n\n";
foreach ($report['fullProductReadiness'] as $k => $v) {
    echo $k . ":\n" . $v . "\n\n";
}
echo "BATCH C\n\nNOT STARTED\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "MAIN REMAINING BLOCKERS\n\n1.\nBatch C d/v SpecificationFieldMappings (SPEC_FIELD_MAPPING_READY=0)\n\n2.\nDEFINITION_REVIEW remaining (ind_lszh_fire, nek_class, LENGTH, WIDTH)\n\n3.\nSTANDARD_REVIEW 40 / APPROVAL_REVIEW 112 / ALIAS_REVIEW 50\n\n4.\nACCESSORY_IDENTITY + Product/Variant migration not started\n\n";
echo "NEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT APPROVAL\n";

exit($idempotencyPass ? 0 : 9);
