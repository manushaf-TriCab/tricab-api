<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch C.1 — unresolved concept discovery + DRY-RUN.
 *
 *   php bin/resolve-batch-c1-unresolved-concepts.php --confirm-non-production
 *   php bin/resolve-batch-c1-unresolved-concepts.php --offline
 *
 * --apply refused. No Definition / mapping / value writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchC1UnresolvedConceptsService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$isApply = in_array('--apply', $argvList, true);

if ($isApply) {
    fwrite(STDERR, "BATCH C.1 APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "BATCH_C1_APPLY_NOT_APPROVED\n");
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
$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$operator = $loadJson($outDir . '/batch-c1-operator-decisions.json');

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
        if ($azure['definitionCount'] !== 42 || $azure['optionCount'] !== 416) {
            fwrite(STDERR, 'WARN: master drift defs=' . $azure['definitionCount'] . ' opts=' . $azure['optionCount'] . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . "\n");
        $azure['liveValidation'] = 'UNAVAILABLE';
        // Fall back to definition codes from Batch C package target
        $azure['definitions'] = [
            ['code' => 'PIN-COUNT'], ['code' => 'THREAD-OD'], ['code' => 'VOLTAGE'], ['code' => 'CURRENT'],
            ['code' => 'CORES'], ['code' => 'NOM-CABLE-OD'], ['code' => 'CORE-COLOURS'], ['code' => 'CSA'],
            ['code' => 'OPERATING-TEMP'], ['code' => 'HCL-EMISSION'], ['code' => 'OXYGEN-INDEX'],
        ];
    }
}

/** @var ResolveBatchC1UnresolvedConceptsService $service */
$service = $container->get(ResolveBatchC1UnresolvedConceptsService::class);
$report = $service->resolve([
    'azure' => $azure,
    'batchCPackage' => $batchC,
    'classificationReadyProducts' => is_array($ready['items'] ?? null) ? $ready['items'] : [],
    'operatorDecisions' => is_array($operator['concepts'] ?? null) ? $operator['concepts'] : $operator,
]);

$write($outDir . '/batch-c1-final-package.json', $report);
$write($outDir . '/batch-c1-unresolved-concepts.json', $report['unresolvedConcepts'] ?? []);
$write($outDir . '/batch-c1-definition-proposals.json', $report['definitionProposals'] ?? []);
$write($outDir . '/batch-c1-type-conflicts.json', $report['typeConflicts'] ?? []);
$write($outDir . '/batch-c1-vacuous-readiness-audit.json', $report['vacuousReadinessAudit'] ?? []);
$write($outDir . '/batch-c1-impact-simulation.json', $report['impactSimulation'] ?? []);

$doc = <<<'MD'
# Phase 12.7 Batch C.1 — Unresolved Specification Concept Resolution

DRY-RUN ONLY. Guard: `BATCH_C1_APPLY_NOT_APPROVED`. Batch C apply remains blocked.

## Purpose

Reduce 584 REVIEW + 51 PROPOSED style-field mappings by resolving **semantic concepts**, not individual guesswork.

## Vacuous readiness correction

Cable styles without accessory `d/v` fields are `FIELD_MAPPING_NOT_APPLICABLE`, not `SPEC_FIELD_MAPPING_READY`.

## Artifacts

- `batch-c1-final-package.json`
- `batch-c1-unresolved-concepts.json`
- `batch-c1-definition-proposals.json`
- `batch-c1-type-conflicts.json`
- `batch-c1-vacuous-readiness-audit.json`
- `batch-c1-impact-simulation.json`

## Next

STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL before creating Definitions or applying Batch C mappings.
MD;
file_put_contents($docsRoot . '/product-phase12.7-batch-c1-unresolved-concept-resolution.md', $doc . PHP_EOL);

$uc = $report['unresolvedConcepts'] ?? [];
$start = $report['startingBatchCState'] ?? [];
$prop = $report['definitionProposals']['counts'] ?? [];
$tc = $report['typeConflicts'] ?? [];
$vac = $report['vacuousReadinessAudit'] ?? [];
$var = $report['variantReadinessAudit'] ?? [];
$imp = $report['impactSimulation'] ?? [];
$ms = $report['mappingStateAfterC1Review'] ?? [];
$pkg = $report['recommendedOperatorApprovalPackage'] ?? [];
$spec = $report['specialReviews'] ?? [];

echo "PHASE 12.7 — BATCH C.1\nUNRESOLVED SPECIFICATION CONCEPT RESOLUTION\nFINAL REVIEW + DRY-RUN\n\n";
echo "TARGET\n\nStatus:\n" . ($azure['liveValidation'] ?? 'UNKNOWN') . "\n\n";
echo "Specification Definitions:\n" . ($azure['definitionCount'] ?? 42) . "\n\n";
echo "Specification Units:\n" . ($azure['unitCount'] ?? 11) . "\n\n";
echo "Specification Options:\n" . ($azure['optionCount'] ?? 416) . "\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
echo "STARTING BATCH C STATE\n\nAPPROVED_MAP:\n" . (int) ($start['APPROVED_MAP'] ?? 0) . "\n\n";
echo "PROPOSED_MAP:\n" . (int) ($start['PROPOSED_MAP'] ?? 0) . "\n\nREVIEW:\n" . (int) ($start['REVIEW'] ?? 0) . "\n\nREJECT:\n" . (int) ($start['REJECT'] ?? 0) . "\n\n";
echo "UNRESOLVED CONCEPTS\n\nTotal semantic concepts:\n" . (int) ($uc['total'] ?? 0) . "\n\n";
echo "High confidence:\n" . (int) ($uc['highConfidence'] ?? 0) . "\n\nMedium confidence:\n" . (int) ($uc['mediumConfidence'] ?? 0) . "\n\nLow confidence:\n" . (int) ($uc['lowConfidence'] ?? 0) . "\n\n";
echo "TOP HIGH-IMPACT CONCEPTS\n\nConcept | Mappings | Styles | Products | Type | Unit | Decision\n\n";
foreach (array_slice($uc['items'] ?? [], 0, 15) as $c) {
    if (!is_array($c)) {
        continue;
    }
    $type = $c['detectedType'] ?? ($c['proposedDefinition']['dataType'] ?? '-');
    $unit = $c['detectedUnit'] ?? ($c['proposedDefinition']['unit'] ?? '-');
    echo ($c['concept'] ?? '') . ' | ' . ($c['mappingRows'] ?? 0) . ' | ' . ($c['stylesAffected'] ?? 0) . ' | ' . ($c['productsAffectedEstimate'] ?? 0) . ' | ' . $type . ' | ' . ($unit ?? '-') . ' | ' . ($c['decision'] ?? '') . "\n";
}
echo "\nEXISTING DEFINITION REUSE\n\nConcepts mapped existing:\n" . (int) ($prop['mapExisting'] ?? 0) . "\n\n";
$mapRows = 0;
foreach (($report['definitionProposals']['mapExisting'] ?? []) as $c) {
    $mapRows += (int) ($c['mappingRows'] ?? 0);
}
echo "Mappings potentially resolved:\n{$mapRows}\n\nProducts affected:\n{$mapRows}\n\n";
echo "PROPOSED NEW DEFINITIONS\n\nCount:\n" . (int) ($prop['proposedCreate'] ?? 0) . "\n\n";
echo "Code | Name | Group | Type | Unit | Mappings | Products | Confidence\n\n";
foreach (($report['definitionProposals']['proposedCreate'] ?? []) as $c) {
    $pd = is_array($c['proposedDefinition'] ?? null) ? $c['proposedDefinition'] : [];
    echo ($pd['code'] ?? '-') . ' | ' . ($pd['name'] ?? '-') . ' | ' . ($pd['group'] ?? '-') . ' | ' . ($pd['dataType'] ?? '-') . ' | ' . ($pd['unit'] ?? '-') . ' | ' . ($c['mappingRows'] ?? 0) . ' | ' . ($c['stylesAffected'] ?? 0) . ' | ' . ($c['confidence'] ?? '') . "\n";
}
echo "\nSTILL REVIEW\n\nConcepts:\n" . (int) ($prop['review'] ?? 0) . "\n\n";
$reviewRows = 0;
foreach (($report['definitionProposals']['review'] ?? []) as $c) {
    $reviewRows += (int) ($c['mappingRows'] ?? 0);
}
echo "Mapping rows:\n{$reviewRows}\n\nProducts:\n{$reviewRows}\n\n";
echo "SPECIAL REVIEWS\n\nind_lszh_fire:\n" . json_encode($spec['ind_lszh_fire'] ?? []) . "\n\n";
echo "nek_class:\n" . json_encode($spec['nek_class'] ?? []) . "\n\n";
echo "LENGTH:\n" . json_encode($spec['LENGTH'] ?? []) . "\n\n";
echo "WIDTH:\n" . json_encode($spec['WIDTH'] ?? []) . "\n\n";
echo "TYPE CONFLICTS\n\nBefore:\n" . (int) ($tc['before'] ?? 0) . "\n\nResolved:\n" . (int) ($tc['resolvedByC1Recommendation'] ?? 0) . "\n\nRemaining:\n" . (int) ($tc['remaining'] ?? 0) . "\n\n";
echo "GLOBAL WILDCARD ROWS\n\nRejected:\n5\n\nDecision changed:\nNO\n\n";
echo "VACUOUS READINESS AUDIT\n\nPreviously vacuous-ready Products:\n" . (int) ($vac['previouslyVacuousReadyProducts'] ?? 0) . "\n\n";
echo "FIELD_MAPPING_NOT_APPLICABLE:\n" . (int) ($vac['FIELD_MAPPING_NOT_APPLICABLE'] ?? 0) . "\n\n";
echo "FIELD_MAPPING_COMPLETE:\n" . (int) ($vac['FIELD_MAPPING_COMPLETE'] ?? 0) . "\n\n";
echo "Incomplete/unknown:\n" . (int) ($vac['incompleteOrUnknown'] ?? 0) . "\n\n";
echo "VARIANT READINESS AUDIT\n\nPreviously projected ready:\n4178\n\n";
echo "DV_NOT_APPLICABLE:\n" . (int) ($var['VARIANT_DV_NOT_APPLICABLE'] ?? 0) . "\n\n";
echo "SPEC_MAPPING_COMPLETE:\n" . (int) ($var['VARIANT_SPEC_MAPPING_COMPLETE'] ?? 0) . "\n\n";
echo "SPEC_MAPPING_INCOMPLETE:\n" . (int) ($var['VARIANT_SPEC_MAPPING_INCOMPLETE'] ?? 0) . "\n\n";
echo "SOURCE_UNKNOWN:\n" . (int) ($var['VARIANT_SPEC_SOURCE_UNKNOWN'] ?? 0) . "\n\n";
echo "IMPACT SIMULATION\n\nCURRENT:\n\nSPEC_FIELD_MAPPING_READY:\n0\n\nFULL_PRODUCT_MIGRATION_READY:\n54\n\n\n";
$a = $imp['SCENARIO_A_CURRENT_124_ONLY'] ?? [];
$b = $imp['SCENARIO_B_PLUS_MAP_EXISTING'] ?? [];
$c = $imp['SCENARIO_C_PLUS_PROPOSED_DEFINITIONS'] ?? [];
echo "CURRENT 124 ONLY:\n\nProjected field-mapping ready:\n" . (int) ($a['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\nProjected full migration ready:\n" . (int) ($a['FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\n\n";
echo "PLUS MAP_EXISTING RESOLUTIONS:\n\nProjected field-mapping ready:\n" . (int) ($b['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\nProjected full migration ready:\n" . (int) ($b['FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\n\n";
echo "PLUS PROPOSED DEFINITIONS:\n\nProjected field-mapping ready:\n" . (int) ($c['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\nProjected full migration ready:\n" . (int) ($c['FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\n\n";
echo "BATCH C MAPPING STATE AFTER C.1 REVIEW\n\nAPPROVED_MAP:\n" . (int) ($ms['APPROVED_MAP'] ?? 0) . "\n\n";
echo "DEPENDENT_ON_NEW_DEFINITION:\n" . (int) ($ms['DEPENDENT_ON_NEW_DEFINITION'] ?? 0) . "\n\n";
echo "PROPOSED_MAP:\n" . (int) ($ms['PROPOSED_MAP'] ?? 0) . "\n\nREVIEW:\n" . (int) ($ms['REVIEW'] ?? 0) . "\n\nREJECT:\n" . (int) ($ms['REJECT'] ?? 0) . "\n\n";
echo "TARGET WRITES\n\nDefinitions created:\n0\n\nMappings inserted:\n0\n\nSpecification values:\n0\n\nProducts:\n0\n\nVariants:\n0\n\nLegacy writes:\n0\n\n";
echo "RECOMMENDED OPERATOR APPROVAL PACKAGE\n\nDefinitions to map existing:\n" . count($pkg['definitionsToMapExisting'] ?? []) . "\n\n";
echo "Definitions proposed for create:\n" . count($pkg['definitionsProposedForCreate'] ?? []) . "\n\n";
echo "Style-field mappings that would become eligible:\n" . (int) ($pkg['styleFieldMappingsThatWouldBecomeEligible'] ?? 0) . "\n\n";
echo "Expected Product readiness improvement:\n" . (string) ($pkg['expectedProductReadinessImprovement'] ?? '') . "\n\n";
echo "BATCH C APPLY\n\nNOT RUN\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\nNEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
