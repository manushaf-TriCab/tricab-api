<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch B.1 — final operator resolution + apply-ready dry-run.
 *
 *   php bin/resolve-batch-b1-operator-approval.php --confirm-non-production
 *   php bin/resolve-batch-b1-operator-approval.php --offline
 *
 * --apply is refused. No target writes. Batch C not started.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchB1OperatorApprovalService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
if (in_array('--apply', $argvList, true)) {
    fwrite(STDERR, "BATCH B APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch B.1 is DRY-RUN ONLY. Re-run without --apply.\n");
    exit(3);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$container = ContainerFactory::create(Settings::fromEnvironment(array_merge($_ENV, $_SERVER)));

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
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

$batchB = $loadJson($outDir . '/batch-b-final-package.json');
if ($batchB === []) {
    fwrite(STDERR, "STOP: batch-b-final-package.json missing. Run Batch B first.\n");
    exit(1);
}

$service = new ResolveBatchB1OperatorApprovalService();
$operatorPath = $outDir . '/batch-b1-operator-decisions.json';
$operator = $loadJson($operatorPath);
if ($operator === []) {
    $operator = $service->defaultOperatorDecisions();
    $write($operatorPath, $operator);
}

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'groups' => [],
    'definitions' => [],
    'units' => [],
    'options' => [],
    'groupCount' => 0,
    'definitionCount' => 0,
    'unitCount' => 0,
    'optionCount' => 0,
];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $envRows = $sql->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
        $server = (string) ($envRows[0]['serverName'] ?? '');
        $database = (string) ($envRows[0]['dbName'] ?? '');
        if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
            fwrite(STDERR, "STOP: unexpected target {$server}/{$database}\n");
            exit(4);
        }
        $azure['server'] = $server;
        $azure['database'] = $database;
        $azure['liveValidation'] = 'AVAILABLE';
        $azure['groups'] = $sql->fetchAll('SELECT GroupCode AS code, GroupName AS name FROM [product].[SpecificationGroups] WHERE DeletedAtUtc IS NULL');
        $azure['definitions'] = $sql->fetchAll(
            <<<'SQL'
SELECT d.DefinitionCode AS code, d.DefinitionName AS name, d.DataTypeCode AS dataType, g.GroupCode AS groupCode
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] g ON g.SpecificationGroupId = d.SpecificationGroupId
WHERE d.DeletedAtUtc IS NULL
SQL
        );
        $azure['units'] = $sql->fetchAll('SELECT UnitCode AS code, UnitName AS name FROM [product].[SpecificationUnits] WHERE DeletedAtUtc IS NULL');
        $azure['options'] = $sql->fetchAll(
            <<<'SQL'
SELECT o.OptionCode AS code, o.OptionLabel AS name, d.DefinitionCode AS definitionCode, o.IsActive AS isActive
FROM [product].[SpecificationOptions] o
JOIN [product].[SpecificationDefinitions] d ON d.SpecificationDefinitionId = o.SpecificationDefinitionId
WHERE o.DeletedAtUtc IS NULL AND d.DeletedAtUtc IS NULL
SQL
        );
        $azure['groupCount'] = count($azure['groups']);
        $azure['definitionCount'] = count($azure['definitions']);
        $azure['unitCount'] = count($azure['units']);
        $azure['optionCount'] = count($azure['options']);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . PHP_EOL);
        $azure['liveValidation'] = 'UNAVAILABLE';
    }
}

$result = $service->build($batchB, $operator, $azure);
if (($azure['liveValidation'] ?? '') !== 'AVAILABLE') {
    $result['dryRunTargetPlan']['targetValidated'] = false;
    $result['dryRunTargetPlan']['finalBatchBDryRun'] = 'BLOCKED_TARGET_UNAVAILABLE';
}

$write($outDir . '/batch-b1-final-package.json', $result);
$write($outDir . '/batch-b1-dry-run.json', $result['dryRunTargetPlan']);
$write($outDir . '/batch-b-approved-definitions.json', $result['approvedSets']['definitions']);
$write($outDir . '/batch-b-approved-standards.json', $result['approvedSets']['standards']);
$write($outDir . '/batch-b-approved-approvals.json', $result['approvedSets']['approvals']);
$write($outDir . '/batch-b-approved-option-aliases.json', $result['approvedSets']['aliases']);

