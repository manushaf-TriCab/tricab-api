<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.3 — Final Cable Spec Mapping Dry-Run.
 *
 *   php bin/resolve-cable-spec-d3-final-dry-run.php --confirm-non-production
 *   php bin/resolve-cable-spec-d3-final-dry-run.php --offline
 *   php bin/resolve-cable-spec-d3-final-dry-run.php --offline --verify-deterministic
 *
 * --apply / --execute / --write refused. No Definition / mapping / value / target writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD2InvestigationCollector;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD3CliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD3CliGuard::refuseApply($argvList);
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

$d2 = $loadJson($outDir . '/cable-spec-d2-final-decision-package.json');
if ($d2 === []) {
    fwrite(STDERR, "STOP: missing cable-spec-d2-final-decision-package.json\n");
    exit(5);
}

$inventory = $loadJson($outDir . '/cable-spec-field-inventory.json');
$liveCache = $loadJson($outDir . '/cable-spec-live-stats-cache.json');
if ($inventory === [] && isset($liveCache['stats']) && is_array($liveCache['stats'])) {
    $inventory = ['stats' => $liveCache['stats']];
}

$ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
$products = is_array($ready['items'] ?? null) ? $ready['items'] : [];
$cableStyles = [];
foreach ($products as $p) {
    if (!is_array($p) || strtoupper((string) ($p['family'] ?? '')) !== 'CABLE') {
        continue;
    }
    $style = strtoupper(trim((string) ($p['style_code'] ?? $p['styleCode'] ?? '')));
    if ($style !== '') {
        $cableStyles[] = $style;
    }
}
$cableStyles = array_values(array_unique($cableStyles));
sort($cableStyles);

$approvedFields = [
    'nc', 'xa', 'od', 'cdia', 'od_ins', 'br', 'wght',
    'ne', 'xae', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'A30c', 'A40c', 'A45c',
];

$presence = CableSpecBatchD2InvestigationCollector::embeddedEvidence()['styleFieldPresence'] ?? [];
$presenceCache = $loadJson($outDir . '/cable-spec-d3-approved-field-presence-cache.json');
if ($presenceCache !== []) {
    foreach ($presenceCache as $field => $styles) {
        if (is_array($styles) && $styles !== []) {
            $presence[(string) $field] = $styles;
        }
    }
}

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'definitions' => is_array($liveCache['definitions'] ?? null) ? $liveCache['definitions'] : [],
    'definitionCount' => 55,
    'unitCount' => 11,
    'optionCount' => 416,
    'styleFieldMappingCount' => 483,
    'fieldMappingCount' => 506,
];
$existingMappings = [];

if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $envRows = $sql->fetchAll('SELECT @@SERVERNAME AS s, DB_NAME() AS d');
        $azure['server'] = (string) ($envRows[0]['s'] ?? $azure['server']);
        $azure['database'] = (string) ($envRows[0]['d'] ?? $azure['database']);
        if (!str_contains(strtolower($azure['server']), 'tricab-mel') || strtolower($azure['database']) !== 'tc') {
            fwrite(STDERR, "STOP: unexpected Azure target {$azure['server']} / {$azure['database']}\n");
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
        $azure['definitionCount'] = count($azure['definitions']);
        $azure['unitCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.SpecificationUnits WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['optionCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.SpecificationOptions WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['styleFieldMappingCount'] = (int) ($sql->fetchAll("SELECT COUNT(*) c FROM migration.SpecificationFieldMappings WHERE LegacyStyleCode IS NOT NULL AND LTRIM(RTRIM(LegacyStyleCode)) <> ''")[0]['c'] ?? 0);
        $azure['fieldMappingCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM migration.SpecificationFieldMappings')[0]['c'] ?? 0);
        $existingMappings = $sql->fetchAll(<<<'SQL'
SELECT SourceSystem AS sourceSystem, SourceTable AS sourceTable, SourceField AS sourceField,
       LegacyStyleCode AS legacyStyleCode, TargetDefinitionCode AS targetDefinitionCode
FROM migration.SpecificationFieldMappings
WHERE SourceTable = 'cable_specs'
SQL);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . "\n");
        $azure['liveValidation'] = 'UNAVAILABLE';
        if (is_array($liveCache['definitions'] ?? null) && $liveCache['definitions'] !== []) {
            $azure['definitions'] = $liveCache['definitions'];
            $azure['definitionCount'] = count($azure['definitions']);
        }
    }

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
            return $conn->query($sql, $params, 'CableSpecD3DryRun', 'collect');
        };
        $collector = new CableSpecBatchD2InvestigationCollector();
        $livePresence = $collector->collectApprovedFieldPresence($query, $cableStyles, $approvedFields);
        foreach ($livePresence as $field => $styles) {
            if (is_array($styles) && $styles !== []) {
                $presence[(string) $field] = $styles;
            }
        }
        $write($outDir . '/cable-spec-d3-approved-field-presence-cache.json', $presence);
        echo 'Live approved-field presence refreshed (' . count($presence) . " fields).\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Legacy tc unavailable — using embedded/cache presence: ' . $e->getMessage() . "\n");
    }
}

