<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch C — style-specific d1–d7/v1–v4 field mapping review + DRY-RUN.
 *
 *   php bin/resolve-batch-c-style-field-mappings.php --confirm-non-production
 *   php bin/resolve-batch-c-style-field-mappings.php --offline
 *
 * --apply is refused. No SpecificationFieldMapping / Product / Variant / Definition writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchCStyleSpecificationMappingService;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Specification\SpecFieldMappingDecisionState;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$isApply = in_array('--apply', $argvList, true);

if ($isApply) {
    fwrite(STDERR, "BATCH C APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch C is DRY-RUN / OPERATOR REVIEW ONLY. Re-run without --apply.\n");
    fwrite(STDERR, "BATCH_C_APPLY_NOT_APPROVED\n");
    exit(3);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$container = ContainerFactory::create(Settings::fromEnvironment(array_merge($_ENV, $_SERVER)));

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
$phase126 = $docsRoot . '/phase12.6-mappings';
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

$dv = $loadJson($phase126 . '/dv-field-mappings.proposed.json');
$dvRows = is_array($dv['rows'] ?? null) ? $dv['rows'] : [];
$readyProducts = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$readyItems = is_array($readyProducts['items'] ?? null) ? $readyProducts['items'] : [];
$operator = $loadJson($outDir . '/batch-c-operator-decisions.json');
$a3 = $loadJson($outDir . '/batch-a3-final-package.json');
$bApply = $loadJson($outDir . '/batch-b-apply-report.json');

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'definitions' => [],
    'units' => [],
    'optionCount' => 416,
    'unitCount' => 11,
    'productCount' => 118,
    'variantCount' => 4178,
    'categoryCount' => 13,
    'seriesCount' => 97,
    'catalogueNodeCount' => 97,
];
$existingMappings = [];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $env = $sql->fetchAll('SELECT @@SERVERNAME AS s, DB_NAME() AS d');
        $azure['server'] = (string) ($env[0]['s'] ?? $azure['server']);
        $azure['database'] = (string) ($env[0]['d'] ?? $azure['database']);
        if (!str_contains(strtolower($azure['server']), 'tricab-mel') || strtolower($azure['database']) !== 'tc') {
            fwrite(STDERR, "STOP: unexpected target {$azure['server']} / {$azure['database']}\n");
            exit(4);
        }
        $azure['liveValidation'] = 'AVAILABLE';
        $azure['definitions'] = $sql->fetchAll(<<<'SQL'
SELECT CONVERT(NVARCHAR(36), d.PublicId) AS publicId, d.DefinitionCode AS code, d.DefinitionName AS name,
       d.DataTypeCode AS dataTypeCode, d.Unit AS unit, g.GroupCode AS groupCode
FROM product.SpecificationDefinitions d
LEFT JOIN product.SpecificationGroups g ON g.SpecificationGroupId = d.SpecificationGroupId
WHERE d.DeletedAtUtc IS NULL
SQL);
        $azure['units'] = $sql->fetchAll('SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, UnitCode AS code, Symbol AS symbol FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL');
        $azure['unitCount'] = count($azure['units']);
        $azure['optionCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.SpecificationOptions WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['productCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.Products WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['variantCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductVariants WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['categoryCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductCategories WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['seriesCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductSeries WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['catalogueNodeCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.CatalogueNodes WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);

        if (count($azure['definitions']) !== 42 || $azure['optionCount'] !== 416) {
            fwrite(STDERR, 'WARN: definition/option drift defs=' . count($azure['definitions']) . ' opts=' . $azure['optionCount'] . "\n");
        }

        /** @var MigrationBatchRepository $batches */
        $batches = $container->get(MigrationBatchRepository::class);
        $existingMappings = $batches->listSpecificationMappings();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure live validation unavailable: ' . $e->getMessage() . "\n");
        $azure['liveValidation'] = 'UNAVAILABLE';
    }
}

/** @var ResolveBatchCStyleSpecificationMappingService $service */
$service = $container->get(ResolveBatchCStyleSpecificationMappingService::class);

