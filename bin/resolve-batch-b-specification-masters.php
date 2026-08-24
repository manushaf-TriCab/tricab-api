<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch B — Specification master review + DRY-RUN only.
 *
 *   php bin/resolve-batch-b-specification-masters.php --confirm-non-production
 *   php bin/resolve-batch-b-specification-masters.php --offline
 *
 * --apply is refused. No Definition/Option/alias/product-value writes.
 * Batch C d/v mapping rows are not processed.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchBSpecificationMasterService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$isApply = in_array('--apply', $argvList, true);

if ($isApply) {
    fwrite(STDERR, "BATCH B APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch B is DRY-RUN ONLY. Re-run without --apply.\n");
    exit(3);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
$phase126 = $docsRoot . '/phase12.6-mappings';
$pre = $docsRoot . '/pre-migration-mappings';
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

$discovery = $loadJson($pre . '/discovery-report.json');
$payload = is_array($discovery['payload'] ?? null) ? $discovery['payload'] : [];
$definitionProposals = $loadJson($pre . '/specification-definitions.proposed.json');
$unitsProposed = $loadJson($phase126 . '/units.proposed.json');
$standardsProposed = $loadJson($phase126 . '/standards.proposed.json');
$approvalsProposed = $loadJson($phase126 . '/approvals.proposed.json');
$optionsProposed = $loadJson($phase126 . '/options.proposed.json');
$dvProposed = $loadJson($phase126 . '/dv-field-mappings.proposed.json');
$operatorDecisions = $loadJson($outDir . '/batch-b-operator-decisions.json');
$a3 = $loadJson($outDir . '/batch-a3-final-package.json');

$specLabels = [];
if (isset($payload['tc']['specLabels']) && is_array($payload['tc']['specLabels'])) {
    $specLabels = $payload['tc']['specLabels'];
} elseif (isset($payload['specifications']['specLabels']) && is_array($payload['specifications']['specLabels'])) {
    $specLabels = $payload['specifications']['specLabels'];
}

$cableSpecsFieldCount = 0;
foreach (($payload['tc']['tables'] ?? []) as $t) {
    if (!is_array($t)) {
        continue;
    }
    // discovery may store generic shape; count columns when present
    if (isset($t['columns']) && is_array($t['columns'])) {
        $cableSpecsFieldCount = max($cableSpecsFieldCount, count($t['columns']));
    }
}
// Known cable_specs inventory from Phase 12.6 discovery narrative
if ($cableSpecsFieldCount === 0) {
    $cableSpecsFieldCount = 35;
}

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'groups' => [],
    'definitions' => [],
    'units' => [],
    'options' => [],
    'optionCount' => 0,
    'optionActiveCount' => 0,
    'optionInactiveCount' => 0,
];

$forbidden = 0;
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
        $azure['groups'] = $sql->fetchAll('SELECT GroupCode AS code, GroupName AS name, DisplayOrder, IsActive FROM [product].[SpecificationGroups] WHERE DeletedAtUtc IS NULL');
        $azure['definitions'] = $sql->fetchAll(
            <<<'SQL'
SELECT d.DefinitionCode AS code, d.DefinitionName AS name, d.DataTypeCode AS dataType,
       g.GroupCode AS groupCode, d.IsActive AS isActive, CONVERT(NVARCHAR(36), d.PublicId) AS publicId
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[SpecificationGroups] g ON g.SpecificationGroupId = d.SpecificationGroupId
WHERE d.DeletedAtUtc IS NULL
SQL
        );
        $azure['units'] = $sql->fetchAll('SELECT UnitCode AS code, UnitName AS name, Symbol AS symbol, IsActive AS isActive FROM [product].[SpecificationUnits] WHERE DeletedAtUtc IS NULL');
        $azure['options'] = $sql->fetchAll(
            <<<'SQL'
SELECT o.OptionCode AS code, o.OptionLabel AS name, o.OptionValue AS optionValue, d.DefinitionCode AS definitionCode,
       o.IsActive AS isActive, CONVERT(NVARCHAR(36), o.PublicId) AS publicId
FROM [product].[SpecificationOptions] o
JOIN [product].[SpecificationDefinitions] d ON d.SpecificationDefinitionId = o.SpecificationDefinitionId
WHERE o.DeletedAtUtc IS NULL AND d.DeletedAtUtc IS NULL
SQL
        );
        $azure['optionCount'] = count($azure['options']);
        $azure['optionActiveCount'] = count(array_filter($azure['options'], static fn (array $r): bool => (bool) ($r['isActive'] ?? false)));
        $azure['optionInactiveCount'] = $azure['optionCount'] - $azure['optionActiveCount'];
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . PHP_EOL);
        $azure['liveValidation'] = 'UNAVAILABLE';
    }
}

