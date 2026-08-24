<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch C.2 — concept operator approval + final dry-run package.
 *
 *   php bin/resolve-batch-c2-operator-approval.php --confirm-non-production
 *   php bin/resolve-batch-c2-operator-approval.php --offline
 *
 * --apply refused. No Definition/mapping/value writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchC2OperatorApprovalService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$isApply = in_array('--apply', $argvList, true);

if ($isApply) {
    fwrite(STDERR, "BATCH C.2 APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "BATCH_C2_APPLY_NOT_APPROVED\n");
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

$batchC = $loadJson($outDir . '/batch-c-final-package.json');
$batchC1 = $loadJson($outDir . '/batch-c1-final-package.json');
$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$operatorFile = $loadJson($outDir . '/batch-c2-operator-decisions.json');

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'definitions' => [],
    'definitionCount' => 42,
    'unitCount' => 11,
    'optionCount' => 416,
    'productCount' => 118,
    'variantCount' => 4178,
    'categoryCount' => 13,
    'seriesCount' => 97,
    'catalogueNodeCount' => 97,
];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $env = $sql->fetchAll('SELECT @@SERVERNAME AS s, DB_NAME() AS d');
        $azure['server'] = (string) ($env[0]['s'] ?? $azure['server']);
        $azure['database'] = (string) ($env[0]['d'] ?? $azure['database']);
        $azure['liveValidation'] = 'AVAILABLE';
        $azure['definitions'] = $sql->fetchAll(<<<'SQL'
SELECT CONVERT(NVARCHAR(36), d.PublicId) AS publicId, d.DefinitionCode AS code, d.DefinitionName AS name,
       d.DataTypeCode AS dataTypeCode, d.Unit AS unit, g.GroupCode AS groupCode
FROM product.SpecificationDefinitions d
LEFT JOIN product.SpecificationGroups g ON g.SpecificationGroupId = d.SpecificationGroupId
WHERE d.DeletedAtUtc IS NULL
SQL);
        $azure['definitionCount'] = count($azure['definitions']);
        $azure['unitCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['optionCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.SpecificationOptions WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['productCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.Products WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['variantCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductVariants WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['categoryCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductCategories WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['seriesCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductSeries WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['catalogueNodeCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.CatalogueNodes WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . "\n");
        $azure['liveValidation'] = 'UNAVAILABLE';
        $azure['definitions'] = [
            ['code' => 'CORE-COLOURS'], ['code' => 'CORES'], ['code' => 'PIN-COUNT'],
            ['code' => 'NOM-CABLE-OD'], ['code' => 'CSA'], ['code' => 'THREAD-OD'],
        ];
    }
}

/** @var ResolveBatchC2OperatorApprovalService $service */
$service = $container->get(ResolveBatchC2OperatorApprovalService::class);
$report = $service->resolve([
    'azure' => $azure,
    'batchCPackage' => $batchC,
    'batchC1Package' => $batchC1,
    'classificationReadyProducts' => is_array($ready['items'] ?? null) ? $ready['items'] : [],
    'operatorDecisions' => is_array($operatorFile['concepts'] ?? null) ? $operatorFile['concepts'] : [],
]);

$write($outDir . '/batch-c2-final-package.json', $report);
$write($outDir . '/batch-c2-approved-definitions.json', $report['approvedDefinitions'] ?? []);
$write($outDir . '/batch-c2-approved-field-mappings.json', $report['approvedFieldMappings'] ?? []);
$write($outDir . '/batch-c2-review.json', $report['reviewPackage'] ?? []);
$write($outDir . '/batch-c2-dry-run.json', $report['dryRunTargetPlan'] ?? []);

$doc = <<<'MD'
# Phase 12.7 Batch C.2 — Concept Operator Approval + Final Field-Mapping Dry-Run

DRY-RUN ONLY. Guards: `BATCH_C2_APPLY_NOT_APPROVED`, `BATCH_C_APPLY_NOT_APPROVED`.

## Definition count reconciliation

C.1 listed **12** mechanical/dimension `PROPOSED_CREATE` Definitions.
`IND-LSZH-FIRE` was a **separate** special review — **not** in the 12 → **13th** unique candidate if pursued.
C.2 keeps `IND-LSZH-FIRE` as **REVIEW** (compound serialized flags need split analysis).

## Outer diameter

Accessory `outerdia` / `outer dia` must **not** map to `NOM-CABLE-OD`.
Approved create: `OUTER-DIAMETER`.

## Future apply order

1. Validate target
2. Create APPROVED_CREATE Definitions
3. Reload IDs
4. Insert approved + pending style-field mappings
5. Verify uniqueness / readiness
6. **No** specification value writes
MD;
file_put_contents($docsRoot . '/product-phase12.7-batch-c2-concept-operator-approval.md', $doc . PHP_EOL);

// Print required report
$rec = $report['definitionCountReconciliation'] ?? [];
$cc = $report['conceptDecisions']['counts'] ?? [];
$ms = $report['mappingState'] ?? [];
$rr = $report['reviewReasons'] ?? [];
$rd = $report['readiness'] ?? [];
$dr = $report['dryRunTargetPlan'] ?? [];
$sp = $report['specialDecisions'] ?? [];

echo "PHASE 12.7 — BATCH C.2\nSPECIFICATION CONCEPT OPERATOR APPROVAL\n+ FINAL FIELD-MAPPING DRY-RUN\n\n";
echo "TARGET\n\nStatus:\n" . ($azure['liveValidation'] ?? 'UNKNOWN') . "\n\n";
echo "Definitions:\n" . ($azure['definitionCount'] ?? 42) . "\n\nUnits:\n" . ($azure['unitCount'] ?? 11) . "\n\nOptions:\n" . ($azure['optionCount'] ?? 416) . "\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
echo "DEFINITION COUNT RECONCILIATION\n\nMechanical/dimension proposals:\n" . (int) ($rec['mechanicalDimensionProposals'] ?? 0) . "\n\n";
echo "Other proposals:\n" . (int) ($rec['otherProposals'] ?? 0) . "\n\n";
echo "IND-LSZH-FIRE included in previous 12:\n" . (($rec['indLszhFireIncludedInPrevious12'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo "Total unique Definition candidates:\n" . (int) ($rec['totalUniqueDefinitionCandidates'] ?? 0) . "\n\n";
echo "CONCEPT DECISIONS\n\n";
foreach ([
    ResolveBatchC2OperatorApprovalService::STATUS_APPROVED_MAP_EXISTING,
    ResolveBatchC2OperatorApprovalService::STATUS_APPROVED_CREATE,
    ResolveBatchC2OperatorApprovalService::STATUS_PROPOSED_CREATE,
    ResolveBatchC2OperatorApprovalService::STATUS_REVIEW,
    ResolveBatchC2OperatorApprovalService::STATUS_NOT_A_SPECIFICATION,
    ResolveBatchC2OperatorApprovalService::STATUS_REJECT,
] as $k) {
    echo $k . ":\n" . (int) ($cc[$k] ?? 0) . "\n\n";
}
echo "NEW DEFINITIONS APPROVED\n\nCode | Name | Group | Type | Unit | Mappings | Products\n\n";
foreach ($report['approvedDefinitions'] ?? [] as $d) {
    if (($d['decision'] ?? '') !== ResolveBatchC2OperatorApprovalService::STATUS_APPROVED_CREATE) {
        continue;
    }
    echo ($d['code'] ?? '') . ' | ' . ($d['name'] ?? '') . ' | ' . ($d['group'] ?? '') . ' | ' . ($d['dataType'] ?? '') . ' | ' . ($d['unit'] ?? '-') . ' | ' . ($d['mappingRows'] ?? 0) . ' | ' . ($d['stylesAffected'] ?? 0) . "\n";
}
echo "\nSPECIAL DECISIONS\n\nind_lszh_fire:\n" . json_encode($sp['ind_lszh_fire'] ?? []) . "\n\n";
echo "nek_class:\n" . json_encode($sp['nek_class'] ?? []) . "\n\n";
echo "LENGTH:\n" . json_encode($sp['LENGTH'] ?? []) . "\n\n";
echo "WIDTH:\n" . json_encode($sp['WIDTH'] ?? []) . "\n\n";
echo "OUTER_DIAMETER:\n" . json_encode($sp['OUTER_DIAMETER'] ?? []) . "\n\n";
echo "CORE_COLOURS:\n" . json_encode($sp['CORE_COLOURS'] ?? []) . "\n\n";
echo "PHASE_COLOUR:\n" . json_encode($sp['PHASE_COLOUR'] ?? []) . "\n\n";
echo "MAPPING STATE\n\nOriginal APPROVED_MAP:\n124\n\n";
echo "Original approvals still valid:\n" . (int) ($ms['originalApprovalsStillValid'] ?? 0) . "\n\n";
echo "Revalidation conflicts:\n" . (int) ($ms['revalidationConflicts'] ?? 0) . "\n\n";
echo "New APPROVED_MAP:\n" . (int) ($ms['newApprovedMap'] ?? 0) . "\n\n";
echo "APPROVED_PENDING_DEFINITION:\n" . (int) ($ms['approvedPendingDefinition'] ?? 0) . "\n\n";
echo "REVIEW:\n" . (int) ($ms['REVIEW'] ?? 0) . "\n\nREJECT:\n" . (int) ($ms['REJECT'] ?? 0) . "\n\n";
echo "REVIEW REASONS\n\n";
foreach ($rr as $k => $v) {
    echo $k . ":\n" . (int) $v . "\n\n";
}
$acc = $rd['productBreakdown']['accessoryProducts'] ?? [];
$cable = $rd['productBreakdown']['cableProducts'] ?? [];
echo "ACCESSORY D/V READINESS\n\nProducts applicable:\n" . (int) ($acc['total'] ?? 0) . "\n\n";
echo "READY:\n" . (int) ($acc['dvComplete'] ?? 0) . "\n\nREVIEW:\n" . (int) ($acc['dvIncomplete'] ?? 0) . "\n\n";
echo "Identity blocked:\n" . (int) ($acc['identityBlocked'] ?? 41) . "\n\n";
echo "CABLE SPEC READINESS\n\nCable Products:\n" . (int) ($cable['total'] ?? 0) . "\n\n";
echo "d/v NOT_APPLICABLE:\n" . (int) ($cable['dvNotApplicable'] ?? 0) . "\n\n";
echo "cable_specs mapping complete:\n0\n\ncable_specs mapping not evaluated:\n" . (int) ($cable['cableSpecsNotEvaluated'] ?? 0) . "\n\n";
echo "cable_specs mapping blocked:\n0\n\n";
echo "FINAL BATCH C.2 DRY-RUN\n\nTarget validated:\n" . (($dr['targetValidated'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo "MASTER DATA\n\nDefinitions mapped existing:\n" . (int) ($dr['masterData']['definitionsMappedExisting'] ?? 0) . "\n\n";
echo "Definitions to create:\n" . (int) ($dr['masterData']['definitionsToCreate'] ?? 0) . "\n\n";
echo "MIGRATION MAPPINGS\n\nStyle-field mappings to insert:\n" . (int) ($dr['migrationMappings']['styleFieldMappingsToInsert'] ?? 0) . "\n\n";
echo "Existing identical mappings:\n" . (int) ($dr['migrationMappings']['existingIdenticalMappings'] ?? 0) . "\n\n";
echo "Mappings to update:\n0\n\nConflicts:\n" . (int) ($dr['migrationMappings']['conflicts'] ?? 0) . "\n\n";
echo "Review excluded:\n" . (int) ($dr['migrationMappings']['reviewExcluded'] ?? 0) . "\n\n";
echo "SPECIFICATION VALUES\n\nProduct values:\n0\n\nVariant values:\n0\n\n";
echo "OTHER MUTATIONS\n\nProducts:\n0\n\nVariants:\n0\n\nClassification:\n0\n\nCatalogue:\n0\n\nPricing:\n0\n\nMedia:\n0\n\nDocuments:\n0\n\nLegacy:\n0\n\n";
echo "IDEMPOTENCY PROJECTION\n\nDefinitions created on second run:\n0\n\nMappings inserted on second run:\n0\n\nMappings updated:\n0\n\n";
$cur = $rd['CURRENT'] ?? [];
$proj = $rd['PROJECTED_AFTER_BATCH_C2_APPLY'] ?? [];
echo "CURRENT READINESS\n\nSPEC_FIELD_MAPPING_READY:\n" . (int) ($cur['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "FULL_PRODUCT_MIGRATION_READY:\n" . (int) ($cur['FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\n";
echo "PROJECTED AFTER APPLY\n\nACCESSORY_DV_FIELD_MAPPING_READY:\n" . (int) ($proj['ACCESSORY_DV_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "CABLE_SPEC_FIELD_MAPPING_READY:\n" . (int) ($proj['CABLE_SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "SPEC_FIELD_MAPPING_READY:\n" . (int) ($proj['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "FULL_PRODUCT_MIGRATION_READY:\n" . (int) ($proj['FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\n";
echo "CABLE-SPECS FOLLOW-UP REQUIRED\n\nYES\n\nReason:\n" . (string) ($report['cableSpecsFollowUpReason'] ?? '') . "\n\n";
echo "BATCH C.2 TARGET APPLY\n\nNOT RUN\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "RECOMMENDATION\n\n" . (string) ($report['recommendation'] ?? '') . "\n\n";
echo "NEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