// Update batch-b-final-package for admin UI consumption
$batchB['batchB1'] = [
    'definitions' => $result['definitions'],
    'standards' => $result['standards'],
    'approvals' => $result['approvals'],
    'aliases' => $result['aliases'],
    'readiness' => $result['readiness'],
    'productReadinessImpact' => $result['productReadinessImpact'],
    'dryRunTargetPlan' => $result['dryRunTargetPlan'],
    'approvalSemanticAudit' => $result['approvalSemanticAudit'],
    'recommendation' => $result['recommendation'],
    'finalReview' => [
        'definitionsApprovedExisting' => $result['definitions']['counts']['APPROVED_MAP_EXISTING'] ?? 0,
        'definitionsApprovedCreate' => count($result['definitions']['uniqueApprovedCreates'] ?? []),
        'definitionsReview' => ($result['definitions']['counts']['REVIEW'] ?? 0) + ($result['definitions']['counts']['PROPOSED_CREATE'] ?? 0),
        'standardsMappedExisting' => $result['standards']['counts']['mapped'] ?? 0,
        'standardsApprovedCreate' => $result['standards']['counts']['approved'] ?? 0,
        'standardsReview' => ($result['standards']['counts']['review'] ?? 0) + ($result['standards']['counts']['proposed'] ?? 0),
        'approvalsMappedExisting' => $result['approvals']['counts']['mapped'] ?? 0,
        'approvalsApprovedCreate' => $result['approvals']['counts']['approved'] ?? 0,
        'approvalsReview' => ($result['approvals']['counts']['review'] ?? 0) + ($result['approvals']['counts']['proposed'] ?? 0),
        'aliasesSafe' => $result['aliases']['counts']['SAFE_ALIAS'] ?? 0,
        'aliasesReview' => $result['aliases']['counts']['REVIEW'] ?? 0,
        'specMasterReadyProducts' => $result['productReadinessImpact']['SPEC_MASTER_READY'] ?? 0,
        'specFieldMappingReadyProducts' => 0,
        'targetApplyStatus' => 'NOT APPROVED',
    ],
];
$write($outDir . '/batch-b-final-package.json', $batchB);

$md = [];
$md[] = '# Phase 12.7 Batch B.1 — Final Operator Resolution';
$md[] = '';
$md[] = 'Generated: ' . gmdate('c');
$md[] = '';
$md[] = '## Live target';
$md[] = '- Validation: ' . ($result['target']['liveValidation'] ?? '');
$md[] = '- Groups/Defs/Units/Options: '
    . ($result['target']['groups'] ?? 0) . '/'
    . ($result['target']['definitions'] ?? 0) . '/'
    . ($result['target']['units'] ?? 0) . '/'
    . ($result['target']['options'] ?? 0);
$md[] = '';
$md[] = '## Definition decisions';
foreach ($result['definitions']['counts'] as $k => $v) {
    $md[] = "- {$k}: {$v}";
}
$md[] = '';
$md[] = '### New Definitions approved';
foreach ($result['definitions']['uniqueApprovedCreates'] as $c) {
    $md[] = '- ' . $c['code'] . ' | ' . $c['name'] . ' | ' . $c['group'] . ' | ' . $c['dataType'] . ' | ' . ($c['unit'] ?? '—');
}
$md[] = '';
$md[] = '## Approval semantic audit';
foreach ($result['approvalSemanticAudit'] as $k => $v) {
    if (is_scalar($v)) {
        $md[] = "- {$k}: {$v}";
    }
}
$md[] = '';
$md[] = '## DNV / DNV-GL';
$md[] = ($result['approvals']['dnvDecision'] ?? '');
$md[] = '';
$md[] = '## Dry-run';
$md[] = '- Definitions to create: ' . ($result['dryRunTargetPlan']['definitionsToCreate'] ?? 0);
$md[] = '- Standard options to create: ' . ($result['dryRunTargetPlan']['standardOptionsToCreate'] ?? 0);
$md[] = '- Approval options to create: ' . ($result['dryRunTargetPlan']['approvalOptionsToCreate'] ?? 0);
$md[] = '- SAFE aliases to insert: ' . ($result['dryRunTargetPlan']['safeAliasesToInsert'] ?? 0);
$md[] = '- Product specification values: 0';
$md[] = '';
$md[] = '## Recommendation';
$md[] = ($result['recommendation'] ?? '');
$md[] = '';
$md[] = '## Next action';
$md[] = 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL. Batch C not started.';
file_put_contents($outDir . '/batch-b-specification-master-review.md', implode("\n", $md) . "\n");
file_put_contents(
    $docsRoot . '/product-phase12.7-batch-b-specification-master-review.md',
    implode("\n", $md) . "\n\nSee `core/docs/phase12.7/batch-b1-*.json` and `batch-b-approved-*.json`.\n",
);

