<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.5 — Cable Spec REVIEW investigation (read-only).
 *
 *   php bin/investigate-cable-spec-d5-review.php --confirm-non-production
 *   php bin/investigate-cable-spec-d5-review.php --offline
 *   php bin/investigate-cable-spec-d5-review.php --offline --verify-deterministic
 *
 * --apply / --execute / --write refused with CABLE_SPEC_D5_APPLY_NOT_AVAILABLE.
 * No Definition / Unit / Option / mapping / value writes.
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
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD5CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD5InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD5ReviewService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD5CliGuard::refuseApply($argvList);
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
$evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
$collector = new CableSpecBatchD5InvestigationCollector();

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
            return $conn->query($sql, $params, 'CableSpecD5Review', 'collect');
        };
        $evidence = $collector->collect($query, $styles);
        echo "Live D.5 investigation evidence refreshed.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Legacy tc unavailable — using embedded evidence: ' . $e->getMessage() . "\n");
        $evidence = CableSpecBatchD5InvestigationCollector::embeddedEvidence();
    }
}

$azure = [];
if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $envRows = $sql->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
        $server = (string) ($envRows[0]['serverName'] ?? '');
        $database = (string) ($envRows[0]['dbName'] ?? '');
        if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
            throw new RuntimeException("Unexpected target {$server}/{$database}");
        }
        $defs = $sql->fetchAll(<<<'SQL'
SELECT d.DefinitionCode AS code, d.DefinitionName AS name, d.DataTypeCode AS dataTypeCode, d.Unit AS unit
FROM product.SpecificationDefinitions d
WHERE d.DeletedAtUtc IS NULL
SQL);
        $units = $sql->fetchAll('SELECT UnitCode AS code, UnitName AS name, Symbol AS symbol FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL');
        $fieldMaps = (int) ($sql->fetchAll('SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings')[0]['cnt'] ?? 0);
        $cableMaps = (int) ($sql->fetchAll("SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE SourceTable = 'cable_specs'")[0]['cnt'] ?? 0);
        $styleMaps = (int) ($sql->fetchAll("SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE LegacyStyleCode IS NOT NULL AND LTRIM(RTRIM(LegacyStyleCode)) <> ''")[0]['cnt'] ?? 0);
        $azure = [
            'definitions' => $defs,
            'units' => $units,
            'definitionCount' => count($defs),
            'fieldMappingCount' => $fieldMaps,
            'cableFieldMappingCount' => $cableMaps,
            'styleFieldMappingCount' => $styleMaps,
            'server' => $server,
            'database' => $database,
        ];
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure snapshot unavailable — using D.4 post-apply baseline: ' . $e->getMessage() . "\n");
        $azure = [
            'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
            'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
            'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
            'styleFieldMappingCount' => 1021,
            'definitions' => array_map(
                static fn (string $code): array => ['code' => $code],
                ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES,
            ),
            'units' => [['code' => 'KG_PER_KM']],
            'offlineBaseline' => true,
        ];
    }
} else {
    $azure = [
        'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
        'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
        'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
        'styleFieldMappingCount' => 1021,
        'definitions' => array_map(
            static fn (string $code): array => ['code' => $code],
            ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES,
        ),
        'units' => [['code' => 'KG_PER_KM']],
        'offlineBaseline' => true,
    ];
}

/** @var InvestigateCableSpecBatchD5ReviewService $service */
$service = $container->get(InvestigateCableSpecBatchD5ReviewService::class);
$inputs = [
    'classificationReadyProducts' => $products,
    'investigationEvidence' => $evidence,
    'azure' => $azure,
    'forbiddenWriteAttempts' => $forbidden,
];
$report = $service->investigate($inputs);

if (($report['packageStatus'] ?? '') === InvestigateCableSpecBatchD5ReviewService::D5_BLOCKED_D4_INTEGRITY) {
    $write($outDir . '/cable-spec-d5-final-review-package.json', $report);
    fwrite(STDERR, InvestigateCableSpecBatchD5ReviewService::D5_BLOCKED_D4_INTEGRITY . "\n");
    exit(4);
}

