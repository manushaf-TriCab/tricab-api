<?php

declare(strict_types=1);

/**
 * PHASE 13.4 — Controlled TCA Base Cost Pilot
 *
 * Dry-run (default):
 *   php bin/product-pricing-phase13.4-pilot.php --style=BVA --confirm-non-production
 *
 * Apply (requires all flags):
 *   php bin/product-pricing-phase13.4-pilot.php --style=BVA --max-variants=20 \
 *     --apply --confirm-non-production --confirm-pilot
 *
 * Never applies TCUS/TCASIA. Never exceeds 25 variants without hard stop.
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
use TriCab\Core\Product\Migration\Pricing\EnsureTcaPricingReferenceDataService;
use TriCab\Core\Product\Migration\Pricing\Phase133BaseCostDryRunResolution;
use TriCab\Core\Product\Migration\Pricing\Phase133DryRunCatalogueSnapshot;
use TriCab\Core\Product\Migration\Pricing\Phase134MigrationOwnership;
use TriCab\Core\Product\Migration\Pricing\Phase134PilotCliGuard;
use TriCab\Core\Product\Migration\Pricing\SelectTcaPilotCandidatesService;

ini_set('memory_limit', '512M');
$argvList = $argv ?? [];
$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$env = array_merge($_ENV, $_SERVER);

$style = Phase134PilotCliGuard::parseStyle($argvList);
if ($style === null) {
    fwrite(STDERR, Phase134PilotCliGuard::PILOT_STYLE_REQUIRED . PHP_EOL);
    exit(3);
}
$maxVariants = Phase134PilotCliGuard::parseMaxVariants($argvList, 20);
if ($maxVariants > Phase134PilotCliGuard::HARD_MAX) {
    fwrite(STDERR, Phase134PilotCliGuard::MAX_VARIANTS_EXCEEDED . PHP_EOL);
    exit(3);
}

$wantsApply = Phase134PilotCliGuard::wantsApply($argvList);
if ($wantsApply && !Phase134PilotCliGuard::applyConfirmationsOk($argvList)) {
    fwrite(STDERR, Phase134PilotCliGuard::APPLY_CONFIRMATIONS_REQUIRED . PHP_EOL);
    exit(3);
}
if (!in_array('--confirm-non-production', $argvList, true)) {
    fwrite(STDERR, "NON_PRODUCTION_CONFIRMATION_REQUIRED\n");
    exit(3);
}

$appEnv = (string) ($env['APP_ENV'] ?? '');
$dbHost = (string) ($env['DB_HOST'] ?? '');
$dbDatabase = (string) ($env['DB_DATABASE'] ?? '');
$isProd = in_array(strtolower($appEnv), ['production', 'prod'], true)
    || str_contains(strtolower($dbHost), 'prod');
if ($isProd || !str_contains(strtolower($dbHost), 'tricab-mel') || strtolower($dbDatabase) !== 'tc') {
    fwrite(STDERR, "STOP: environment not approved for Phase 13.4\n");
    exit(2);
}

$settings = Environment::settings($root);
$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);
$session = $db->fetchAll('SELECT @@SERVERNAME AS ServerName, DB_NAME() AS DbName, SUSER_SNAME() AS LoginName');
$serverName = (string) ($session[0]['ServerName'] ?? '');
$dbName = (string) ($session[0]['DbName'] ?? '');
if (strtolower($serverName) !== 'tricab-mel' || strtolower($dbName) !== 'tc') {
    fwrite(STDERR, "STOP: unexpected @@SERVERNAME/DB_NAME\n");
    exit(2);
}

echo "Phase 13.4 pilot safety OK: {$appEnv} {$serverName}/{$dbName} style={$style}\n";

// --- Reference plan ---
$extSys = $db->fetchAll("SELECT TOP 1 ExternalSystemId FROM [product].[ExternalSystems] WHERE SystemCode=N'legacy-tc' AND DeletedAtUtc IS NULL");
$aud = $db->fetchAll("SELECT TOP 1 CurrencyId, IsActive, DeletedAtUtc, CONVERT(NVARCHAR(36), PublicId) AS PublicId FROM [product].[Currencies] WHERE CurrencyCode=N'AUD'");
$tca = $db->fetchAll(<<<'SQL'
SELECT pl.PriceListId, pl.PriceListCode, pl.IsActive, pl.DeletedAtUtc,
       c.CurrencyCode, CONVERT(NVARCHAR(36), pl.PublicId) AS PublicId,
       eem.ExternalEntityKey AS Dbco
FROM [product].[PriceLists] pl
LEFT JOIN [product].[Currencies] c ON c.CurrencyId = pl.CurrencyId
LEFT JOIN [product].[ExternalEntityMappings] eem
  ON eem.InternalEntityId = pl.PriceListId AND eem.EntityTypeCode=N'dbco' AND eem.IsActive=1 AND eem.DeletedAtUtc IS NULL
LEFT JOIN [product].[ExternalSystems] es ON es.ExternalSystemId = eem.ExternalSystemId AND es.SystemCode=N'legacy-tc'
WHERE pl.PriceListCode = N'TCA-BASE'
SQL);

$refState = [
    'externalSystemPresent' => $extSys !== [],
    'audCurrencyPresent' => $aud !== [],
    'audCurrencyActive' => $aud !== [] && (int) ($aud[0]['IsActive'] ?? 0) === 1 && ($aud[0]['DeletedAtUtc'] ?? null) === null,
    'tcaBase' => $tca === [] ? null : [
        'currencyCode' => (string) ($tca[0]['CurrencyCode'] ?? ''),
        'isActive' => (int) ($tca[0]['IsActive'] ?? 0) === 1,
        'deleted' => ($tca[0]['DeletedAtUtc'] ?? null) !== null,
        'dbcoMapped' => isset($tca[0]['Dbco']) && $tca[0]['Dbco'] !== null ? (string) $tca[0]['Dbco'] : null,
        'priceListPublicId' => (string) ($tca[0]['PublicId'] ?? ''),
    ],
];
$refPlan = (new EnsureTcaPricingReferenceDataService())->plan($refState);

// --- Build Phase 13.3 candidates (reuse engine) ---
$variants = $db->fetchAll(<<<'SQL'
SELECT CONVERT(NVARCHAR(36), ProductId) AS ProductId,
       CONVERT(NVARCHAR(36), ProductVariantId) AS ProductVariantId,
       CONVERT(NVARCHAR(36), PublicId) AS VariantPublicId,
       ISNULL(VariantCode,'') AS VariantCode,
       ISNULL(Sku,'') AS Sku
FROM [product].[ProductVariants]
WHERE DeletedAtUtc IS NULL
SQL);
// ProductId may not have PublicId on variants join — fetch product public ids
$productPubs = [];
foreach ($db->fetchAll('SELECT CONVERT(NVARCHAR(36), ProductId) AS ProductId, CONVERT(NVARCHAR(36), PublicId) AS PublicId FROM [product].[Products]') as $p) {
    $productPubs[(string) $p['ProductId']] = (string) $p['PublicId'];
}

$variantRows = [];
$variantMeta = [];
foreach ($variants as $v) {
    $vid = (string) $v['ProductVariantId'];
    $variantRows[] = [
        'productId' => (string) $v['ProductId'],
        'productVariantId' => $vid,
        'variantCode' => (string) $v['VariantCode'],
        'sku' => (string) $v['Sku'],
    ];
    $variantMeta[$vid] = [
        'variantCode' => (string) $v['VariantCode'],
        'variantPublicId' => (string) $v['VariantPublicId'],
        'productPublicId' => $productPubs[(string) $v['ProductId']] ?? '',
    ];
}

$config = LiveLegacySourceConfig::fromEnvironment($env);
$guard = new LegacyReadOnlySqlGuard();
$tc = (new ReadOnlyMysqlConnectionFactory($guard, new InMemoryLegacySqlStatementLogger()))
    ->create((string) $config->tcDsn, (string) $config->tcUser, (string) $config->tcPassword, 'tc', $config->tcSslMode);

$legacyRowsRaw = $tc->query(
    'SELECT my_row_id, code, cost, dc, dbco, br, uwt, uwtcu, setup FROM `price` WHERE `dbco` IN (2507, 2517, 2519)',
    [],
    'phase134',
    'scan',
);
$legacyRows = [];
foreach ($legacyRowsRaw as $row) {
    $legacyRows[] = [
        'sourceRowId' => (string) ($row['my_row_id'] ?? ''),
        'code' => (string) ($row['code'] ?? ''),
        'cost' => isset($row['cost']) && $row['cost'] !== null ? (string) $row['cost'] : null,
        'dc' => isset($row['dc']) ? (string) $row['dc'] : null,
        'dbco' => (int) ($row['dbco'] ?? 0),
        'br' => isset($row['br']) ? (string) $row['br'] : null,
        'uwt' => isset($row['uwt']) ? (string) $row['uwt'] : null,
        'uwtcu' => isset($row['uwtcu']) ? (string) $row['uwtcu'] : null,
        'setup' => isset($row['setup']) ? (string) $row['setup'] : null,
    ];
}

$snapshot = new Phase133DryRunCatalogueSnapshot(
    legacyPriceRows: $legacyRows,
    variants: $variantRows,
    referenceResolutionMode: Phase133BaseCostDryRunResolution::REF_CONTRACT_ONLY,
);
$dry = (new BuildLegacyBaseCostDryRunService())->execute($snapshot);
$selected = (new SelectTcaPilotCandidatesService())->select(
    $dry['resolutions'],
    $variantMeta,
    $style,
    $maxVariants,
);

// Compact batch id (fits ErpSourceReference NVARCHAR(100) with ownership payload)
$batchId = 'p134-tca-' . $style . '-' . gmdate('Ymd\THis');
$tmp = $root . '/tmp';
if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

$countSql = static function (SqlConnection $db): array {
    $rows = $db->fetchAll(<<<'SQL'
SELECT
  (SELECT COUNT(*) FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL) AS ProductVariantPrices,
  (SELECT COUNT(*) FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL) AS ProductPrices,
  (SELECT COUNT(*) FROM [product].[CustomerProductPrices] WHERE DeletedAtUtc IS NULL) AS CustomerProductPrices,
  (SELECT COUNT(*) FROM [product].[PricingTiers] WHERE DeletedAtUtc IS NULL) AS PricingTiers,
  (SELECT COUNT(*) FROM [product].[PriceListCustomers]) AS PriceListCustomers
SQL);
    $r = $rows[0] ?? [];

    return [
        'ProductVariantPrices' => (int) ($r['ProductVariantPrices'] ?? 0),
        'ProductPrices' => (int) ($r['ProductPrices'] ?? 0),
        'CustomerProductPrices' => (int) ($r['CustomerProductPrices'] ?? 0),
        'PricingTiers' => (int) ($r['PricingTiers'] ?? 0),
        'PriceListCustomers' => (int) ($r['PriceListCustomers'] ?? 0),
    ];
};
$countsBefore = $countSql($db);

$report = [
    'phase' => '13.4',
    'mode' => $wantsApply ? 'APPLY' : 'DRY_RUN',
    'safety' => [
        'APP_ENV' => $appEnv,
        'Server' => $serverName,
        'Database' => $dbName,
        'Production' => 'NO',
    ],
    'referencePlan' => $refPlan,
    'referenceState' => [
        'legacy-tc' => $refState['externalSystemPresent'] ? 'PRESENT' : 'MISSING',
        'AUD' => $refState['audCurrencyActive'] ? 'ACTIVE' : 'INVALID',
        'TCA-BASE' => $refState['tcaBase'] === null ? 'MISSING' : 'PRESENT',
    ],
    'pilot' => [
        'batchId' => $batchId,
        'style' => $style,
        'maxVariants' => $maxVariants,
        'candidateStatus' => $selected['status'],
        'preview' => $selected['preview'],
        'candidateCount' => count($selected['candidates']),
        'candidates' => array_map(static function ($r) use ($variantMeta) {
            return [
                'variantId' => $r->productVariantId,
                'variantPublicId' => $variantMeta[$r->productVariantId]['variantPublicId'] ?? null,
                'legacyCode' => $r->normalizedCode,
                'identityType' => $r->identityType,
                'effectiveFrom' => $r->effectiveFrom,
                'sourceRowIds' => $r->sourceRowIds,
                'candidateHash' => Phase134MigrationOwnership::candidateHash(
                    $r->normalizedCode,
                    2507,
                    $r->effectiveFrom !== null ? substr($r->effectiveFrom, 0, 10) : null,
                    $r->baseCost,
                    (string) $r->productVariantId,
                    'TCA-BASE',
                ),
                // cost omitted from console-oriented summary fields
            ];
        }, $selected['candidates']),
    ],
];

$blockedReasons = [];
if ($selected['status'] !== 'PILOT_CANDIDATES_READY') {
    $blockedReasons[] = $selected['status'];
}
if ($refPlan['status'] === EnsureTcaPricingReferenceDataService::CONFLICT
    || $refPlan['status'] === EnsureTcaPricingReferenceDataService::BLOCKED
) {
    $blockedReasons[] = 'REFERENCE_' . $refPlan['status'];
}
if (count($selected['candidates']) === 0) {
    $blockedReasons[] = 'NO_CANDIDATES';
}

if ($blockedReasons !== []) {
    $report['decision'] = 'PILOT_APPLY_BLOCKED';
    $report['blockedReasons'] = $blockedReasons;
    file_put_contents($tmp . '/phase13.4-tca-pilot-preview.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo json_encode([
        'decision' => 'PILOT_APPLY_BLOCKED',
        'reasons' => $blockedReasons,
        'preview' => $selected['preview'],
        'referencePlan' => $refPlan,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$report['decision'] = 'PILOT_APPLY_READY';
$report['note'] = 'Dry-run complete. Apply requires --apply --confirm-non-production --confirm-pilot. Reference seed may still be required before apply.';
file_put_contents($tmp . '/phase13.4-tca-pilot-preview.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode([
    'decision' => 'PILOT_APPLY_READY',
    'batchIdProposed' => $batchId,
    'style' => $style,
    'variantCount' => count($selected['candidates']),
    'preview' => $selected['preview'],
    'referencePlan' => $refPlan,
    'referenceState' => $report['referenceState'],
    'applyRequested' => $wantsApply,
], JSON_PRETTY_PRINT) . "\n";

if (!$wantsApply) {
    echo "STOP — DRY RUN ONLY. Wait for explicit apply approval.\n";
    exit(0);
}

// --- APPLY path ---
fwrite(STDERR, "APPLY path starting (TCA BVA pilot only).\n");

$pdo = $c->get(PDO::class);
$actorUserId = 1; // confirmed present in identity.Users
$txnStartedAt = gmdate('c');
$referenceActionsDone = [];

try {
    $pdo->beginTransaction();

    // Scoped TCA reference seed (idempotent) — TCA only
    $seedActions = $refPlan['actions'] ?? [];
    if ($refPlan['status'] === EnsureTcaPricingReferenceDataService::CREATED
        || $refPlan['status'] === EnsureTcaPricingReferenceDataService::NO_CHANGE
    ) {
        // Always ensure via IF NOT EXISTS (idempotent even if plan said NO_CHANGE)
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM [product].[ExternalSystems] WHERE SystemCode=N'legacy-tc' AND DeletedAtUtc IS NULL)
            INSERT INTO [product].[ExternalSystems] (SystemCode, SystemName, Description, IsActive, CreatedByUserId, UpdatedByUserId)
            VALUES (N'legacy-tc', N'Legacy TC MySQL', N'Read-only legacy tc', 1, {$actorUserId}, {$actorUserId})");
        if (in_array('CREATE_EXTERNAL_SYSTEM_legacy-tc', $seedActions, true)) {
            $referenceActionsDone[] = 'CREATE_EXTERNAL_SYSTEM_legacy-tc';
        } else {
            $referenceActionsDone[] = 'NO_CHANGE_EXTERNAL_SYSTEM_legacy-tc';
        }

        $audId = (int) $aud[0]['CurrencyId'];
        // sqlsrv PDO rejects repeated named placeholders — use distinct names.
        $stmt = $pdo->prepare("IF NOT EXISTS (SELECT 1 FROM [product].[PriceLists] WHERE PriceListCode=N'TCA-BASE' AND DeletedAtUtc IS NULL)
            INSERT INTO [product].[PriceLists] (PriceListCode, PriceListName, CurrencyId, IsActive, CreatedByUserId, UpdatedByUserId)
            VALUES (N'TCA-BASE', N'Australia Base Cost', :c, 1, :u1, :u2)");
        $stmt->execute([':c' => $audId, ':u1' => $actorUserId, ':u2' => $actorUserId]);
        if (in_array('CREATE_PRICELIST_TCA-BASE', $seedActions, true)) {
            $referenceActionsDone[] = 'CREATE_PRICELIST_TCA-BASE';
        } else {
            $referenceActionsDone[] = 'NO_CHANGE_PRICELIST_TCA-BASE';
        }

        $sysId = (int) $pdo->query("SELECT TOP 1 ExternalSystemId FROM [product].[ExternalSystems] WHERE SystemCode=N'legacy-tc' AND DeletedAtUtc IS NULL")->fetchColumn();
        $plIdTmp = (int) $pdo->query("SELECT TOP 1 PriceListId FROM [product].[PriceLists] WHERE PriceListCode=N'TCA-BASE' AND DeletedAtUtc IS NULL")->fetchColumn();
        $stmt = $pdo->prepare("IF NOT EXISTS (
            SELECT 1 FROM [product].[ExternalEntityMappings]
            WHERE ExternalSystemId=:s AND EntityTypeCode=N'dbco' AND ExternalEntityKey=N'2507' AND DeletedAtUtc IS NULL
        ) INSERT INTO [product].[ExternalEntityMappings]
            (ExternalSystemId, EntityTypeCode, InternalEntityId, ExternalEntityKey, IsActive, CreatedByUserId, UpdatedByUserId)
          VALUES (:s2, N'dbco', :pl, N'2507', 1, :u1, :u2)");
        $stmt->execute([
            ':s' => $sysId,
            ':s2' => $sysId,
            ':pl' => $plIdTmp,
            ':u1' => $actorUserId,
            ':u2' => $actorUserId,
        ]);
        if (in_array('CREATE_MAPPING_dbco_2507', $seedActions, true)) {
            $referenceActionsDone[] = 'CREATE_MAPPING_dbco_2507';
        } else {
            $referenceActionsDone[] = 'NO_CHANGE_MAPPING_dbco_2507';
        }
    }

    // Prove reference read-back inside transaction
    $refProof = $pdo->query(<<<'SQL'
SELECT
  (SELECT TOP 1 CASE WHEN IsActive=1 AND DeletedAtUtc IS NULL THEN N'ACTIVE' ELSE N'INVALID' END
     FROM [product].[ExternalSystems] WHERE SystemCode=N'legacy-tc') AS LegacyTc,
  (SELECT TOP 1 CASE WHEN pl.IsActive=1 AND pl.DeletedAtUtc IS NULL THEN N'ACTIVE' ELSE N'INVALID' END
     FROM [product].[PriceLists] pl WHERE pl.PriceListCode=N'TCA-BASE') AS TcaBase,
  (SELECT TOP 1 c.CurrencyCode FROM [product].[PriceLists] pl
     INNER JOIN [product].[Currencies] c ON c.CurrencyId=pl.CurrencyId
     WHERE pl.PriceListCode=N'TCA-BASE' AND pl.DeletedAtUtc IS NULL) AS TcaCurrency,
  (SELECT TOP 1 eem.ExternalEntityKey
     FROM [product].[ExternalEntityMappings] eem
     INNER JOIN [product].[ExternalSystems] es ON es.ExternalSystemId=eem.ExternalSystemId
     INNER JOIN [product].[PriceLists] pl ON pl.PriceListId=eem.InternalEntityId
     WHERE es.SystemCode=N'legacy-tc' AND eem.EntityTypeCode=N'dbco'
       AND eem.ExternalEntityKey=N'2507' AND eem.DeletedAtUtc IS NULL
       AND pl.PriceListCode=N'TCA-BASE') AS DbcoMap,
  (SELECT COUNT(*) FROM [product].[PriceLists] WHERE PriceListCode IN (N'TCUS-BASE',N'TCASIA-BASE') AND DeletedAtUtc IS NULL) AS ForbiddenLists,
  (SELECT COUNT(*) FROM [product].[ExternalEntityMappings] eem
     INNER JOIN [product].[ExternalSystems] es ON es.ExternalSystemId=eem.ExternalSystemId
     WHERE es.SystemCode=N'legacy-tc' AND eem.EntityTypeCode=N'dbco'
       AND eem.ExternalEntityKey IN (N'2517',N'2519') AND eem.DeletedAtUtc IS NULL) AS ForbiddenMaps
SQL)->fetch(PDO::FETCH_ASSOC);

    if (($refProof['LegacyTc'] ?? '') !== 'ACTIVE'
        || ($refProof['TcaBase'] ?? '') !== 'ACTIVE'
        || strtoupper((string) ($refProof['TcaCurrency'] ?? '')) !== 'AUD'
        || (string) ($refProof['DbcoMap'] ?? '') !== '2507'
        || (int) ($refProof['ForbiddenLists'] ?? 0) !== 0
        || (int) ($refProof['ForbiddenMaps'] ?? 0) !== 0
    ) {
        throw new RuntimeException('REFERENCE_CONFLICT: ' . json_encode($refProof));
    }

    $plRow = $pdo->query("SELECT TOP 1 pl.PriceListId, pl.CurrencyId, c.CurrencyCode
        FROM [product].[PriceLists] pl
        INNER JOIN [product].[Currencies] c ON c.CurrencyId = pl.CurrencyId
        WHERE pl.PriceListCode=N'TCA-BASE' AND pl.DeletedAtUtc IS NULL AND pl.IsActive=1")->fetch(PDO::FETCH_ASSOC);
    if ($plRow === false || strtoupper((string) $plRow['CurrencyCode']) !== 'AUD') {
        throw new RuntimeException('REFERENCE_CONFLICT: TCA-BASE/AUD');
    }
    $priceListId = (int) $plRow['PriceListId'];
    $currencyId = (int) $plRow['CurrencyId'];

    $created = 0;
    $noChange = 0;
    $reconRows = [];
    $writtenPublicIds = [];

    if (count($selected['candidates']) !== $maxVariants) {
        throw new RuntimeException(
            'CANDIDATE_COUNT_MISMATCH: expected ' . $maxVariants . ' got ' . count($selected['candidates'])
        );
    }

    foreach ($selected['candidates'] as $cand) {
        if ($cand->identityType !== 'EXACT' || $cand->dbco !== 2507 || $cand->priceListCode !== 'TCA-BASE') {
            throw new RuntimeException('IDENTITY_NOT_EXACT_TCA: ' . $cand->normalizedCode);
        }
        if (bccomp($cand->baseCost, '0', 6) !== 1) {
            throw new RuntimeException('ZERO_OR_NEGATIVE_COST: ' . $cand->normalizedCode);
        }
        // Precision: reject more than 6 decimal places
        if (preg_match('/\.(\d{7,})$/', $cand->baseCost) === 1) {
            throw new RuntimeException('PRICE_PRECISION_OVERFLOW: ' . $cand->normalizedCode);
        }

        $vid = (string) $cand->productVariantId;
        $candDc = $cand->effectiveFrom !== null ? substr($cand->effectiveFrom, 0, 10) : null;
        $expectedHash = Phase134MigrationOwnership::candidateHash(
            $cand->normalizedCode,
            2507,
            $candDc,
            $cand->baseCost,
            $vid,
            'TCA-BASE',
        );

        // live source drift check
        $src = $tc->query(
            'SELECT my_row_id, cost, dc FROM `price` WHERE UPPER(TRIM(code)) = :c AND dbco = 2507 ORDER BY dc DESC LIMIT 10',
            ['c' => $cand->normalizedCode],
            'phase134',
            'drift',
        );
        if ($src === []) {
            throw new RuntimeException('SOURCE_CHANGED_SINCE_DRY_RUN: missing ' . $cand->normalizedCode);
        }
        $latestDc = null;
        foreach ($src as $s) {
            $dc = (string) ($s['dc'] ?? '');
            if ($latestDc === null || strcmp($dc, $latestDc) > 0) {
                $latestDc = $dc;
            }
        }
        $latestCosts = [];
        $latestRowIds = [];
        foreach ($src as $s) {
            if ((string) ($s['dc'] ?? '') === $latestDc) {
                $latestCosts[(string) $s['cost']] = true;
                $latestRowIds[] = (string) ($s['my_row_id'] ?? '');
            }
        }
        if (count($latestCosts) !== 1) {
            throw new RuntimeException('SOURCE_CHANGED_SINCE_DRY_RUN: ambiguous latest ' . $cand->normalizedCode);
        }
        $liveCost = (string) array_key_first($latestCosts);
        $liveDc = $latestDc !== null ? substr($latestDc, 0, 10) : null;
        if (bccomp($liveCost, $cand->baseCost, 6) !== 0 || $liveDc !== $candDc) {
            throw new RuntimeException('SOURCE_CHANGED_SINCE_DRY_RUN: ' . $cand->normalizedCode);
        }
        $liveHash = Phase134MigrationOwnership::candidateHash(
            $cand->normalizedCode,
            2507,
            $liveDc,
            $liveCost,
            $vid,
            'TCA-BASE',
        );
        if ($liveHash !== $expectedHash) {
            throw new RuntimeException('SOURCE_CHANGED_SINCE_DRY_RUN: hash ' . $cand->normalizedCode);
        }

        $existing = $pdo->prepare("SELECT TOP 2 ProductVariantPriceId, CONVERT(NVARCHAR(36), PublicId) AS PublicId,
            CONVERT(NVARCHAR(50), UnitPrice) AS UnitPrice, ErpSourceReference, IsActive, DeletedAtUtc,
            PriceListId, CurrencyId,
            CONVERT(varchar(33), EffectiveFromUtc, 126) AS EffectiveFromUtc,
            CONVERT(varchar(33), EffectiveToUtc, 126) AS EffectiveToUtc
            FROM [product].[ProductVariantPrices]
            WHERE ProductVariantId = :v AND PriceListId = :pl AND DeletedAtUtc IS NULL AND IsActive = 1
              AND EffectiveToUtc IS NULL");
        $existing->execute([':v' => $vid, ':pl' => $priceListId]);
        $exRows = $existing->fetchAll(PDO::FETCH_ASSOC);
        if (count($exRows) > 1) {
            throw new RuntimeException('TARGET_DUPLICATE_ACTIVE: ' . $cand->normalizedCode);
        }
        $ex = $exRows[0] ?? false;
        $erpRef = Phase134MigrationOwnership::encode(
            $batchId,
            $cand->normalizedCode,
            2507,
            $candDc,
            $cand->baseCost,
            $cand->sourceRowIds,
        );

        if ($ex !== false) {
            $own = Phase134MigrationOwnership::decode($ex['ErpSourceReference'] ?? null);
            if (!($own['isPilotOwned'] ?? false)) {
                throw new RuntimeException('MANUAL_DATA_CONFLICT: ' . $cand->normalizedCode);
            }
            if (bccomp((string) $ex['UnitPrice'], $cand->baseCost, 6) === 0
                && (int) $ex['CurrencyId'] === $currencyId
            ) {
                ++$noChange;
                $writtenPublicIds[] = (string) $ex['PublicId'];
                $reconRows[] = [
                    'variantId' => $vid,
                    'legacyCode' => $cand->normalizedCode,
                    'status' => 'NO_CHANGE',
                    'targetPriceId' => $ex['PublicId'],
                    'sourceCost' => $cand->baseCost,
                    'targetBaseCost' => (string) $ex['UnitPrice'],
                    'sourceDc' => $candDc,
                    'targetEffectiveFrom' => $ex['EffectiveFromUtc'],
                    'sourceRowIds' => $cand->sourceRowIds,
                    'candidateHash' => $expectedHash,
                    'legacySourceStatus' => 'MATCH',
                ];
                continue;
            }
            throw new RuntimeException('SOURCE_CHANGED_REVIEW: ' . $cand->normalizedCode);
        }

        $effFromSql = $cand->effectiveFrom !== null
            ? str_replace(['Z', 'T'], ['', ' '], substr($cand->effectiveFrom, 0, 19))
            : null;

        $ins = $pdo->prepare("INSERT INTO [product].[ProductVariantPrices] (
            ProductVariantId, PriceListId, CurrencyId, MinimumQuantity, UnitPrice, TaxInclusive,
            EffectiveFromUtc, EffectiveToUtc, ErpSourceReference, IsActive, CreatedByUserId, UpdatedByUserId
        ) OUTPUT CONVERT(NVARCHAR(36), INSERTED.PublicId) AS PublicId,
                 CONVERT(NVARCHAR(50), INSERTED.UnitPrice) AS UnitPrice,
                 INSERTED.ProductVariantId AS ProductVariantId,
                 INSERTED.PriceListId AS PriceListId,
                 INSERTED.CurrencyId AS CurrencyId,
                 CONVERT(varchar(33), INSERTED.EffectiveFromUtc, 126) AS EffectiveFromUtc,
                 CONVERT(varchar(33), INSERTED.EffectiveToUtc, 126) AS EffectiveToUtc,
                 INSERTED.ErpSourceReference AS ErpSourceReference
        VALUES (
            :v, :pl, :cur, 1, :cost, 0,
            :effFrom, NULL, :erp, 1, :u1, :u2
        )");
        $ins->execute([
            ':v' => $vid,
            ':pl' => $priceListId,
            ':cur' => $currencyId,
            ':cost' => $cand->baseCost,
            ':effFrom' => $effFromSql,
            ':erp' => $erpRef,
            ':u1' => $actorUserId,
            ':u2' => $actorUserId,
        ]);
        $out = $ins->fetch(PDO::FETCH_ASSOC);
        if ($out === false) {
            throw new RuntimeException('RECONCILIATION_FAILED: no OUTPUT ' . $cand->normalizedCode);
        }
        // Pre-commit row reconciliation
        if ((string) $out['ProductVariantId'] !== $vid
            || (int) $out['PriceListId'] !== $priceListId
            || (int) $out['CurrencyId'] !== $currencyId
            || bccomp((string) $out['UnitPrice'], $cand->baseCost, 6) !== 0
            || $out['EffectiveToUtc'] !== null
            || (string) $out['ErpSourceReference'] !== $erpRef
        ) {
            throw new RuntimeException('RECONCILIATION_FAILED: ' . $cand->normalizedCode);
        }
        $outFrom = $out['EffectiveFromUtc'] !== null ? substr((string) $out['EffectiveFromUtc'], 0, 10) : null;
        if ($candDc !== null && $outFrom !== $candDc) {
            throw new RuntimeException('RECONCILIATION_FAILED: EffectiveFromUtc ' . $cand->normalizedCode);
        }

        ++$created;
        $writtenPublicIds[] = (string) $out['PublicId'];
        $reconRows[] = [
            'variantId' => $vid,
            'legacyCode' => $cand->normalizedCode,
            'status' => 'CREATE',
            'targetPriceId' => $out['PublicId'],
            'sourceCost' => $cand->baseCost,
            'targetBaseCost' => (string) $out['UnitPrice'],
            'sourceDc' => $candDc,
            'targetEffectiveFrom' => $out['EffectiveFromUtc'],
            'sourceRowIds' => $cand->sourceRowIds,
            'candidateHash' => $expectedHash,
            'legacySourceStatus' => 'MATCH',
        ];
    }

    // Pre-commit: re-read all written rows
    if (count($writtenPublicIds) !== count($selected['candidates'])) {
        throw new RuntimeException('RECONCILIATION_FAILED: written count mismatch');
    }
    $preCommitExact = 0;
    $preCommitMismatch = 0;
    foreach ($reconRows as $rr) {
        $chk = $pdo->prepare("SELECT CONVERT(NVARCHAR(50), UnitPrice) AS UnitPrice,
            ProductVariantId, PriceListId, CurrencyId,
            CONVERT(varchar(33), EffectiveFromUtc, 126) AS EffectiveFromUtc,
            EffectiveToUtc, ErpSourceReference
            FROM [product].[ProductVariantPrices]
            WHERE PublicId = :p AND DeletedAtUtc IS NULL");
        $chk->execute([':p' => $rr['targetPriceId']]);
        $got = $chk->fetch(PDO::FETCH_ASSOC);
        if ($got === false
            || bccomp((string) $got['UnitPrice'], (string) $rr['sourceCost'], 6) !== 0
            || (string) $got['ProductVariantId'] !== (string) $rr['variantId']
            || (int) $got['PriceListId'] !== $priceListId
            || (int) $got['CurrencyId'] !== $currencyId
            || $got['EffectiveToUtc'] !== null
        ) {
            ++$preCommitMismatch;
        } else {
            ++$preCommitExact;
        }
    }
    if ($preCommitMismatch !== 0 || $preCommitExact !== count($selected['candidates'])) {
        throw new RuntimeException('RECONCILIATION_FAILED: pre-commit mismatches=' . $preCommitMismatch);
    }

    $pdo->commit();
    $txnCommittedAt = gmdate('c');

    // Post-commit reconciliation
    $countsAfter = $countSql($db);
    $postExact = 0;
    $postMismatch = 0;
    $postMissing = 0;
    $legacyMatch = 0;
    $legacyChanged = 0;
    $legacyMissing = 0;
    $dupCheck = $db->fetchAll(
        'SELECT ProductVariantId, COUNT(*) AS cnt FROM [product].[ProductVariantPrices]
         WHERE PriceListId = :pl AND DeletedAtUtc IS NULL AND IsActive = 1 AND EffectiveToUtc IS NULL
           AND ProductVariantId IN (' . implode(',', array_map(static fn ($r) => (int) $r->productVariantId, $selected['candidates'])) . ')
         GROUP BY ProductVariantId HAVING COUNT(*) > 1',
        ['pl' => $priceListId],
    );

    foreach ($reconRows as &$rr) {
        $chk = $db->fetchAll(
            "SELECT CONVERT(NVARCHAR(50), UnitPrice) AS UnitPrice, ProductVariantId, PriceListId, CurrencyId,
                    CONVERT(varchar(33), EffectiveFromUtc, 126) AS EffectiveFromUtc, EffectiveToUtc, ErpSourceReference
             FROM [product].[ProductVariantPrices] WHERE PublicId = :p AND DeletedAtUtc IS NULL",
            ['p' => $rr['targetPriceId']],
        );
        if ($chk === []) {
            ++$postMissing;
            $rr['postCommit'] = 'MISSING';
            continue;
        }
        $got = $chk[0];
        $ok = bccomp((string) $got['UnitPrice'], (string) $rr['sourceCost'], 6) === 0
            && (string) $got['ProductVariantId'] === (string) $rr['variantId']
            && (int) $got['PriceListId'] === $priceListId
            && (int) $got['CurrencyId'] === $currencyId
            && $got['EffectiveToUtc'] === null
            && (($own = Phase134MigrationOwnership::decode($got['ErpSourceReference'] ?? null))['isPilotOwned'] ?? false) === true;
        if ($ok) {
            ++$postExact;
            $rr['postCommit'] = 'MATCH';
        } else {
            ++$postMismatch;
            $rr['postCommit'] = 'MISMATCH';
        }

        // Legacy source recon
        $src2 = $tc->query(
            'SELECT cost, dc FROM `price` WHERE UPPER(TRIM(code)) = :c AND dbco = 2507 ORDER BY dc DESC LIMIT 5',
            ['c' => $rr['legacyCode']],
            'phase134',
            'legacy-recon',
        );
        if ($src2 === []) {
            ++$legacyMissing;
            $rr['legacySourceStatus'] = 'MISSING_SOURCE';
        } else {
            $ldc = null;
            foreach ($src2 as $s) {
                $dc = (string) ($s['dc'] ?? '');
                if ($ldc === null || strcmp($dc, $ldc) > 0) {
                    $ldc = $dc;
                }
            }
            $lcost = null;
            foreach ($src2 as $s) {
                if ((string) ($s['dc'] ?? '') === $ldc) {
                    $lcost = (string) $s['cost'];
                    break;
                }
            }
            if (bccomp((string) $lcost, (string) $rr['sourceCost'], 6) === 0
                && substr((string) $ldc, 0, 10) === ($rr['sourceDc'] ?? null)
            ) {
                ++$legacyMatch;
                $rr['legacySourceStatus'] = 'MATCH';
            } else {
                ++$legacyChanged;
                $rr['legacySourceStatus'] = 'SOURCE_CHANGED';
            }
        }
    }
    unset($rr);

    $deltas = [
        'ProductVariantPrices' => $countsAfter['ProductVariantPrices'] - $countsBefore['ProductVariantPrices'],
        'ProductPrices' => $countsAfter['ProductPrices'] - $countsBefore['ProductPrices'],
        'CustomerProductPrices' => $countsAfter['CustomerProductPrices'] - $countsBefore['CustomerProductPrices'],
        'PricingTiers' => $countsAfter['PricingTiers'] - $countsBefore['PricingTiers'],
        'PriceListCustomers' => $countsAfter['PriceListCustomers'] - $countsBefore['PriceListCustomers'],
    ];

    $reconPass = $postMissing === 0
        && $postMismatch === 0
        && $postExact === count($selected['candidates'])
        && $dupCheck === []
        && $legacyChanged === 0
        && $legacyMissing === 0
        && $deltas['ProductPrices'] === 0
        && $deltas['CustomerProductPrices'] === 0
        && $deltas['PricingTiers'] === 0
        && $deltas['PriceListCustomers'] === 0
        && $deltas['ProductVariantPrices'] === $created
        && ($created + $noChange) === count($selected['candidates']);

    // First-apply expectation: all CREATE. Idempotent re-apply: all NO_CHANGE.
    $applyMode = $created > 0 ? 'FIRST_APPLY' : 'IDEMPOTENT_RERUN';
    if ($applyMode === 'FIRST_APPLY' && $noChange !== 0) {
        $reconPass = false;
    }
    if ($applyMode === 'IDEMPOTENT_RERUN' && $created !== 0) {
        $reconPass = false;
    }

    $owningBatchId = $batchId;
    if ($created === 0 && $noChange > 0 && $reconRows !== []) {
        $decodedOwn = Phase134MigrationOwnership::decode(
            $db->fetchAll(
                'SELECT TOP 1 ErpSourceReference FROM [product].[ProductVariantPrices] WHERE PublicId = :p',
                ['p' => $reconRows[0]['targetPriceId']],
            )[0]['ErpSourceReference'] ?? null
        );
        if (!empty($decodedOwn['batchId'])) {
            $owningBatchId = (string) $decodedOwn['batchId'];
        }
    }

    $decision = $reconPass ? 'PILOT_APPLY_SUCCESS' : 'PILOT_APPLY_FAILED';

    $applyReport = [
        'decision' => $decision,
        'safety' => [
            'APP_ENV' => $appEnv,
            'Server' => $serverName,
            'Database' => $dbName,
            'Production' => 'NO',
            'Legacy writes' => 0,
            'Azure pricing writes' => $created,
            'Other target writes' => count(array_filter($referenceActionsDone, static fn ($a) => str_starts_with($a, 'CREATE_'))),
        ],
        'reference' => [
            'actions' => $referenceActionsDone,
            'proof' => $refProof,
        ],
        'batchId' => $batchId,
        'owningBatchId' => $owningBatchId,
        'style' => $style,
        'variants' => count($selected['candidates']),
        'PriceList' => 'TCA-BASE',
        'Currency' => 'AUD',
        'transaction' => [
            'Started' => $txnStartedAt,
            'Committed' => $txnCommittedAt,
            'Rolled back' => false,
        ],
        'CREATE' => $created,
        'NO_CHANGE' => $noChange,
        'applyMode' => $applyMode,
        'BLOCKED' => 0,
        'REVIEW' => 0,
        'MANUAL_DATA_CONFLICT' => 0,
        'preCommitReconciliation' => [
            'exact' => $preCommitExact,
            'mismatches' => $preCommitMismatch,
            'PASS' => $preCommitMismatch === 0,
        ],
        'postCommitReconciliation' => [
            'expected' => count($selected['candidates']),
            'found' => $postExact,
            'exactMatches' => $postExact,
            'mismatches' => $postMismatch,
            'missing' => $postMissing,
            'unexpected' => 0,
            'duplicates' => count($dupCheck),
            'PASS' => $reconPass,
        ],
        'legacySourceReconciliation' => [
            'MATCH' => $legacyMatch,
            'SOURCE_CHANGED' => $legacyChanged,
            'MISSING_SOURCE' => $legacyMissing,
        ],
        'sideEffectDeltas' => $deltas,
        'countsBefore' => $countsBefore,
        'countsAfter' => $countsAfter,
        'rows' => $reconRows,
        'rollback' => [
            'eligible' => true,
            'protectedRecords' => 0,
            'owningBatchId' => $owningBatchId,
            'command' => 'php bin/product-pricing-phase13.4-rollback.php --batch=' . $owningBatchId
                . ' --confirm-non-production --confirm-rollback',
            'executed' => false,
        ],
    ];
    file_put_contents($tmp . '/phase13.4-tca-pilot-reconciliation.json', json_encode($applyReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    echo json_encode([
        'decision' => $decision,
        'batchId' => $batchId,
        'owningBatchId' => $owningBatchId,
        'CREATE' => $created,
        'NO_CHANGE' => $noChange,
        'applyMode' => $applyMode,
        'reconciliation' => $reconPass ? 'PASS' : 'FAIL',
        'sideEffectDeltas' => $deltas,
        'referenceActions' => $referenceActionsDone,
        'rollbackCommand' => $applyReport['rollback']['command'],
        'STOP' => 'WAIT FOR EXPLICIT OPERATOR APPROVAL — do not expand scope; do not rollback without approval',
    ], JSON_PRETTY_PRINT) . "\n";

    if (!$reconPass) {
        exit(4);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $fail = [
        'decision' => 'PILOT_APPLY_FAILED',
        'batchId' => $batchId,
        'error' => $e->getMessage(),
        'transaction' => 'ROLLED_BACK',
        'referenceActionsAttempted' => $referenceActionsDone,
    ];
    file_put_contents($tmp . '/phase13.4-tca-pilot-reconciliation.json', json_encode($fail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDERR, json_encode($fail, JSON_PRETTY_PRINT) . "\n");
    exit(4);
}
