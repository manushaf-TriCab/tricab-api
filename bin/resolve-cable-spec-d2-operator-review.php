<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.2 — Cable Spec Operator Review (read/investigate only).
 *
 *   php bin/resolve-cable-spec-d2-operator-review.php --confirm-non-production
 *   php bin/resolve-cable-spec-d2-operator-review.php --offline
 *   php bin/resolve-cable-spec-d2-operator-review.php --offline --verify-deterministic
 *
 * --apply / --execute / --write refused. No Definition / mapping / value / target writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD2OperatorReviewService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD2CliGuard::refuseApply($argvList);
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
    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }
};

$semanticComparePayload = static function (array $payload) use ($ksortRecursive): array {
    $copy = $payload;
    unset($copy['generatedAtUtc']);
    $ksortRecursive($copy);

    return $copy;
};

$d1 = $loadJson($outDir . '/cable-spec-final-dry-run-package.json');
if ($d1 === []) {
    fwrite(STDERR, "STOP: missing D.1 package core/docs/phase12.7/cable-spec-final-dry-run-package.json\n");
    exit(5);
}

$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$products = is_array($ready['items'] ?? null) ? $ready['items'] : [];
$styles = [];
foreach ($products as $p) {
    if (!is_array($p) || strtoupper((string) ($p['family'] ?? '')) !== 'CABLE') {
        continue;
    }
    $style = strtoupper(trim((string) ($p['style_code'] ?? '')));
    if ($style !== '') {
        $styles[] = $style;
    }
}

$forbidden = 0;
$evidence = CableSpecBatchD2InvestigationCollector::embeddedEvidence();
$collector = new CableSpecBatchD2InvestigationCollector();

if (!$offline) {
    try {
        $legacy = LiveLegacySourceConfig::fromEnvironment($env);
        if (!$legacy->tcConfigured()) {
            throw new RuntimeException('LEGACY_TC_* not configured');
        }
        /** @var ReadOnlyMysqlConnectionFactory $factory */
        $factory = $container->get(ReadOnlyMysqlConnectionFactory::class);
        $conn = $factory->create(
            (string) $legacy->tcDsn,
            (string) $legacy->tcUser,
            (string) $legacy->tcPassword,
            'tc',
            $legacy->tcSslMode ?? 'required',
        );

        $query = static function (string $sql, array $params = []) use ($conn): array {
            return $conn->query($sql, $params, 'CableSpecD2Review', 'collect');
        };

        $evidence = $collector->collect($query, $styles);
        echo "Live D.2 investigation evidence refreshed.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Legacy tc unavailable — using embedded evidence: ' . $e->getMessage() . "\n");
        $evidence = CableSpecBatchD2InvestigationCollector::embeddedEvidence();
    }
}

/** @var ResolveCableSpecBatchD2OperatorReviewService $service */
$service = $container->get(ResolveCableSpecBatchD2OperatorReviewService::class);
$resolveInputs = [
    'd1Package' => $d1,
    'classificationReadyProducts' => $products,
    'investigationEvidence' => $evidence,
    'forbiddenWriteAttempts' => $forbidden,
];
$report = $service->resolve($resolveInputs);

$write($outDir . '/cable-spec-d2-final-review-package.json', $report);
$write($outDir . '/cable-spec-d2-semantic-investigation.json', [
    'criticalFindings' => $report['criticalFindings'] ?? [],
    'schemaInvestigation' => $report['schemaInvestigation'] ?? [],
    'ambientCurrentInvestigation' => $report['ambientCurrentInvestigation'] ?? [],
    'earthCsaTypeValidation' => $report['earthCsaTypeValidation'] ?? [],
    'investigationEvidenceMode' => $report['investigationEvidenceMode'] ?? null,
    'sampleInvestigations' => $evidence['sampleInvestigations'] ?? [],
    'descriptLookup' => $evidence['descriptLookup'] ?? [],
]);
$write($outDir . '/cable-spec-d2-code-correlations.json', $report['correlations'] ?? []);
$write($outDir . '/cable-spec-d2-operator-candidates.json', $report['operatorCandidates'] ?? []);
$write($outDir . '/cable-spec-d2-blocker-matrix.json', $report['blockerMatrix'] ?? []);
$write($outDir . '/cable-spec-d2-readiness-projection.json', $report['readinessProjection'] ?? []);