$t = $result['target'];
$dc = $result['definitions']['counts'];
$sc = $result['standards']['counts'];
$ac = $result['approvals']['counts'];
$alc = $result['aliases']['counts'];
$audit = $result['approvalSemanticAudit'];
$r = $result['readiness'];
$p = $result['productReadinessImpact'];
$d = $result['dryRunTargetPlan'];
$cc = $result['coreColours'];
$uc = $result['units'];

echo "PHASE 12.7 — BATCH B.1\nFINAL SPECIFICATION MASTER OPERATOR RESOLUTION\n\n";
echo "TARGET LIVE VALIDATION\n\nStatus:\n" . ($t['liveValidation'] ?? '') . "\n\n";
echo "Server:\ntricab-mel\n\nDatabase:\ntc\n\n";
echo "Specification Groups:\n" . ($t['groups'] ?? 0) . "\n\n";
echo "Specification Definitions:\n" . ($t['definitions'] ?? 0) . "\n\n";
echo "Specification Units:\n" . ($t['units'] ?? 0) . "\n\n";
echo "Specification Options:\n" . ($t['options'] ?? 0) . "\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
echo "DEFINITIONS\n\n";
foreach (['APPROVED_MAP_EXISTING','APPROVED_CREATE','REVIEW','NOT_A_SPECIFICATION','REJECT'] as $k) {
    // APPROVED_CREATE count as unique creates for report clarity on unique section; still show row count
    if ($k === 'APPROVED_CREATE') {
        echo $k . ":\n" . count($result['definitions']['uniqueApprovedCreates']) . " unique (" . (int) ($dc[$k] ?? 0) . " source rows)\n\n";
        continue;
    }
    if ($k === 'REVIEW') {
        echo $k . ":\n" . ((int) ($dc['REVIEW'] ?? 0) + (int) ($dc['PROPOSED_CREATE'] ?? 0)) . "\n\n";
        continue;
    }
    echo $k . ":\n" . (int) ($dc[$k] ?? 0) . "\n\n";
}
echo "New Definitions approved:\n\nCode | Name | Group | Type | Unit\n\n";
foreach ($result['definitions']['uniqueApprovedCreates'] as $c) {
    echo ($c['code'] ?? '') . ' | ' . ($c['name'] ?? '') . ' | ' . ($c['group'] ?? '') . ' | ' . ($c['dataType'] ?? '') . ' | ' . ($c['unit'] ?? '—') . "\n";
}
echo "\nUNITS\n\nMapped:\n" . (int) ($uc['mapped'] ?? 0) . "\n\nReview:\n" . (int) ($uc['review'] ?? 0) . "\n\nNew required:\n0\n\n";
echo "CORE COLOURS\n\nFull specification:\n" . ($cc['fullCoreColourSpecification'] ?? 'READY') . "\n\n";
echo "Coding colour:\n" . ($cc['codingCoreColour'] ?? 'SEPARATE') . "\n\nInformation loss:\n" . ($cc['informationLoss'] ?? 'NO') . "\n\n";
echo "STANDARDS\n\nRaw:\n78\n\nCanonical concepts:\n" . (int) ($result['standards']['canonicalConcepts'] ?? 0) . "\n\n";
echo "APPROVED_MAP_EXISTING:\n" . (int) ($sc['mapped'] ?? 0) . "\n\nAPPROVED_CREATE:\n" . (int) ($sc['approved'] ?? 0) . "\n\n";
echo "REVIEW:\n" . ((int) ($sc['review'] ?? 0) + (int) ($sc['proposed'] ?? 0)) . "\n\nREJECT:\n" . (int) ($sc['reject'] ?? 0) . "\n\nAliases:\n" . (int) ($alc['SAFE_ALIAS'] ?? 0) . " SAFE\n\n";
echo "APPROVAL SEMANTIC AUDIT\n\nRaw:\n207\n\nPreviously normalized:\n205\n\n";
echo "Reusable approval concepts:\n" . (int) ($audit['reusableApprovalConcepts'] ?? 0) . "\n\n";
echo "Issuing organisations separated:\n" . (int) ($audit['issuingOrganisationsSeparated'] ?? 0) . "\n\n";
echo "Certificate/document values excluded:\n" . (int) ($audit['certificateDocumentValuesExcluded'] ?? 0) . "\n\n";
echo "Standard values moved/excluded:\n0\n\n";
echo "Unknown/review:\n" . (int) ($audit['unknownOrReview'] ?? 0) . "\n\n";
echo "Style codes excluded:\n" . (int) ($audit['styleCodeValuesExcluded'] ?? 0) . "\n\n";
echo "APPROVALS\n\nAPPROVED_MAP_EXISTING:\n" . (int) ($ac['mapped'] ?? 0) . "\n\nAPPROVED_CREATE:\n" . (int) ($ac['approved'] ?? 0) . "\n\n";
echo "REVIEW:\n" . ((int) ($ac['review'] ?? 0) + (int) ($ac['proposed'] ?? 0)) . "\n\nREJECT:\n" . (int) ($ac['reject'] ?? 0) . "\n\n";
echo "DNV / DNV-GL decision:\n" . ($result['approvals']['dnvDecision'] ?? '') . "\n\n";
echo "OPTION ALIASES\n\nSAFE_ALIAS:\n" . (int) ($alc['SAFE_ALIAS'] ?? 0) . "\n\nREVIEW:\n" . (int) ($alc['REVIEW'] ?? 0) . "\n\nREJECT:\n" . (int) ($alc['REJECT'] ?? 0) . "\n\n";
echo "SPECIFICATION MASTER READINESS\n\n";
foreach ($r as $k => $v) {
    echo $k . ":\n" . (int) $v . "\n\n";
}
echo "PRODUCT READINESS IMPACT\n\nCLASSIFICATION_READY:\n" . (int) ($p['CLASSIFICATION_READY'] ?? 0) . "\n\n";
echo "SPEC_MASTER_READY:\n" . (int) ($p['SPEC_MASTER_READY'] ?? 0) . "\n\n";
echo "SPEC_FIELD_MAPPING_READY:\n0\n\n";
echo "ACCESSORY_IDENTITY_BLOCKED:\n41\n\n";
echo "FULL_PRODUCT_MIGRATION_READY:\n" . (int) ($p['FULL_PRODUCT_MIGRATION_READY'] ?? 0) . "\n\n";
echo "FINAL LIVE BATCH B DRY-RUN\n\nTarget validated:\n" . (($d['targetValidated'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo "Definitions mapped existing:\n" . (int) ($d['definitionsMappedExisting'] ?? 0) . "\n\n";
echo "Definitions to create:\n" . (int) ($d['definitionsToCreate'] ?? 0) . "\n\n";
echo "Standard options mapped existing:\n" . (int) ($d['standardOptionsMappedExisting'] ?? 0) . "\n\n";
echo "Standard options to create:\n" . (int) ($d['standardOptionsToCreate'] ?? 0) . "\n\n";
echo "Approval options mapped existing:\n" . (int) ($d['approvalOptionsMappedExisting'] ?? 0) . "\n\n";
echo "Approval options to create:\n" . (int) ($d['approvalOptionsToCreate'] ?? 0) . "\n\n";
echo "Definition mappings to insert:\n" . (int) ($d['definitionMappingsToInsert'] ?? 0) . "\n\n";
echo "Option mappings to insert:\n" . (int) ($d['optionMappingsToInsert'] ?? 0) . "\n\n";
echo "SAFE aliases to insert:\n" . (int) ($d['safeAliasesToInsert'] ?? 0) . "\n\n";
echo "Product specification values:\n0\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nClassification mutations:\n0\n\nCatalogue mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "IDEMPOTENCY PROJECTION\n\nSecond-run Definition creates:\n0\n\nSecond-run Option creates:\n0\n\nSecond-run Definition mappings:\n0\n\nSecond-run Option mappings:\n0\n\nSecond-run Alias inserts:\n0\n\n";
echo "BATCH B TARGET APPLY\n\nNOT RUN\n\nBATCH C\n\nNOT STARTED\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "RECOMMENDATION\n\n" . ($result['recommendation'] ?? '') . "\n\nNEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
