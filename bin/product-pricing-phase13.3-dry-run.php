<?php

declare(strict_types=1);

/**
 * PHASE 13.3 — Legacy Base Cost Migration Dry Run
 *
 *   php bin/product-pricing-phase13.3-dry-run.php --confirm-non-production
 *
 * STRICT ZERO WRITES. Scope: dbco 2507 + 2517 only. 2519 excluded.
 * Refuses --apply / --execute / --write / --seed / --migrate.
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
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Pricing\BuildLegacyBaseCostDryRunService;
use TriCab\Core\Product\Migration\Pricing\DbcoPriceListMappingContract;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunCliGuard;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunResolution;
use TriCab\Core\Product\Migration\Pricing\Phase133DryRunCatalogueSnapshot;

ini_set('memory_limit', '512M');

$argvList = $argv ?? [];
$refuse = Phase133BaseCostDryRunCliGuard::refuseApply($argvList);
if ($refuse !== null) {
    fwrite(STDERR, $refuse . PHP_EOL);
    exit(3);
}
if (Phase133BaseCostDryRunCliGuard::requiresNonProductionConfirm($argvList)) {
    fwrite(STDERR, Phase133BaseCostDryRunCliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED . PHP_EOL);
    exit(3);
}

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Environment::settings($root);

$appEnv = (string) ($env['APP_ENV'] ?? '');
$dbHost = (string) ($env['DB_HOST'] ?? '');
$dbDatabase = (string) ($env['DB_DATABASE'] ?? '');
$isProd = in_array(strtolower($appEnv), ['production', 'prod'], true)
    || str_contains(strtolower($dbHost), 'prod');
if ($isProd) {
    fwrite(STDERR, "STOP: production target refused.\n");
    exit(2);
}
if (!str_contains(strtolower($dbHost), 'tricab-mel') || strtolower($dbDatabase) !== 'tc') {
    fwrite(STDERR, "STOP: unexpected Azure target {$dbHost}/{$dbDatabase}\n");
    exit(2);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$session = $db->fetchAll('SELECT @@SERVERNAME AS ServerName, DB_NAME() AS DbName, SUSER_SNAME() AS LoginName');
$serverName = (string) ($session[0]['ServerName'] ?? '');
$dbName = (string) ($session[0]['DbName'] ?? '');
$login = (string) ($session[0]['LoginName'] ?? '');

echo "Phase 13.3 dry-run safety: {$appEnv} {$dbHost}/{$dbDatabase} server={$serverName}\n";

// Reference data (SELECT only)
$extSys = $db->fetchAll("SELECT ExternalSystemId, SystemCode FROM [product].[ExternalSystems] WHERE SystemCode = N'legacy-tc'");
$priceLists = $db->fetchAll(<<<'SQL'
SELECT pl.PriceListId, pl.PriceListCode, pl.CurrencyId, c.CurrencyCode,
       eem.ExternalEntityKey AS Dbco
FROM [product].[PriceLists] pl
LEFT JOIN [product].[Currencies] c ON c.CurrencyId = pl.CurrencyId
LEFT JOIN [product].[ExternalEntityMappings] eem
  ON eem.InternalEntityId = pl.PriceListId
 AND eem.EntityTypeCode = N'dbco'
 AND eem.IsActive = 1
LEFT JOIN [product].[ExternalSystems] es ON es.ExternalSystemId = eem.ExternalSystemId AND es.SystemCode = N'legacy-tc'
WHERE pl.PriceListCode IN (N'TCA-BASE', N'TCUS-BASE', N'TCASIA-BASE')
SQL);

$priceListsByCode = [];
$refMode = Phase133BaseCostDryRunResolution::REF_CONTRACT_ONLY;
foreach ($priceLists as $pl) {
    $code = (string) $pl['PriceListCode'];
    $priceListsByCode[$code] = [
        'priceListId' => isset($pl['PriceListId']) ? (string) $pl['PriceListId'] : null,
        'currencyId' => isset($pl['CurrencyId']) ? (string) $pl['CurrencyId'] : null,
        'currencyCode' => (string) ($pl['CurrencyCode'] ?? ''),
        'dbco' => isset($pl['Dbco']) && $pl['Dbco'] !== null && $pl['Dbco'] !== '' ? (int) $pl['Dbco'] : 0,
    ];
    if (($pl['PriceListId'] ?? null) !== null) {
        $refMode = Phase133BaseCostDryRunResolution::REF_DATABASE_ID;
    }
}

// Contract fallback for missing lists
$dbcoContract = new DbcoPriceListMappingContract();
foreach ($dbcoContract->all() as $row) {
    if (!($row['phase133InScope'] ?? false)) {
        continue;
    }
    $code = $row['priceListCode'];
    if (!isset($priceListsByCode[$code])) {
        $priceListsByCode[$code] = [
            'priceListId' => null,
            'currencyId' => null,
            'currencyCode' => $row['currencyCode'],
            'dbco' => $row['dbco'],
        ];
        $refMode = Phase133BaseCostDryRunResolution::REF_CONTRACT_ONLY;
    }
}

$variants = $db->fetchAll(<<<'SQL'
SELECT CAST(ProductId AS VARCHAR(36)) AS ProductId,
       CAST(ProductVariantId AS VARCHAR(36)) AS ProductVariantId,
       ISNULL(VariantCode, '') AS VariantCode,
       ISNULL(Sku, '') AS Sku
FROM [product].[ProductVariants]
SQL);

$variantRows = [];
$idByPublic = [];
foreach ($variants as $v) {
    $vid = (string) $v['ProductVariantId'];
    $variantRows[] = [
        'productId' => (string) $v['ProductId'],
        'productVariantId' => $vid,
        'variantCode' => (string) $v['VariantCode'],
        'sku' => (string) $v['Sku'],
    ];
}

// Explicit mappings (if any)
$explicit = [];
try {
    $maps = $db->fetchAll(<<<'SQL'
SELECT CAST(eem.InternalEntityId AS VARCHAR(36)) AS VariantId,
       eem.ExternalEntityKey AS LegacyCode,
       eem.IsActive
FROM [product].[ExternalEntityMappings] eem
INNER JOIN [product].[ExternalSystems] es ON es.ExternalSystemId = eem.ExternalSystemId
WHERE es.SystemCode = N'legacy-tc'
  AND eem.EntityTypeCode IN (N'variant-price-code', N'legacy-price-code', N'VARIANT_PRICE_CODE')
  AND eem.IsActive = 1
SQL);
    foreach ($maps as $m) {
        $explicit[(string) $m['VariantId']] = strtoupper(trim((string) $m['LegacyCode']));
    }
} catch (Throwable $e) {
    // Table/filter may differ; continue with empty explicit map
    $explicit = [];
}

// Existing ProductVariantPrices (SELECT only)
$existingPrices = [];
try {
    $existing = $db->fetchAll(<<<'SQL'
SELECT CAST(vp.ProductVariantId AS VARCHAR(36)) AS ProductVariantId,
       pl.PriceListCode,
       vp.UnitPrice AS BaseCost,
       CONVERT(varchar(33), vp.EffectiveFromUtc, 127) AS EffectiveFromUtc,
       vp.PriceSourceCode
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = vp.PriceListId
WHERE vp.IsActive = 1
  AND (vp.EffectiveToUtc IS NULL OR vp.EffectiveToUtc > SYSUTCDATETIME())
SQL);
    foreach ($existing as $row) {
        $key = (string) $row['ProductVariantId'] . '|' . (string) $row['PriceListCode'];
        $existingPrices[$key] = [
            'priceListCode' => (string) $row['PriceListCode'],
            'currencyCode' => '',
            'baseCost' => (string) $row['BaseCost'],
            'effectiveFrom' => $row['EffectiveFromUtc'] !== null ? (string) $row['EffectiveFromUtc'] : null,
            'source' => (string) ($row['PriceSourceCode'] ?? ''),
        ];
    }
} catch (Throwable $e) {
    $existingPrices = [];
}

// Legacy tc.price via read-only guard
$config = LiveLegacySourceConfig::fromEnvironment($env);
$logger = new InMemoryLegacySqlStatementLogger();
$guard = new LegacyReadOnlySqlGuard();
$factory = new ReadOnlyMysqlConnectionFactory($guard, $logger);
$tc = $factory->create((string) $config->tcDsn, (string) $config->tcUser, (string) $config->tcPassword, 'tc', $config->tcSslMode);

// Prove mutation rejection
$forbiddenAttempts = 0;
foreach ([
    'INSERT INTO price (code) VALUES (\'x\')',
    'UPDATE price SET cost=1',
    'DELETE FROM price',
] as $badSql) {
    try {
        $guard->assertReadOnly($badSql);
        fwrite(STDERR, "FAIL: guard allowed mutation SQL\n");
        exit(4);
    } catch (Throwable) {
        ++$forbiddenAttempts;
    }
}

$legacyRows = $tc->query(
    'SELECT my_row_id, code, cost, dc, dbco, br, uwt, uwtcu, setup FROM `price` WHERE `dbco` IN (2507, 2517, 2519)',
    [],
    'phase133',
    'scanPrice',
);

$normalizedLegacy = [];
foreach ($legacyRows as $row) {
    $normalizedLegacy[] = [
        'sourceRowId' => (string) ($row['my_row_id'] ?? ''),
        'code' => (string) ($row['code'] ?? ''),
        'cost' => isset($row['cost']) && $row['cost'] !== null ? (string) $row['cost'] : null,
        'dc' => isset($row['dc']) && $row['dc'] !== null ? (string) $row['dc'] : null,
        'dbco' => (int) ($row['dbco'] ?? 0),
        'br' => isset($row['br']) ? (string) $row['br'] : null,
        'uwt' => isset($row['uwt']) ? (string) $row['uwt'] : null,
        'uwtcu' => isset($row['uwtcu']) ? (string) $row['uwtcu'] : null,
        'setup' => isset($row['setup']) ? (string) $row['setup'] : null,
    ];
}

$snapshot = new Phase133DryRunCatalogueSnapshot(
    legacyPriceRows: $normalizedLegacy,
    variants: $variantRows,
    explicitLegacyCodeByVariantId: $explicit,
    existingPrices: $existingPrices,
    priceListsByCode: $priceListsByCode,
    referenceResolutionMode: $refMode,
);

$service = new BuildLegacyBaseCostDryRunService();
$result = $service->execute($snapshot);
$summary = $result['summary'];
$summary['safety'] = [
    'APP_ENV' => $appEnv,
    'Server' => $serverName,
    'Database' => $dbName,
    'Login' => $login,
    'Production' => 'NO',
    'WordPress writes' => 0,
    'Legacy tc writes' => 0,
    'Azure ProductPrices writes' => 0,
    'Azure ProductVariantPrices writes' => 0,
    'Azure pricing writes total' => 0,
    'Blob writes' => 0,
    'Forbidden write attempts' => $forbiddenAttempts,
];
$summary['referenceData'] = [
    'ExternalSystem legacy-tc' => count($extSys) > 0 ? 'PRESENT' : 'MISSING',
    'TCA-BASE' => isset($priceListsByCode['TCA-BASE']['priceListId']) && $priceListsByCode['TCA-BASE']['priceListId'] !== null ? 'PRESENT' : 'CONTRACT_ONLY',
    'TCUS-BASE' => isset($priceListsByCode['TCUS-BASE']['priceListId']) && $priceListsByCode['TCUS-BASE']['priceListId'] !== null ? 'PRESENT' : 'CONTRACT_ONLY',
    'Resolution mode' => $refMode,
    'Seed required before apply' => $refMode !== Phase133BaseCostDryRunResolution::REF_DATABASE_ID,
];

$tmp = $root . '/tmp';
if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

$writeJson = static function (string $path, mixed $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
};

$writeJson($tmp . '/phase13.3-base-cost-dry-run-summary.json', $summary);

# Details: READY/REVIEW/BLOCKED + samples only (full unresolved volume is in counters)
$detailResolutions = [];
foreach ($result['resolutions'] as $r) {
    if (in_array($r->readiness, ['READY', 'REVIEW', 'BLOCKED'], true)
        || $r->precedenceDecision === 'MASK_SHADOWED_BY_EXACT'
        || $r->identityType === 'UNRESOLVED'
    ) {
        $detailResolutions[] = $r->toArray();
    }
}
$writeJson($tmp . '/phase13.3-base-cost-dry-run-details.json', [
    'resolutionCountMaterialized' => count($result['resolutions']),
    'detailRowsWritten' => count($detailResolutions),
    'resolutions' => $detailResolutions,
    'samples' => $result['details'],
]);

$unresolved = [];
foreach ($result['resolutions'] as $r) {
    if ($r->identityType === 'UNRESOLVED' || ($r->readiness === 'SKIPPED' && in_array('LEGACY_CODE_NOT_RELEVANT_TO_CURRENT_CATALOGUE', $r->reasons, true))) {
        $unresolved[] = [
            'normalizedCode' => $r->normalizedCode,
            'dbco' => $r->dbco,
            'reasons' => $r->reasons,
        ];
        if (count($unresolved) >= 500) {
            break;
        }
    }
}
$writeJson($tmp . '/phase13.3-base-cost-unresolved.json', ['countSampled' => count($unresolved), 'rows' => $unresolved]);

$zeros = [];
foreach ($result['resolutions'] as $r) {
    if ($r->zeroCostDecision === 'REVIEW_REQUIRED' || in_array('ZERO_COST', $r->reasons, true)) {
        $zeros[] = [
            'normalizedCode' => $r->normalizedCode,
            'dbco' => $r->dbco,
            'identityType' => $r->identityType,
            'priceListCode' => $r->priceListCode,
            'productVariantId' => $r->productVariantId,
            'readiness' => $r->readiness,
        ];
    }
}
$writeJson($tmp . '/phase13.3-base-cost-zero-review.json', ['count' => count($zeros), 'rows' => $zeros]);

$collisions = [];
foreach ($result['resolutions'] as $r) {
    if ($r->collisionDecision === 'TARGET_PRICE_COLLISION' || in_array('DIFFERING_SAME_DATE_COST', $r->reasons, true)) {
        $row = $r->toArray();
        unset($row['baseCost']);
        $collisions[] = $row;
    }
}
$writeJson($tmp . '/phase13.3-base-cost-collisions.json', ['count' => count($collisions), 'rows' => $collisions]);

echo json_encode([
    'status' => 'PHASE_13_3_DRY_RUN_COMPLETE',
    'final' => $summary['final'],
    'targetCoverage' => $summary['targetCoverage'],
    'referenceData' => $summary['referenceData'],
    'writes' => $summary['writes'],
    'forbiddenWriteAttempts' => $forbiddenAttempts,
], JSON_PRETTY_PRINT) . "\n";