$doc = <<<'MD'
# Phase 12.7 Batch D.2 — Cable Spec Operator Review

READ + INVESTIGATE + REVIEW ONLY. Guard: `CABLE_SPEC_D2_APPLY_NOT_AVAILABLE`.

Reuses D.1 artifacts. No Definition / mapping / option / unit / value writes.
MD;
file_put_contents($docsRoot . '/product-phase12.7-cable-spec-d2-operator-review.md', $doc . PHP_EOL);

if ($verifyDeterministic) {
    $again = $service->resolve($resolveInputs);
    $a = $semanticComparePayload($report);
    $b = $semanticComparePayload($again);
    if (json_encode($a) !== json_encode($b)) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED: semantic resolve payloads differ\n");
        exit(1);
    }
    echo "Deterministic resolve verified.\n";
}

$counts = $report['candidateCounts'] ?? [];
$conf = $report['confidenceCounts'] ?? [];
$proj = $report['readinessProjection'] ?? [];
$blockers = $report['blockerMatrix']['criticalCodeBlockers'] ?? [];
$earth = $report['earthCsaTypeValidation'] ?? [];
$integrity = $report['batchC2Integrity'] ?? [];
$packageStatus = (string) ($report['packageStatus'] ?? '');

echo "PHASE 12.7 — BATCH D.2\nCABLE SPEC OPERATOR REVIEW\nREAD INVESTIGATE REVIEW ONLY\n\n";
echo "PACKAGE\n\nStatus:\n" . $packageStatus . "\n\n";
echo "CANDIDATES\n\n";
foreach ([
    ResolveCableSpecBatchD2OperatorReviewService::APPROVED_MAP_EXISTING_CANDIDATE,
    ResolveCableSpecBatchD2OperatorReviewService::APPROVED_CREATE_CANDIDATE,
    ResolveCableSpecBatchD2OperatorReviewService::NOT_A_SPECIFICATION_CANDIDATE,
    ResolveCableSpecBatchD2OperatorReviewService::REVIEW,
    ResolveCableSpecBatchD2OperatorReviewService::REJECT_CANDIDATE,
] as $k) {
    echo $k . ":\n" . (int) ($counts[$k] ?? 0) . "\n\n";
}
echo "CONFIDENCE\n\nHIGH:\n" . (int) ($conf['HIGH'] ?? 0) . "\n\nMEDIUM:\n" . (int) ($conf['MEDIUM'] ?? 0) . "\n\nLOW:\n" . (int) ($conf['LOW'] ?? 0) . "\n\n";
echo "CRITICAL BLOCKERS\n\ncc:\n" . (int) ($blockers['cc'] ?? 0) . "\n\nmt:\n" . (int) ($blockers['mt'] ?? 0) . "\n\nct:\n" . (int) ($blockers['ct'] ?? 0) . "\n\n";
echo "READINESS\n\nCurrent READY:\n" . (int) ($proj['current']['READY'] ?? 0) . "\n\nHIGH READY:\n" . (int) ($proj['highConfidence']['READY'] ?? 0) . "\n\nifCcMtCtResolved READY:\n" . (int) ($proj['ifCcMtCtResolved']['READY'] ?? 0) . "\n\n";
echo "EARTH-CSA typeRecommendation:\n" . (string) ($earth['typeRecommendation'] ?? '') . "\n\n";
echo "BATCH C/C.2 INTEGRITY\n\ndefs:\n" . (int) ($integrity['definitions'] ?? 0) . "\n\nstyleMaps:\n" . (int) ($integrity['styleMappings'] ?? 0) . "\n\n";
echo "APPLY\n\nNOT AVAILABLE\n\n";

echo "PHASE 12.7 BATCH D.2 CABLE SPEC OPERATOR REVIEW: COMPLETE\n";
if ($packageStatus === 'READY_FOR_OPERATOR_DECISIONS') {
    echo "PHASE 12.7 BATCH D.2 CABLE SPEC OPERATOR REVIEW: SUCCESS\n";
    echo "READY FOR OPERATOR DECISIONS\n";
} else {
    echo "MORE SEMANTIC INVESTIGATION REQUIRED\n";
}
exit(0);