/** @var ResolveCableSpecBatchD3FinalDryRunService $service */
$service = $container->get(ResolveCableSpecBatchD3FinalDryRunService::class);
$resolveInputs = [
    'd2Package' => $d2,
    'inventory' => $inventory,
    'classificationReadyProducts' => $products,
    'azure' => $azure,
    'existingMappings' => $existingMappings,
    'styleFieldPresence' => $presence,
    'offlineFallbackPresence' => $offline,
    'forbiddenWriteAttempts' => 0,
];
$report = $service->resolve($resolveInputs);

$write($outDir . '/cable-spec-d3-final-package.json', $report);
$write($outDir . '/cable-spec-d3-approved-definitions.json', $report['approvedDefinitionsValidation'] ?? []);
$write($outDir . '/cable-spec-d3-approved-field-mappings.json', $report['approvedFieldMappings'] ?? []);
$write($outDir . '/cable-spec-d3-review-exclusions.json', $report['reviewExclusions'] ?? []);
$write($outDir . '/cable-spec-d3-apply-projection.json', $report['applyProjection'] ?? []);
$write($outDir . '/cable-spec-d3-idempotency-projection.json', $report['idempotencyProjection'] ?? []);
$write($outDir . '/cable-spec-d3-readiness.json', $report['readinessProjection'] ?? []);

if ($verifyDeterministic) {
    $again = $service->resolve($resolveInputs);
    $a = $semanticComparePayload($report);
    $b = $semanticComparePayload($again);
    if (json_encode($a) !== json_encode($b)) {
        fwrite(STDERR, "DETERMINISM CHECK FAILED: semantic D.3 payloads differ\n");
        exit(1);
    }
    echo "Deterministic D.3 resolve verified.\n";
}

$baseline = $report['targetBaseline'] ?? [];
$proj = $report['styleFieldMappingProjection'] ?? [];
$apply = $report['applyProjection'] ?? [];
$idemp = $report['idempotencyProjection']['secondRun'] ?? [];
$readyProj = $report['readinessProjection']['projectedAfterFutureApply'] ?? [];
$packageStatus = (string) ($report['packageStatus'] ?? '');
$blocked = $packageStatus !== ResolveCableSpecBatchD3FinalDryRunService::PACKAGE_READY;

echo "PHASE 12.7 — BATCH D.3\nFINAL CABLE SPEC MAPPING DRY-RUN\nDRY-RUN ONLY\n\n";
echo "TARGET BASELINE\n\nDefinitions:\n" . (int) ($baseline['definitions'] ?? 0) . "\n\n";
echo "Style mappings:\n" . (int) ($baseline['styleFieldMappings'] ?? 0) . "\n\n";
echo "Drift:\n" . ((($baseline['driftDetected'] ?? false) === true) ? 'true' : 'false') . "\n\n";
echo "MAPPING PROJECTION\n\nEligible:\n" . (int) ($proj['eligible'] ?? 0) . "\n\n";
echo "To insert:\n" . (int) ($proj['toInsert'] ?? 0) . "\n\n";
echo "Identical existing:\n" . (int) ($proj['identicalExisting'] ?? 0) . "\n\n";
echo "Conflicts:\n" . (int) ($proj['conflicts'] ?? 0) . "\n\n";
echo "APPLY PROJECTION\n\nDefinitions create:\n" . (int) ($apply['definitionsToCreate'] ?? 0) . "\n\n";
echo "Mappings insert:\n" . (int) ($apply['mappingsToInsert'] ?? 0) . "\n\n";
echo "IDEMPOTENCY (second run)\n\nDefinitions create:\n" . (int) ($idemp['definitionsToCreate'] ?? 0) . "\n\n";
echo "Mappings insert:\n" . (int) ($idemp['mappingsToInsert'] ?? 0) . "\n\n";
echo "Mappings identical:\n" . (int) ($idemp['mappingsIdenticalExisting'] ?? 0) . "\n\n";
echo "READINESS PROJECTED\n\nCable READY:\n" . (int) ($readyProj['Cable READY'] ?? 0) . "\n\n";
echo "Cable REVIEW:\n" . (int) ($readyProj['Cable REVIEW'] ?? 0) . "\n\n";
echo "APPLY\n\nNOT APPROVED — " . CableSpecBatchD3CliGuard::APPLY_NOT_APPROVED . "\n\n";

if ($blocked) {
    echo "PHASE 12.7 BATCH D.3 FINAL CABLE MAPPING DRY-RUN: BLOCKED\n";
    echo "NOT READY FOR APPLY APPROVAL\n";
} else {
    echo "PHASE 12.7 BATCH D.3 FINAL CABLE MAPPING DRY-RUN: SUCCESS\n";
    echo "READY FOR EXPLICIT D.4 APPLY APPROVAL\n";
}
exit($blocked ? 2 : 0);
