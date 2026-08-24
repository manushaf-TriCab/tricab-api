<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch D.1 — Cable Spec Legacy Inventory & Proposal Dry-Run.
 *
 *   php bin/resolve-cable-spec-field-mappings.php --confirm-non-production
 *   php bin/resolve-cable-spec-field-mappings.php --offline
 *   php bin/resolve-cable-spec-field-mappings.php --offline --verify-deterministic
 *
 * --apply / --execute / --write refused. No Definition / mapping / value / target writes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD1CliGuard;
use TriCab\Core\Product\Migration\Application\Service\CableSpecLegacyInventoryCollector;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecFieldMappingService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = CableSpecBatchD1CliGuard::refuseApply($argvList);
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

$stripGeneratedAt = static function (array $payload): array {
    unset($payload['generatedAtUtc']);

    return $payload;
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
$operator = $loadJson($outDir . '/cable-spec-operator-decisions.json');
$inventoryCache = $loadJson($outDir . '/cable-spec-live-stats-cache.json');

$azure = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'liveValidation' => 'UNAVAILABLE',
    'definitions' => [],
    'definitionCount' => 55,
    'unitCount' => 11,
    'optionCount' => 416,
    'styleFieldMappingCount' => 483,
    'fieldMappingCount' => 506,
    'productCount' => 118,
    'variantCount' => 4178,
];