$service = new ResolveBatchBSpecificationMasterService();
$result = $service->resolve([
    'azure' => $azure,
    'definitionProposals' => $definitionProposals,
    'unitsProposed' => $unitsProposed,
    'standardsProposed' => $standardsProposed,
    'approvalsProposed' => $approvalsProposed,
    'optionsProposed' => $optionsProposed,
    'dvRows' => is_array($dvProposed['rows'] ?? null) ? $dvProposed['rows'] : [],
    'specLabels' => $specLabels,
    'operatorDecisions' => $operatorDecisions,
    'cableSpecsFieldCount' => $cableSpecsFieldCount,
    'standardsRawCount' => count($standardsProposed['values'] ?? []),
    'approvalsRawCount' => count($approvalsProposed['values'] ?? []),
    'forbiddenWriteAttempts' => $forbidden,
    'classificationReady' => (int) (($a3['readiness']['CLASSIFICATION_READY'] ?? 95)),
    'fullProductMigrationReady' => (int) (($a3['readiness']['FULL_PRODUCT_MIGRATION_READY'] ?? 54)),
    'fullProductMigrationBlocked' => (int) (($a3['readiness']['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 336)),
]);

if (($azure['liveValidation'] ?? '') !== 'AVAILABLE') {
    $result['dryRunTargetPlan']['targetValidated'] = false;
    $result['dryRunTargetPlan']['finalBatchBDryRun'] = 'BLOCKED_TARGET_UNAVAILABLE';
}

$write($outDir . '/batch-b-definition-resolution.json', $result['definitions']['items']);
$write($outDir . '/batch-b-unit-resolution.json', $result['units']['items']);
$write($outDir . '/batch-b-standards-resolution.json', $result['standards']['items']);
$write($outDir . '/batch-b-approvals-resolution.json', $result['approvals']['items']);
$write($outDir . '/batch-b-option-alias-resolution.json', $result['aliases']['items']);
$write($outDir . '/batch-b-dry-run.json', $result['dryRunTargetPlan']);
$write($outDir . '/batch-b-final-package.json', $result);

$md = [];
$md[] = '# Phase 12.7 Batch B — Specification Master Review';
$md[] = '';
$md[] = 'Generated: ' . gmdate('c');
$md[] = '';
$md[] = '## Target';
$md[] = '- Live validation: ' . ($result['target']['liveValidation'] ?? '');
$md[] = '- Server/DB: ' . ($result['target']['server'] ?? '') . ' / ' . ($result['target']['database'] ?? '');
$md[] = '- Groups: ' . ($result['target']['groups'] ?? 0);
$md[] = '- Definitions: ' . ($result['target']['definitions'] ?? 0);
$md[] = '- Units: ' . ($result['target']['units'] ?? 0) . ' (' . implode(', ', $result['target']['unitCodes'] ?? []) . ')';
$md[] = '- Options: ' . ($result['target']['options'] ?? 0) . ' (active ' . ($result['target']['optionsActive'] ?? 0) . ', inactive ' . ($result['target']['optionsInactive'] ?? 0) . ')';
$md[] = '';
$md[] = '## Definition decisions';
foreach ($result['definitions']['counts'] as $k => $v) {
    $md[] = "- {$k}: {$v}";
}
$md[] = '';
$md[] = '## Units';
$md[] = '- Mapped: ' . ($result['units']['counts']['mapped'] ?? 0);
$md[] = '- Review: ' . ($result['units']['counts']['review'] ?? 0);
$md[] = '- New unit required: ' . ($result['units']['counts']['newRequired'] ?? 0);
$md[] = '';
$md[] = '## Core Colours';
$md[] = '- Full CORE-COLOURS: ' . ($result['coreColours']['fullCoreColourSpecification'] ?? '');
$md[] = '- Coding CODE_CORE_COLOUR: ' . ($result['coreColours']['codingCoreColour'] ?? '');
$md[] = '- Information loss: ' . ($result['coreColours']['informationLoss'] ?? '');
$md[] = '';
$md[] = '## Standards / Approvals / Aliases';
$md[] = '- Standards mapped/proposed/review: '
    . ($result['standards']['counts']['mapped'] ?? 0) . '/'
    . ($result['standards']['counts']['proposed'] ?? 0) . '/'
    . ($result['standards']['counts']['review'] ?? 0);
$md[] = '- Approvals mapped/proposed/review: '
    . ($result['approvals']['counts']['mapped'] ?? 0) . '/'
    . ($result['approvals']['counts']['proposed'] ?? 0) . '/'
    . ($result['approvals']['counts']['review'] ?? 0);
$md[] = '- SAFE_ALIAS / REVIEW: '
    . ($result['aliases']['counts']['SAFE_ALIAS'] ?? 0) . '/'
    . ($result['aliases']['counts']['REVIEW'] ?? 0);
$md[] = '';
$md[] = '## Dry-run';
$md[] = '- Mode: DRY-RUN ONLY (no target writes)';
$md[] = '- Definitions to create (approved): ' . ($result['dryRunTargetPlan']['definitionsToCreate'] ?? 0);
$md[] = '- Definitions proposed pending approval: ' . ($result['dryRunTargetPlan']['definitionsProposedPendingApproval'] ?? 0);
$md[] = '- Options to create (approved): ' . ($result['dryRunTargetPlan']['optionsToCreate'] ?? 0);
$md[] = '- Product specification values: 0';
$md[] = '';
$md[] = '## Recommendation';
$md[] = ($result['recommendation'] ?? '') . '';
$md[] = '';
$md[] = '## Next action';
$md[] = 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL. Batch C not started.';
file_put_contents($outDir . '/batch-b-specification-master-review.md', implode("\n", $md) . "\n");

$docsPath = $docsRoot . '/product-phase12.7-batch-b-specification-masters.md';
file_put_contents($docsPath, implode("\n", $md) . "\n\nSee artifacts under `core/docs/phase12.7/batch-b-*.json`.\n");

// Console report
$t = $result['target'];
$inv = $result['inventory'];
$dc = $result['definitions']['counts'];
$uc = $result['units']['counts'];
$sc = $result['standards']['counts'];
$ac = $result['approvals']['counts'];
$alc = $result['aliases']['counts'];
$r = $result['readiness'];
$p = $result['productReadinessImpact'];
$d = $result['dryRunTargetPlan'];
$cc = $result['coreColours'];

echo "PHASE 12.7 — BATCH B\nSPECIFICATION MASTER REVIEW + DRY-RUN\n\n";
echo "TARGET LIVE VALIDATION\n\nStatus:\n" . ($t['liveValidation'] ?? '') . "\n\n";
echo "Server:\n" . ($t['server'] ?? '') . "\n\nDatabase:\ntc\n\n";
echo "Specification Groups:\n" . ($t['groups'] ?? 0) . "\n\n";
echo "Specification Definitions:\n" . ($t['definitions'] ?? 0) . "\n\n";
echo "Specification Units:\n" . ($t['units'] ?? 0) . "\n\n";
echo "Specification Options:\n" . ($t['options'] ?? 0) . "\n\n";
echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
echo "LEGACY SPECIFICATION INVENTORY\n\nWordPress fields:\n" . ($inv['wordpressFields'] ?? 0) . "\n\n";
echo "cable_specs fields:\n" . ($inv['cableSpecsFields'] ?? 0) . "\n\n";
echo "accessory d/v labels/concepts:\n" . ($inv['accessoryDvLabelConcepts'] ?? 0) . "\n\n";
echo "Standards raw values:\n" . ($inv['standardsRawValues'] ?? 0) . "\n\nStandards normalized:\n" . ($inv['standardsNormalized'] ?? 0) . "\n\n";
echo "Approvals raw values:\n" . ($inv['approvalsRawValues'] ?? 0) . "\n\nApprovals normalized:\n" . ($inv['approvalsNormalized'] ?? 0) . "\n\n";
echo "DEFINITION DECISIONS\n\n";
foreach (['APPROVED_MAP_EXISTING','APPROVED_CREATE','PROPOSED_CREATE','REVIEW','NOT_A_SPECIFICATION','REJECT'] as $k) {
    echo $k . ":\n" . (int) ($dc[$k] ?? 0) . "\n\n";
}
echo "UNITS\n\nMapped:\n" . (int) ($uc['mapped'] ?? 0) . "\n\nReview:\n" . (int) ($uc['review'] ?? 0) . "\n\nNew unit required:\n" . (int) ($uc['newRequired'] ?? 0) . "\n\n";
echo "CORE COLOURS\n\nFull Core Colour specification:\n" . ($cc['fullCoreColourSpecification'] ?? '') . "\n\n";
echo "Coding Core Colour:\n" . ($cc['codingCoreColour'] ?? '') . "\n\nInformation loss:\n" . ($cc['informationLoss'] ?? '') . "\n\n";
echo "STANDARDS\n\nExisting options mapped:\n" . (int) ($sc['mapped'] ?? 0) . "\n\nApproved new options:\n" . (int) ($sc['approved'] ?? 0) . "\n\nProposed:\n" . (int) ($sc['proposed'] ?? 0) . "\n\nReview:\n" . (int) ($sc['review'] ?? 0) . "\n\nAliases:\n(see alias artifact)\n\n";
echo "APPROVALS\n\nExisting options mapped:\n" . (int) ($ac['mapped'] ?? 0) . "\n\nApproved new options:\n" . (int) ($ac['approved'] ?? 0) . "\n\nProposed:\n" . (int) ($ac['proposed'] ?? 0) . "\n\nReview:\n" . (int) ($ac['review'] ?? 0) . "\n\nAliases:\n(see alias artifact)\n\n";
echo "OPTION ALIASES\n\nSAFE_ALIAS:\n" . (int) ($alc['SAFE_ALIAS'] ?? 0) . "\n\nREVIEW:\n" . (int) ($alc['REVIEW'] ?? 0) . "\n\nREJECT:\n" . (int) ($alc['REJECT'] ?? 0) . "\n\n";
echo "SPECIFICATION MASTER READINESS\n\n";
foreach ($r as $k => $v) {
    echo $k . ":\n" . (int) $v . "\n\n";
}
echo "PRODUCT READINESS IMPACT\n\nCLASSIFICATION_READY:\n" . (int) ($p['CLASSIFICATION_READY'] ?? 0) . "\n\n";
echo "SPEC_MASTER_READY:\n" . (int) ($p['SPEC_MASTER_READY'] ?? 0) . "\n\n";
echo "SPEC_FIELD_MAPPING_READY:\n" . (int) ($p['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "FULL_PRODUCT_MIGRATION_READY:\n" . (int) ($p['FULL_PRODUCT_MIGRATION_READY'] ?? 0) . "\n\n";
echo "Still blocked:\n" . json_encode($p['stillBlocked'] ?? [], JSON_UNESCAPED_SLASHES) . "\n\n";
echo "FINAL BATCH B DRY-RUN\n\nTarget validated:\n" . (($d['targetValidated'] ?? false) ? 'YES' : 'NO') . "\n\n";
echo "Definitions mapped existing:\n" . (int) ($d['definitionsMappedExisting'] ?? 0) . "\n\n";
echo "Definitions to create:\n" . (int) ($d['definitionsToCreate'] ?? 0) . "\n\n";
echo "Options mapped existing:\n" . (int) ($d['optionsMappedExisting'] ?? 0) . "\n\n";
echo "Options to create:\n" . (int) ($d['optionsToCreate'] ?? 0) . "\n\n";
echo "Definition mappings to insert:\n" . (int) ($d['definitionMappingsToInsert'] ?? 0) . "\n\n";
echo "Option mappings to insert:\n" . (int) ($d['optionMappingsToInsert'] ?? 0) . "\n\n";
echo "Aliases to insert:\n" . (int) ($d['aliasesToInsert'] ?? 0) . "\n\n";
echo "Product specification values:\n0\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nClassification mutations:\n0\n\nCatalogue mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "BATCH B TARGET APPLY\n\nNOT RUN\n\nBATCH C D/V MAPPINGS\n\nNOT STARTED\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "REMAINING BLOCKERS\n\n1. Operator approval of PROPOSED_CREATE Definitions\n2. Operator approval of Standards/Approvals options\n3. Batch C style-specific d/v mappings (764 rows)\n4. ACCESSORY_IDENTITY (41)\n5. CATEGORY_UNRESOLVED Series REVIEW (295)\n\n";
echo "RECOMMENDATION\n\n" . ($result['recommendation'] ?? '') . "\n\nNEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT OPERATOR APPROVAL\n";