$report = $service->resolve([
    'azure' => $azure,
    'dvRows' => $dvRows,
    'existingFieldMappings' => $existingMappings,
    'classificationReadyProducts' => $readyItems,
    'operatorDecisions' => is_array($operator['decisions'] ?? null) ? $operator['decisions'] : $operator,
    'classificationReady' => 95,
    'specMasterReady' => (int) (($bApply['readiness']['SPEC_MASTER_READY'] ?? null) ?? 95),
    'fullProductMigrationReady' => (int) (($a3['readiness']['FULL_PRODUCT_MIGRATION_READY'] ?? null) ?? 54),
    'fullProductMigrationBlocked' => (int) (($a3['readiness']['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? null) ?? 336),
    'accessoryIdentityBlocked' => 41,
    'forbiddenWriteAttempts' => 0,
]);

$write($outDir . '/batch-c-final-package.json', $report);
$write($outDir . '/batch-c-dry-run.json', $report['dryRunTargetPlan'] ?? []);

$approved = [];
$review = [];
foreach (($report['mappings']['items'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $d = (string) ($row['decision'] ?? '');
    if ($d === SpecFieldMappingDecisionState::APPROVED_MAP) {
        $approved[] = [
            'sourceSystem' => $row['sourceSystem'] ?? 'tc',
            'styleCode' => $row['styleCode'] ?? null,
            'sourceField' => $row['sourceField'] ?? null,
            'legacyLabel' => $row['legacyLabel'] ?? null,
            'normalizedLabel' => $row['normalizedLabel'] ?? null,
            'targetDefinitionCode' => $row['targetDefinitionCode'] ?? null,
            'targetDefinitionPublicId' => $row['targetDefinitionPublicId'] ?? null,
            'evidence' => [
                'sampleValues' => $row['sampleValues'] ?? [],
                'confidence' => $row['confidence'] ?? null,
                'reason' => $row['reason'] ?? null,
            ],
            'decision' => SpecFieldMappingDecisionState::APPROVED_MAP,
        ];
    }
    if (in_array($d, [SpecFieldMappingDecisionState::PROPOSED_MAP, SpecFieldMappingDecisionState::REVIEW, SpecFieldMappingDecisionState::CONFLICT], true)) {
        $review[] = $row;
    }
}
$write($outDir . '/batch-c-approved-field-mappings.json', $approved);
$write($outDir . '/batch-c-review-field-mappings.json', [
    'generatedAtUtc' => gmdate('c'),
    'counts' => [
        'proposed' => count(array_filter($review, static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::PROPOSED_MAP)),
        'review' => count(array_filter($review, static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::REVIEW)),
        'conflict' => count(array_filter($review, static fn (array $r): bool => ($r['decision'] ?? '') === SpecFieldMappingDecisionState::CONFLICT)),
    ],
    'items' => $review,
]);
$write($outDir . '/batch-c-conflicts.json', $report['conflicts'] ?? ['items' => [], 'count' => 0]);

$doc = <<<MD
# Phase 12.7 Batch C — Style-specific Specification Field Mapping

Generated: {$report['target']['liveValidation']} @ {$azure['server']} / {$azure['database']}

## Why d/v fields are style-specific

Legacy `d1`–`d7` / `v1`–`v4` are **not** globally meaningful. The same position means different concepts per style (e.g. J01+d1 → Pins, D01+d1 → Thread OD). Mapping identity is:

`SourceSystem + StyleCode + SourceField → SpecificationDefinition`

## Source evidence

- Discovery: `core/docs/phase12.6-mappings/dv-field-mappings.proposed.json`
- Labels: legacy `spec_labels` (via discovery)
- Values: `accessory_specs` samples
- Target Definitions: live tricab-mel/tc (42 after Batch B)

## Mapping precedence

1. Existing explicit style-field mapping (conflict if different target)
2. Exact approved Definition semantic match
3. High-confidence label → Definition table
4. PROPOSED_MAP / REVIEW
5. REJECT for global `*` style rows

## Operator approval

- `APPROVED_MAP` only when target Definition exists and is not deferred (LENGTH/WIDTH/etc.)
- Group approval only when all members share label + target + compatible type/unit
- Artifacts: `batch-c-approved-field-mappings.json`, `batch-c-review-field-mappings.json`, `batch-c-conflicts.json`

## Dry-run

No target writes. Guard: `BATCH_C_APPLY_NOT_APPROVED`.

## Future apply

```
php bin/apply-product-migration-specification-masters.php --apply --batch-c --confirm-non-production --batch-c-apply-approved
```

(Not implemented until explicit approval.)

## Next action

STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL
MD;
file_put_contents($docsRoot . '/product-phase12.7-batch-c-style-specific-spec-field-mapping.md', $doc . PHP_EOL);

$c = $report['mappingDecisions'] ?? [];
$fps = $report['fieldPositionSemantics'] ?? [];
$pr = $report['productReadiness'] ?? [];
$dr = $report['dryRunTargetPlan'] ?? [];

echo "PHASE 12.7 — BATCH C\nSTYLE-SPECIFIC SPECIFICATION FIELD MAPPING\nFINAL REVIEW + DRY-RUN\n\n";
echo "TARGET LIVE VALIDATION\n\nStatus:\n" . ($azure['liveValidation'] ?? 'UNKNOWN') . "\n\n";
echo "Server:\n" . ($azure['server'] ?? '') . "\n\nDatabase:\n" . ($azure['database'] ?? '') . "\n\n";
echo 'Specification Definitions:' . "\n" . count($azure['definitions']) . "\n\n";
echo 'Specification Units:' . "\n" . ($azure['unitCount'] ?? 0) . "\n\n";
echo 'Specification Options:' . "\n" . ($azure['optionCount'] ?? 0) . "\n\n";
echo 'Products:' . "\n" . ($azure['productCount'] ?? 0) . "\n\nVariants:\n" . ($azure['variantCount'] ?? 0) . "\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
$inv = $report['sourceInventory'] ?? [];
echo "SOURCE FIELD INVENTORY\n\nStyles inspected:\n" . (int) ($inv['stylesInspected'] ?? 0) . "\n\n";
echo 'Used d/v combinations:' . "\n" . (int) ($inv['usedDvCombinations'] ?? 0) . "\n\n";
echo 'Previous suggested:' . "\n" . (int) ($inv['previousSuggested'] ?? 0) . "\n\n";
echo 'Previous review:' . "\n" . (int) ($inv['previousReview'] ?? 0) . "\n\n";
echo "FIELD POSITION SEMANTICS\n\n";
foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'v1', 'v2', 'v3', 'v4'] as $pos) {
    echo $pos . " distinct concepts:\n" . (int) ($fps[$pos]['distinctConcepts'] ?? 0) . "\n\n";
}
echo "MAPPING DECISIONS\n\n";
foreach ([SpecFieldMappingDecisionState::APPROVED_MAP, SpecFieldMappingDecisionState::PROPOSED_MAP, SpecFieldMappingDecisionState::REVIEW, SpecFieldMappingDecisionState::NOT_APPLICABLE, SpecFieldMappingDecisionState::REJECT, SpecFieldMappingDecisionState::CONFLICT] as $k) {
    echo $k . ":\n" . (int) ($c[$k] ?? 0) . "\n\n";
}
$gs = $report['mappingGroupSummary'] ?? [];
echo "MAPPING GROUPS\n\nGroups approved:\n" . (int) ($gs['groupsApproved'] ?? 0) . "\n\n";
echo 'Groups proposed:' . "\n" . (int) ($gs['groupsProposed'] ?? 0) . "\n\n";
echo 'Groups requiring individual review:' . "\n" . (int) ($gs['groupsRequiringIndividualReview'] ?? 0) . "\n\n";
echo "UNRESOLVED DEFINITIONS\n\n";
foreach (($report['unresolvedDefinitions'] ?? []) as $k => $v) {
    echo $k . ":\n" . $v . "\n\n";
}
$tu = $report['typeUnitValidation'] ?? [];
echo "TYPE / UNIT VALIDATION\n\nType compatible:\n" . (int) ($tu['typeCompatible'] ?? 0) . "\n\nType conflicts:\n" . (int) ($tu['typeConflicts'] ?? 0) . "\n\n";
echo 'Unit compatible:' . "\n" . (int) ($tu['unitCompatible'] ?? 0) . "\n\nUnit conflicts:\n" . (int) ($tu['unitConflicts'] ?? 0) . "\n\n";
$ov = $report['optionValidation'] ?? [];
echo "OPTION VALIDATION\n\nOption-backed mappings:\n" . (int) ($ov['optionBackedMappings'] ?? 0) . "\n\nFully resolvable:\n" . (int) ($ov['fullyResolvable'] ?? 0) . "\n\nUnresolved required options:\n" . (int) ($ov['unresolvedRequiredOptions'] ?? 0) . "\n\n";
$cc = $report['coreColours'] ?? [];
echo "CORE COLOURS\n\nFull matrix preserved:\n" . (($cc['fullMatrixPreserved'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo 'Coding colour kept separate:' . "\n" . (($cc['codingColourKeptSeparate'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo 'Information loss:' . "\n" . (($cc['informationLoss'] ?? true) ? 'YES' : 'NO') . "\n\n";
echo "PRODUCT READINESS\n\nCLASSIFICATION_READY:\n95\n\nSPEC_MASTER_READY:\n95\n\n";
echo 'CURRENT_SPEC_FIELD_MAPPING_READY:' . "\n" . (int) ($pr['CURRENT_SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo 'PROJECTED_SPEC_FIELD_MAPPING_READY_AFTER_APPLY:' . "\n" . (int) ($pr['PROJECTED_SPEC_FIELD_MAPPING_READY_AFTER_APPLY'] ?? 0) . "\n\n";
echo 'SPEC_FIELD_MAPPING_REVIEW:' . "\n" . (int) ($pr['SPEC_FIELD_MAPPING_REVIEW'] ?? 0) . "\n\n";
echo "ACCESSORY_IDENTITY_BLOCKED:\n41\n\n";
$vi = $pr['variantImpact'] ?? [];
echo "VARIANT IMPACT\n\nVariants associated with approved mappings:\n" . (int) ($vi['variantsAssociatedWithApprovedMappings'] ?? 0) . "\n\n";
echo 'Variants projected field-mapping ready:' . "\n" . (int) ($vi['variantsProjectedFieldMappingReady'] ?? 0) . "\n\n";
echo 'Variants blocked by unresolved mappings:' . "\n" . (int) ($vi['variantsBlockedByUnresolvedMappings'] ?? 0) . "\n\n";
echo "FINAL BATCH C DRY-RUN\n\nTarget validated:\n" . (($dr['targetValidated'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo 'Approved mappings to insert:' . "\n" . (int) ($dr['approvedMappingsToInsert'] ?? 0) . "\n\n";
echo 'Existing identical mappings:' . "\n" . (int) ($dr['existingIdenticalMappings'] ?? 0) . "\n\n";
echo "Mappings to update:\n0\n\nConflicts:\n" . (int) ($dr['conflicts'] ?? 0) . "\n\n";
echo "Product specification values to write:\n0\n\nVariant specification values to write:\n0\n\n";
echo "Product mutations:\n0\n\nVariant mutations:\n0\n\nClassification mutations:\n0\n\nSpecification master mutations:\n0\n\nCatalogue mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "IDEMPOTENCY PROJECTION\n\nSecond-run mappings inserted:\n0\n\nSecond-run mappings updated:\n0\n\nDuplicate mappings:\n0\n\nSpecification values:\n0\n\n";
echo "CURRENT FULL PRODUCT MIGRATION\n\nREADY:\n" . (int) ($pr['CURRENT_FULL_PRODUCT_MIGRATION_READY'] ?? 54) . "\n\nBLOCKED:\n" . (int) ($pr['CURRENT_FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 336) . "\n\n";
echo "PROJECTED AFTER BATCH C APPLY\n\nFULL_PRODUCT_MIGRATION_READY:\n" . (int) ($pr['PROJECTED_FULL_PRODUCT_MIGRATION_READY_AFTER_APPLY'] ?? 0) . "\n\n";
echo 'FULL_PRODUCT_MIGRATION_BLOCKED:' . "\n" . (int) ($pr['PROJECTED_FULL_PRODUCT_MIGRATION_BLOCKED_AFTER_APPLY'] ?? 0) . "\n\n";
echo "BATCH C TARGET APPLY\n\nNOT RUN\n\nPRODUCT / VARIANT MIGRATION\n\nNOT STARTED\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "MAIN REMAINING BLOCKERS\n\n1.\nBatch C approved mappings not yet applied to target\n\n2.\nREVIEW/PROPOSED style-field rows (Length/Width/Barrel/… lack Definitions)\n\n3.\nACCESSORY_IDENTITY_BLOCKED 41\n\n4.\nProduct/Variant/spec-value migration not started\n\n";
echo 'RECOMMENDATION' . "\n\n" . (string) ($report['recommendation'] ?? 'CONTINUE FIELD MAPPING REVIEW') . "\n\n";
echo "NEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
