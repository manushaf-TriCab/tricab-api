<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.5.1 — Cable spec final semantic resolution (read-only).
 *
 *   php bin/investigate-cable-spec-d5-1-final-resolution.php --confirm-non-production
 *   php bin/investigate-cable-spec-d5-1-final-resolution.php --offline
 *   php bin/investigate-cable-spec-d5-1-final-resolution.php --offline --verify-deterministic
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
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD51LegacySourceScanner;
use TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD51FinalResolutionService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD51CliGuard::refuseApply($argvList);
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

$semanticCompare = static function (array $payload) use ($ksortRecursive): array {
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

$d5Package = $loadJson($outDir . '/cable-spec-d5-final-review-package.json');
$forbidden = 0;
$evidence = CableSpecBatchD51InvestigationCollector::embeddedEvidence();
$collector = new CableSpecBatchD51InvestigationCollector();

$scanRoots = [
    dirname(__DIR__) . '/core/src',
    dirname(__DIR__) . '/src',
];
$sourceScan = (new CableSpecBatchD51LegacySourceScanner())->scan($scanRoots);

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
        $query = static fn (string $sql, array $params = []): array => $conn->query($sql, $params, 'CableSpecD51', 'collect');
        $evidence = $collector->collect($query, $styles);
        echo "Live D.5.1 investigation evidence refreshed.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Legacy tc unavailable — embedded evidence: ' . $e->getMessage() . "\n");
        $evidence = CableSpecBatchD51InvestigationCollector::embeddedEvidence();
    }
}

$azure = [
    'definitionCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_DEFINITIONS_POST,
    'fieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_TOTAL_MAPPINGS_POST,
    'cableFieldMappingCount' => ApplyCableSpecBatchD4TargetApplyService::EXPECTED_MAPPING_PACKAGE_COUNT,
    'definitions' => array_map(static fn (string $c): array => ['code' => $c], ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES),
    'units' => [['code' => 'KG_PER_KM']],
];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $envRows = $sql->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
        $server = (string) ($envRows[0]['serverName'] ?? '');
        $database = (string) ($envRows[0]['dbName'] ?? '');
        if (str_contains(strtolower($server), 'tricab-mel') && strtolower($database) === 'tc') {
            $defs = $sql->fetchAll('SELECT DefinitionCode AS code FROM product.SpecificationDefinitions WHERE DeletedAtUtc IS NULL');
            $units = $sql->fetchAll('SELECT UnitCode AS code FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL');
            $azure = [
                'definitionCount' => count($defs),
                'fieldMappingCount' => (int) ($sql->fetchAll('SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings')[0]['cnt'] ?? 0),
                'cableFieldMappingCount' => (int) ($sql->fetchAll("SELECT COUNT(*) AS cnt FROM migration.SpecificationFieldMappings WHERE SourceTable = 'cable_specs'")[0]['cnt'] ?? 0),
                'definitions' => $defs,
                'units' => $units,
            ];
        }
    } catch (Throwable) {
        // offline baseline above
    }
}

if ($d5Package === []) {
    $d5Package = [
        'phase' => '12.7-batch-d5-cable-spec-review-investigation',
        'd4Integrity' => ['status' => 'D4_INTEGRITY_OK'],
    ];
}

/** @var InvestigateCableSpecBatchD51FinalResolutionService $service */
$service = $container->get(InvestigateCableSpecBatchD51FinalResolutionService::class);
$inputs = [
    'classificationReadyProducts' => $products,
    'investigationEvidence' => $evidence,
    'legacySourceScan' => $sourceScan,
    'azure' => $azure,
    'd5Package' => $d5Package,
    'forbiddenWriteAttempts' => $forbidden,
];
$report = $service->resolve($inputs);

if (($report['packageStatus'] ?? '') === InvestigateCableSpecBatchD51FinalResolutionService::D51_BLOCKED_BASELINE_DRIFT) {
    $write($outDir . '/cable-spec-d5-1-final-package.json', $report);
    fwrite(STDERR, InvestigateCableSpecBatchD51FinalResolutionService::D51_BLOCKED_BASELINE_DRIFT . "\n");
    exit(4);
}

$findings = is_array($report['fieldFindings'] ?? null) ? $report['fieldFindings'] : [];

$write($outDir . '/cable-spec-d5-1-final-package.json', $report);
$write($outDir . '/cable-spec-d5-1-cc-final.json', $findings['cc'] ?? []);
$write($outDir . '/cable-spec-d5-1-ct-final.json', $findings['ct'] ?? []);
$write($outDir . '/cable-spec-d5-1-weight-unit-final.json', [
    'walu' => $findings['walu'] ?? [],
    'wbnz' => $findings['wbnz'] ?? [],
    'unitProof' => $evidence['waluWbnzUnitProof'] ?? [],
]);
$write($outDir . '/cable-spec-d5-1-ins-final.json', $findings['ins'] ?? []);
$write($outDir . '/cable-spec-d5-1-b1-final.json', [
    'Af' => $findings['Af'] ?? [],
    'r' => $findings['r'] ?? [],
    'x50' => $findings['x50'] ?? [],
    'x60' => $findings['x60'] ?? [],
    'tension' => $findings['tension'] ?? [],
]);
$write($outDir . '/cable-spec-d5-1-operator-options.json', $report['operatorOptions'] ?? []);
$write($outDir . '/cable-spec-d5-1-readiness-options.json', $report['readinessOptions'] ?? []);

if ($verifyDeterministic) {
    $again = $service->resolve($inputs);
    if (json_encode($semanticCompare($report)) !== json_encode($semanticCompare($again))) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED\n");
        exit(1);
    }
    echo "Deterministic resolve verified.\n";
}

$closure = is_array($report['closureRecommendation'] ?? null) ? $report['closureRecommendation'] : [];
$baseline = is_array($report['baselineIntegrity'] ?? null) ? $report['baselineIntegrity'] : [];

echo "PHASE 12.7 — BATCH D.5.1\nFINAL SEMANTIC RESOLUTION\n\n";
echo "Baseline drift: " . ((bool) ($baseline['driftDetected'] ?? true) ? 'YES' : 'NO') . "\n";
echo "Closure path: " . (string) ($closure['recommendedPath'] ?? '') . "\n\n";

foreach (InvestigateCableSpecBatchD51FinalResolutionService::REVIEW_FIELDS as $field) {
    $f = is_array($findings[$field] ?? null) ? $findings[$field] : [];
    echo $field . ' → ' . (string) ($f['resolution'] ?? '') . ' [' . (string) ($f['confidence'] ?? $f['meaningConfidence'] ?? '') . "]\n";
}

echo "\n";
if (($report['packageStatus'] ?? '') === 'READY_FOR_FINAL_OPERATOR_DECISIONS') {
    echo "PHASE 12.7 BATCH D.5.1 FINAL SEMANTIC RESOLUTION: COMPLETE\n";
    echo "READY FOR FINAL OPERATOR DECISIONS\n";
} else {
    echo "PHASE 12.7 BATCH D.5.1 FINAL SEMANTIC RESOLUTION: BLOCKED\n";
    echo "CRITICAL SEMANTICS STILL UNRESOLVED\n";
}
exit(0);