$write($outDir . '/cable-spec-d5-final-review-package.json', $report);
$write($outDir . '/cable-spec-d5-field-evidence.json', [
    'codebaseEvidence' => $report['codebaseEvidence'] ?? [],
    'targetMasterScan' => $report['targetMasterScan'] ?? [],
    'investigationEvidenceMode' => $report['investigationEvidenceMode'] ?? null,
    'd4Integrity' => $report['d4Integrity'] ?? [],
]);
$write($outDir . '/cable-spec-d5-cc-investigation.json', [
    'ccByValue' => $evidence['ccByValue'] ?? [],
    'ccCrossNcXa' => $evidence['ccCrossNcXa'] ?? [],
    'ccValueA' => $evidence['ccValueA'] ?? [],
    'proposal' => array_values(array_filter(
        $report['proposals']['items'] ?? [],
        static fn (array $p): bool => ($p['legacyField'] ?? '') === 'cc',
    ))[0] ?? null,
]);
$write($outDir . '/cable-spec-d5-ct-investigation.json', [
    'ctByValue' => $evidence['ctByValue'] ?? [],
    'ctPhysicalSamples' => $evidence['ctPhysicalSamples'] ?? [],
    'ratingCtEncoding' => $evidence['ratingCtEncoding'] ?? [],
    'targetConductorScan' => $report['targetMasterScan']['conductorDefinitionMatches'] ?? [],
    'proposal' => array_values(array_filter(
        $report['proposals']['items'] ?? [],
        static fn (array $p): bool => ($p['legacyField'] ?? '') === 'ct',
    ))[0] ?? null,
]);
$write($outDir . '/cable-spec-d5-weight-investigation.json', [
    'waluWbnzUnitAnalysis' => $evidence['waluWbnzUnitAnalysis'] ?? [],
    'proposals' => array_values(array_filter(
        $report['proposals']['items'] ?? [],
        static fn (array $p): bool => in_array($p['legacyField'] ?? '', ['walu', 'wbnz'], true),
    )),
]);
$write($outDir . '/cable-spec-d5-ins-investigation.json', [
    'insByStyle' => $evidence['insByStyle'] ?? [],
    'insDimensionalCheck' => $evidence['insDimensionalCheck'] ?? [],
    'proposal' => array_values(array_filter(
        $report['proposals']['items'] ?? [],
        static fn (array $p): bool => ($p['legacyField'] ?? '') === 'ins',
    ))[0] ?? null,
]);
$write($outDir . '/cable-spec-d5-b1-investigation.json', [
    'b1FieldSamples' => $evidence['b1FieldSamples'] ?? [],
    'proposals' => array_values(array_filter(
        $report['proposals']['items'] ?? [],
        static fn (array $p): bool => in_array($p['legacyField'] ?? '', ['Af', 'r', 'x50', 'x60', 'tension'], true),
    )),
]);
$write($outDir . '/cable-spec-d5-proposals.json', $report['proposals'] ?? []);
$write($outDir . '/cable-spec-d5-readiness-scenarios.json', $report['readinessScenarios'] ?? []);

if ($verifyDeterministic) {
    $again = $service->investigate($inputs);
    $a = $semanticComparePayload($report);
    $b = $semanticComparePayload($again);
    if (json_encode($a) !== json_encode($b)) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED: semantic investigate payloads differ\n");
        exit(1);
    }
    echo "Deterministic investigate verified.\n";
}

$d4 = $report['d4Integrity'] ?? [];
$scenarios = $report['readinessScenarios'] ?? [];
$proposals = $report['proposals']['items'] ?? [];

echo "PHASE 12.7 — BATCH D.5\nCABLE SPEC REVIEW INVESTIGATION\nREAD INVESTIGATE PROPOSE ONLY\n\n";
echo "D.4 INTEGRITY\n\nDefinitions: " . (int) ($d4['definitions'] ?? 0) . "\n";
echo "Total mappings: " . (int) ($d4['totalFieldMappings'] ?? 0) . "\n";
echo "Cable mappings: " . (int) ($d4['cableFieldMappings'] ?? 0) . "\n";
echo "Drift: " . ((bool) ($d4['driftDetected'] ?? true) ? 'YES' : 'NO') . "\n\n";

echo "READINESS SCENARIOS\n\n";
foreach (['current', 'highConfidenceOnly', 'ccOnly', 'ctOnly', 'ccAndCt', 'allProposedCandidatesResolved'] as $key) {
    $s = is_array($scenarios[$key] ?? null) ? $scenarios[$key] : [];
    echo strtoupper($key) . " READY: " . (int) ($s['READY'] ?? 0) . " REVIEW: " . (int) ($s['REVIEW'] ?? 0) . "\n";
}
echo "\n";

echo "PROPOSALS (10 concepts)\n\n";
foreach ($proposals as $p) {
    if (!is_array($p)) {
        continue;
    }
    echo (string) ($p['legacyField'] ?? '') . ' → ' . (string) ($p['decision'] ?? '') . ' [' . (string) ($p['confidence'] ?? '') . "]\n";
}

echo "\nPHASE 12.7 BATCH D.5 CABLE SPEC REVIEW INVESTIGATION: COMPLETE\n";
echo "READY FOR OPERATOR DECISIONS\n";
exit(0);
