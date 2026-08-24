<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.2 — Record Cable Spec Operator Decisions (artifact only).
 *
 *   php bin/record-cable-spec-d2-operator-decisions.php --confirm-non-production
 *   php bin/record-cable-spec-d2-operator-decisions.php --offline
 *   php bin/record-cable-spec-d2-operator-decisions.php --offline --verify-deterministic
 *
 * --apply / --execute / --write refused. No Definition / mapping / value / target writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2DecisionCliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\RecordCableSpecBatchD2OperatorDecisionsService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD2DecisionCliGuard::refuseApply($argvList);
if ($refuse !== null) {
    fwrite(STDERR, $refuse . "\n");
    exit(3);
}

if (!$offline && !$confirmNonProd) {
    fwrite(STDERR, "ERROR: require --confirm-non-production or --offline\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$container = ContainerFactory::create(Settings::fromEnvironment($env));

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

$ksortRecursive = static function (mixed &$value) use (&$ksortRecursive): void {
    if (!is_array($value)) {
        return;
    }
    foreach ($value as &$child) {
        $ksortRecursive($child);
    }
    unset($child);
    if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
};

$semanticComparePayload = static function (array $payload) use ($ksortRecursive): array {
    $copy = $payload;
    unset($copy['generatedAtUtc']);
    $ksortRecursive($copy);

    return $copy;
};

$d2 = $loadJson($outDir . '/cable-spec-d2-final-review-package.json');
if ($d2 === []) {
    $d2 = $loadJson($outDir . '/cable-spec-final-dry-run-package.json');
}
if ($d2 === []) {
    fwrite(STDERR, "STOP: missing D.2 review package or D.1 package in core/docs/phase12.7/\n");
    exit(5);
}

$inventory = $loadJson($outDir . '/cable-spec-field-inventory.json');
if ($inventory === [] && isset($d2['fieldInventory']) && is_array($d2['fieldInventory'])) {
    $inventory = $d2['fieldInventory'];
}

$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$products = is_array($ready['items'] ?? null) ? $ready['items'] : [];

$liveCache = $loadJson($outDir . '/cable-spec-live-stats-cache.json');
$definitions = is_array($liveCache['definitions'] ?? null) ? $liveCache['definitions'] : [];
if ($inventory === [] && isset($liveCache['stats']) && is_array($liveCache['stats'])) {
    $inventory = ['stats' => $liveCache['stats']];
}

$units = $loadJson($outDir . '/batch-b-unit-resolution.json');
$presence = CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'];

/** @var RecordCableSpecBatchD2OperatorDecisionsService $service */
$service = $container->get(RecordCableSpecBatchD2OperatorDecisionsService::class);
$resolveInputs = [
    'd2Package' => $d2,
    'inventory' => $inventory,
    'classificationReadyProducts' => $products,
    'azureDefinitions' => $definitions,
    'azureUnits' => $units,
    'styleFieldPresence' => $presence,
    'forbiddenWriteAttempts' => 0,
];
$report = $service->record($resolveInputs);

$write($outDir . '/cable-spec-d2-final-decision-package.json', $report);
$write($outDir . '/cable-spec-d2-operator-decisions.json', $report['operatorDecisions'] ?? []);
$write($outDir . '/cable-spec-d2-approved-existing-mappings.json', $report['approvedExistingMappings'] ?? []);
$write($outDir . '/cable-spec-d2-approved-definitions.json', $report['approvedDefinitions'] ?? []);
$write($outDir . '/cable-spec-d2-approved-not-specifications.json', $report['approvedNotSpecifications'] ?? []);
$write($outDir . '/cable-spec-d2-unresolved-review.json', $report['unresolvedReview'] ?? []);
$write($outDir . '/cable-spec-d2-post-decision-readiness.json', $report['readinessProjection'] ?? []);

if ($verifyDeterministic) {
    $again = $service->record($resolveInputs);
    $a = $semanticComparePayload($report);
    $b = $semanticComparePayload($again);
    if (json_encode($a) !== json_encode($b)) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED: semantic decision payloads differ\n");
        exit(1);
    }
    echo "Deterministic decision recording verified.\n";
}

$counts = $report['decisionCounts'] ?? [];
$proj = $report['readinessProjection'] ?? [];
$current = $proj['currentTarget'] ?? [];
$after = $proj['projectedAfterApprovedDecisionsApplied'] ?? [];
$blockers = $report['blockerMatrix']['criticalCodeBlockers'] ?? [];
$ambient = $report['ambientCurrentValidation'] ?? [];
$walu = $report['waluWbnzUnitValidation'] ?? [];
$integrity = $report['batchC2Integrity'] ?? [];
$ccCt = $report['ccCtFinalSearch'] ?? [];

echo "PHASE 12.7 — BATCH D.2\nCABLE SPEC OPERATOR DECISIONS\nRECORD ONLY — APPLY NOT AVAILABLE\n\n";
echo "PACKAGE\n\nStatus:\n" . (string) ($report['packageStatus'] ?? '') . "\n\n";
echo "DECISIONS\n\n";
foreach ([
    RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_MAP_EXISTING,
    RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_CREATE,
    RecordCableSpecBatchD2OperatorDecisionsService::APPROVED_NOT_A_SPECIFICATION,
    RecordCableSpecBatchD2OperatorDecisionsService::REVIEW,
    RecordCableSpecBatchD2OperatorDecisionsService::REJECT,
] as $k) {
    echo $k . ":\n" . (int) ($counts[$k] ?? 0) . "\n\n";
}

echo "AMBIENT VALIDATION\n\npassed:\n" . (($ambient['passed'] ?? false) ? 'true' : 'false') . "\n\nconfidence:\n" . (string) ($ambient['confidence'] ?? '') . "\n\n";
echo "WALU/WBNZ\n\nreason:\n" . (string) ($walu['reason'] ?? '') . "\n\n";
echo "CRITICAL BLOCKERS (post-decision)\n\ncc:\n" . (int) ($blockers['cc'] ?? 0) . "\n\nmt:\n" . (int) ($blockers['mt'] ?? 0) . "\n\nct:\n" . (int) ($blockers['ct'] ?? 0) . "\n\n";
echo "READINESS\n\nCurrent CABLE_SPEC READY:\n" . (int) ($current['CABLE_SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\nCurrent SPEC READY:\n" . (int) ($current['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "Projected READY:\n" . (int) ($after['READY'] ?? 0) . "\n\nProjected REVIEW:\n" . (int) ($after['REVIEW'] ?? 0) . "\n\n";
echo "cc/ct search definitiveEvidenceFound:\n" . (($ccCt['definitiveEvidenceFound'] ?? false) ? 'true' : 'false') . "\n\n";
echo "INTEGRITY\n\ndefs:\n" . (int) ($integrity['definitions'] ?? 0) . "\n\nstyleMaps:\n" . (int) ($integrity['styleMappings'] ?? 0) . "\n\n";
echo "APPLY\n\nNOT AVAILABLE\n\n";
echo "PHASE 12.7 BATCH D.2 OPERATOR DECISIONS: RECORDED\n";
if (($report['recommendation']['readyForD3FinalMappingDryRun'] ?? false) === true) {
    echo "READY FOR D.3 FINAL MAPPING DRY-RUN\n";
} else {
    echo "ADDITIONAL OPERATOR DECISIONS REQUIRED\n";
}
echo "DO NOT START D.3 APPLY AUTOMATICALLY\n";
exit(0);