$inventory = $stripGeneratedAt($inventoryCache);
$forbidden = 0;
$collector = new CableSpecLegacyInventoryCollector();

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
        $azure['productCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.Products WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
        $azure['variantCount'] = (int) ($sql->fetchAll('SELECT COUNT(*) c FROM product.ProductVariants WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Azure unavailable: ' . $e->getMessage() . "\n");
        $azure['liveValidation'] = 'UNAVAILABLE';
        if (is_array($inventoryCache['definitions'] ?? null) && $inventoryCache['definitions'] !== []) {
            $azure['definitions'] = $inventoryCache['definitions'];
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

        $query = static function (string $sql, array $params = []) use ($conn): array {
            return $conn->query($sql, $params, 'CableSpecDryRun', 'collect');
        };

        $inventory = $collector->collect($query, $styles);
        $liveCache = $inventory;
        $liveCache['generatedAtUtc'] = gmdate('c');
        $liveCache['definitions'] = $azure['definitions'];
        $liveCache['legacyWrites'] = 0;
        $write($outDir . '/cable-spec-live-stats-cache.json', $liveCache);
        echo 'Live cable_specs inventory refreshed (rows=' . $inventory['rowCount'] . ").\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Legacy tc unavailable — using cache if present: ' . $e->getMessage() . "\n");
        $inventory = $stripGeneratedAt($inventoryCache);
        if ($inventory === []) {
            fwrite(STDERR, "STOP: no cable-spec inventory available.\n");
            exit(5);
        }
    }
}

if ($inventory === []) {
    fwrite(STDERR, "STOP: cable-spec inventory empty. Run without --offline first.\n");
    exit(5);
}

$inventory = $stripGeneratedAt($inventory);
$fieldDistributions = is_array($inventory['fieldDistributions'] ?? null) ? $inventory['fieldDistributions'] : [];

/** @var ResolveCableSpecFieldMappingService $service */
$service = $container->get(ResolveCableSpecFieldMappingService::class);
$resolveInputs = [
    'azure' => $azure,
    'classificationReadyProducts' => is_array($ready['items'] ?? null) ? $ready['items'] : [],
    'cableSpecInventory' => $inventory,
    'fieldDistributions' => $fieldDistributions,
    'operatorDecisions' => is_array($operator['fields'] ?? null) ? $operator['fields'] : [],
    'forbiddenWriteAttempts' => $forbidden,
];
$report = $service->resolve($resolveInputs);

$write($outDir . '/cable-spec-final-dry-run-package.json', $report);
$write($outDir . '/cable-spec-field-inventory.json', $report['fieldInventory'] ?? []);
$write($outDir . '/cable-spec-concept-proposals.json', $report['conceptDecisions'] ?? []);
$write($outDir . '/cable-spec-proposed-field-mappings.json', $report['proposedFieldMappings'] ?? []);
$write($outDir . '/cable-spec-review.json', [
    'reviewReasons' => $report['reviewReasons'] ?? [],
    'concepts' => array_values(array_filter(
        $report['conceptDecisions']['items'] ?? [],
        static fn (array $c): bool => ($c['decision'] ?? '') === ResolveCableSpecFieldMappingService::REVIEW,
    )),
]);
$write($outDir . '/cable-spec-readiness.json', $report['readiness'] ?? []);
$write($outDir . '/cable-spec-special-investigations.json', $report['specialInvestigations'] ?? []);

$doc = <<<'MD'
# Phase 12.7 Batch D.1 — Cable Spec Legacy Inventory & Proposal Dry-Run

DRY-RUN ONLY. Guard: `CABLE_SPEC_FIELD_MAPPING_APPLY_NOT_AVAILABLE`.

Source: legacy MySQL `tc.cable_specs` (read-only). Target mutations: none.
Batch C/C.2 accessory mappings preserved (483 style mappings / 55 Definitions).
`od` → `NOM-CABLE-OD` (never `OUTER-DIAMETER`).
MD;
file_put_contents($docsRoot . '/product-phase12.7-cable-spec-field-mapping.md', $doc . PHP_EOL);

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

$pop = $report['cablePopulation'] ?? [];
$counts = $report['conceptDecisions']['counts'] ?? [];
$readyCounts = $report['readiness']['products'] ?? [];
$proj = $report['readiness']['PROJECTED'] ?? [];
$current = $report['readiness']['CURRENT'] ?? [];
$integrity = $report['batchC2Integrity'] ?? [];
$packageStatus = (string) ($report['packageStatus'] ?? '');
$blocked = $packageStatus !== 'READY_FOR_OPERATOR_REVIEW';

echo "PHASE 12.7 — BATCH D.1\nCABLE SPEC LEGACY INVENTORY & PROPOSAL DRY-RUN\nDRY-RUN ONLY\n\n";
echo "TARGET\n\nStatus:\n" . ($azure['liveValidation'] ?? 'UNKNOWN') . "\n\n";
echo "Definitions:\n" . (int) ($azure['definitionCount'] ?? 0) . "\n\n";
echo "Style mappings:\n" . (int) ($azure['styleFieldMappingCount'] ?? 0) . "\n\n";
echo "CABLE POPULATION\n\nexpected:\n" . (int) ($pop['expected'] ?? 54) . "\n\ndiscovered:\n" . (int) ($pop['discovered'] ?? 0) . "\n\nstatus:\n" . (string) ($pop['status'] ?? '') . "\n\n";
echo "CONCEPT DECISIONS\n\n";
foreach ([
    ResolveCableSpecFieldMappingService::PROPOSED_MAP_EXISTING,
    ResolveCableSpecFieldMappingService::PROPOSED_CREATE,
    ResolveCableSpecFieldMappingService::REVIEW,
    ResolveCableSpecFieldMappingService::NOT_A_SPECIFICATION,
    ResolveCableSpecFieldMappingService::REJECT,
] as $k) {
    echo $k . ":\n" . (int) ($counts[$k] ?? 0) . "\n\n";
}
echo "READINESS\n\nREADY:\n" . (int) ($readyCounts['READY'] ?? 0) . "\n\nREVIEW:\n" . (int) ($readyCounts['REVIEW'] ?? 0) . "\n\nBLOCKED:\n" . (int) ($readyCounts['BLOCKED'] ?? 0) . "\n\n";
echo "CURRENT ACCESSORY_DV_FIELD_MAPPING_READY:\n" . (int) ($current['ACCESSORY_DV_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "PROJECTED CABLE_SPEC_FIELD_MAPPING_READY:\n" . (int) ($proj['CABLE_SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "PROJECTED SPEC_FIELD_MAPPING_READY:\n" . (int) ($proj['SPEC_FIELD_MAPPING_READY'] ?? 0) . "\n\n";
echo "BATCH C/C.2 INTEGRITY\n\ndefs:\n" . (int) ($integrity['definitions'] ?? 0) . "\n\nstyleMaps:\n" . (int) ($integrity['styleMappings'] ?? 0) . "\n\ntotalMaps:\n" . (int) ($integrity['totalMappings'] ?? 0) . "\n\n";
echo "APPLY\n\nNOT AVAILABLE\n\n";

if ($blocked) {
    echo "PHASE 12.7 BATCH D.1 CABLE SPEC FIELD MAPPING DRY-RUN: BLOCKED\n";
    echo "NOT READY FOR OPERATOR REVIEW\n";
} else {
    echo "PHASE 12.7 BATCH D.1 CABLE SPEC FIELD MAPPING DRY-RUN: SUCCESS\n";
    echo "READY FOR OPERATOR REVIEW\n";
}
exit(0);
