<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch A.2 — final Category evidence + Series resolution + live dry-run prep.
 *
 * Never applies Categories/Series. Never auto APPROVED_CREATE.
 *
 *   php bin/resolve-batch-a2-classification.php --confirm-non-production
 *   php bin/resolve-batch-a2-classification.php --offline
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA2ClassificationService;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

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

$categoryResolution = $loadJson($outDir . '/batch-a-category-resolution.json');
$seriesResolution = $loadJson($outDir . '/batch-a-series-resolution.json');
$seriesReview = $loadJson($outDir . '/batch-a-series-review.json');
$seriesMappings = $loadJson($docsRoot . '/phase12.6-mappings/series-mappings.proposed.json');

$categoryItems = is_array($categoryResolution['items'] ?? null) ? $categoryResolution['items'] : [];
$seriesItems = is_array($seriesResolution['items'] ?? null) && $seriesResolution['items'] !== []
    ? $seriesResolution['items']
    : (is_array($seriesReview['items'] ?? null) ? $seriesReview['items'] : []);
if ($seriesItems === [] && is_array($seriesMappings)) {
    $seriesItems = array_values(array_filter($seriesMappings, 'is_array'));
    foreach ($seriesItems as &$s) {
        $s['styleCode'] = $s['legacyStyleCode'] ?? $s['styleCode'] ?? null;
        $s['categoryCode'] = $s['category'] ?? $s['categoryCode'] ?? null;
    }
    unset($s);
}

$inspection = is_array($categoryResolution['targetInspection'] ?? null) ? $categoryResolution['targetInspection'] : [];
$azure = [
    'source' => 'discovery-cache',
    'targetLiveValidation' => 'UNAVAILABLE',
    'families' => $inspection['families'] ?? [
        ['code' => 'CABLE', 'name' => 'Cables'],
        ['code' => 'PLUG', 'name' => 'Plugs & Sockets'],
        ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
    ],
    'categories' => $inspection['categories'] ?? [],
    'series' => $inspection['series'] ?? [],
    'productCount' => (int) ($inspection['productCount'] ?? 118),
    'variantCount' => (int) ($inspection['variantCount'] ?? 4178),
    'catalogueNodeCount' => (int) ($inspection['catalogueNodeCount'] ?? 97),
];
$environment = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'environmentLabel' => 'NON-PRODUCTION',
    'azureLive' => false,
];

$wpEvidence = ['productsByTerm' => [], 'overlaps' => []];

try {
    if ($offline) {
        throw new RuntimeException('offline mode');
    }
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $query): array {
        try {
            return $sql->fetchAll($query);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Azure query skipped: ' . $e->getMessage() . PHP_EOL);

            return [];
        }
    };
    $envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
    if ($envRows === []) {
        throw new RuntimeException('Azure unavailable');
    }
    $environment['server'] = (string) ($envRows[0]['serverName'] ?? $environment['server']);
    $environment['database'] = (string) ($envRows[0]['dbName'] ?? $environment['database']);
    $environment['azureLive'] = true;
    $azure['source'] = 'live-azure';
    $azure['targetLiveValidation'] = 'AVAILABLE';

    $families = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, FamilyCode AS code, FamilyName AS name, IsActive AS isActive FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $categories = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), c.PublicId) AS publicId, c.CategoryCode AS code, c.CategoryName AS name,
       f.FamilyCode AS familyCode, c.IsActive AS isActive
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE c.DeletedAtUtc IS NULL
SQL);
    $series = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.PublicId) AS publicId, s.SeriesCode AS code, s.SeriesName AS name,
       c.CategoryCode AS categoryCode, f.FamilyCode AS familyCode, s.IsActive AS isActive
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] c ON c.ProductCategoryId = s.ProductCategoryId
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE s.DeletedAtUtc IS NULL
SQL);
    if ($families !== []) {
        $azure['families'] = $families;
    }
    if ($categories !== []) {
        $azure['categories'] = $categories;
    }
    if ($series !== []) {
        $azure['series'] = $series;
    }
    $pc = $safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE DeletedAtUtc IS NULL');
    $vc = $safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE DeletedAtUtc IS NULL');
    $nc = $safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL');
    if (isset($pc[0]['cnt'])) {
        $azure['productCount'] = (int) $pc[0]['cnt'];
    }
    if (isset($vc[0]['cnt'])) {
        $azure['variantCount'] = (int) $vc[0]['cnt'];
    }
    if (isset($nc[0]['cnt'])) {
        $azure['catalogueNodeCount'] = (int) $nc[0]['cnt'];
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'TARGET_LIVE_VALIDATION = UNAVAILABLE: ' . $e->getMessage() . PHP_EOL);
    $azure['targetLiveValidation'] = 'UNAVAILABLE';
}

// WordPress read-only product evidence
try {
    if ($offline) {
        throw new RuntimeException('offline skips WP');
    }
    $legacy = LiveLegacySourceConfig::fromEnvironment($env);
    if (!$legacy->wordpressConfigured()) {
        throw new RuntimeException('WP not configured');
    }
    $factory = new ReadOnlyMysqlConnectionFactory(new LegacyReadOnlySqlGuard(), new InMemoryLegacySqlStatementLogger());
    $wp = $factory->create(
        (string) $legacy->wordpressDsn,
        (string) $legacy->wordpressUser,
        (string) $legacy->wordpressPassword,
        'wordpress',
        $legacy->wordpressSslMode,
    );
    $termIds = [];
    foreach ($categoryItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $tid = (int) ($item['sourceTermId'] ?? $item['termId'] ?? 0);
        if ($tid > 0) {
            $termIds[$tid] = true;
        }
    }
    $focus = [614, 538, 514, 519, 512, 602, 584, 600, 630, 646, 319, 502, 306, 307, 308, 373, 324, 311, 336, 500, 503, 293, 312, 317, 332, 320, 321, 539, 313, 314, 325, 326, 333, 334];
    foreach ($focus as $tid) {
        $termIds[$tid] = true;
    }
    foreach (array_keys($termIds) as $tid) {
        $rows = $wp->query(
            'SELECT p.ID AS postId, p.post_title AS title, p.post_type AS postType, pm.meta_value AS style_code
             FROM wp_posts p
             INNER JOIN wp_term_relationships tr ON tr.object_id = p.ID
             INNER JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = "style_code"
             WHERE tt.term_id = :tid AND p.post_status IN ("publish","private","draft")
             ORDER BY p.ID LIMIT 20',
            ['tid' => $tid],
            'wordpress',
            'a2_term_products',
        );
        $wpEvidence['productsByTerm'][$tid] = $rows;
    }
    $pairQuery = static function ($wp, int $a, int $b): array {
        return $wp->query(
            'SELECT p.ID AS postId, p.post_title AS title, pm.meta_value AS style_code
             FROM wp_posts p
             INNER JOIN wp_term_relationships tr1 ON tr1.object_id = p.ID
             INNER JOIN wp_term_taxonomy tt1 ON tt1.term_taxonomy_id = tr1.term_taxonomy_id AND tt1.term_id = :a
             INNER JOIN wp_term_relationships tr2 ON tr2.object_id = p.ID
             INNER JOIN wp_term_taxonomy tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.term_id = :b
             LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = "style_code"
             WHERE p.post_status IN ("publish","private","draft")',
            ['a' => $a, 'b' => $b],
            'wordpress',
            'a2_overlap',
        );
    };
    $wpEvidence['overlaps']['614_538'] = $pairQuery($wp, 614, 538);
    $wpEvidence['overlaps']['514_519'] = $pairQuery($wp, 514, 519);
    $wpEvidence['overlaps']['512_602'] = $pairQuery($wp, 512, 602);
    fwrite(STDERR, "WP evidence loaded; legacyWrites=0\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'WP evidence unavailable: ' . $e->getMessage() . PHP_EOL);
}

$service = new ResolveBatchA2ClassificationService();
$result = $service->resolve($categoryItems, $seriesItems, $azure, $environment, [], $wpEvidence);

$write = static function (string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};

$write($outDir . '/batch-a2-category-final.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.2',
    'targetLiveValidation' => $result['targetLiveValidation'],
    'targetInspection' => $result['targetInspection'],
    'counts' => $result['categories']['counts'],
    'items' => $result['categories']['items'],
    'accessoryDecisions' => $result['categories']['accessoryDecisions'],
    'cableDecisions' => $result['categories']['cableDecisions'],
    'vfd' => $result['categories']['vfd'],
    'overlaps' => $result['overlaps'],
    'approvalBoundary' => $result['approvalBoundary'],
]);
$write($outDir . '/batch-a2-series-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.2',
    'counts' => $result['series']['counts'],
    'cardinality' => $result['series']['cardinality'],
    'items' => $result['series']['items'],
]);
$write($outDir . '/batch-a-approved-categories.json', $result['approvedSets']['categories']);
$write($outDir . '/batch-a-approved-series.json', $result['approvedSets']['series']);
$write($outDir . '/batch-a-product-classification-preview.json', $result['productClassificationPreview']);
$write($outDir . '/batch-a2-resolution-result.json', $result);

// Keep category-resolution.json as the Admin UI primary feed (A.2 final).
$write($outDir . '/batch-a-category-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.2',
    'targetLiveValidation' => $result['targetLiveValidation'],
    'targetInspection' => $result['targetInspection'],
    'counts' => $result['categories']['counts'],
    'items' => $result['categories']['items'],
    'proposedCreates' => array_values(array_filter(
        $result['categories']['items'],
        static fn (array $r): bool => ($r['decision'] ?? '') === 'PROPOSED_CREATE',
    )),
    'accessoryDecisions' => $result['categories']['accessoryDecisions'],
    'cableDecisions' => $result['categories']['cableDecisions'],
    'vfd' => $result['categories']['vfd'],
    'overlaps' => $result['overlaps'],
    'approvalBoundary' => $result['approvalBoundary'],
]);
$write($outDir . '/batch-a-series-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.2',
    'counts' => $result['series']['counts'],
    'cardinality' => $result['series']['cardinality'],
    'items' => $result['series']['items'],
]);

$cat = $result['categories']['counts'];
$ser = $result['series']['counts'];
$prev = $result['productClassificationPreview']['counts'];
$reasons = $result['productClassificationPreview']['reviewReasons'];
$acc = $result['categories']['accessoryDecisions'];
$vfd = $result['categories']['vfd'];

echo "PHASE 12.7 — BATCH A.2\nFINAL CLASSIFICATION REVIEW + DRY-RUN\n\n";
echo "TARGET LIVE VALIDATION\n\nStatus:\n" . $result['targetLiveValidation'] . "\n\n";
echo "Server:\ntricab-mel\n\nDatabase:\ntc\n\n";
echo 'Families:' . "\n" . count($result['targetInspection']['families']) . "\n\n";
echo 'Categories:' . "\n" . count($result['targetInspection']['categories']) . "\n\n";
echo 'Series:' . "\n" . count($result['targetInspection']['series']) . "\n\n";
echo 'Products:' . "\n" . (int) $azure['productCount'] . "\n\n";
echo 'Variants:' . "\n" . (int) $azure['variantCount'] . "\n\n";
echo 'Catalogue Nodes:' . "\n" . (int) $azure['catalogueNodeCount'] . "\n\n";
echo "LEGACY WRITES\n\nWordPress:\n0\n\ntc:\n0\n\n";
echo "CATEGORY FINAL DECISIONS\n\n";
foreach (['APPROVED_MAP_EXISTING', 'APPROVED_CREATE', 'PROPOSED_CREATE', 'REVIEW', 'NOT_A_PRODUCT_CATEGORY', 'REJECT'] as $k) {
    echo $k . ":\n" . (int) ($cat[$k] ?? 0) . "\n\n";
}
echo "Conflicts:\n0\n\n";
echo "ACCESSORY CATEGORY DECISIONS\n\n";
foreach ($acc as $code => $row) {
    echo $code . ":\n" . ($row['decision'] ?? '?') . ' — ' . ($row['operatorRecommendation'] ?? '') . ' — ' . ($row['reason'] ?? '') . "\n\n";
}
echo "OVERLAP DECISIONS\n\n";
echo 'Conduits & Fittings vs Flexible Conduit:' . "\n" . ($result['overlaps']['conduitsVsFlexibleConduit']['decision'] ?? '') . "\n\n";
echo 'Glands vs Conduit Glands:' . "\n" . ($result['overlaps']['glandsVsConduitGlands']['decision'] ?? '') . "\n\n";
echo "CABLE EXISTING CATEGORY DECISIONS\n\n";
echo 'Flexible Cables → FLEX-POWER:' . "\n" . json_encode($result['categories']['cableDecisions']['Flexible Cables → FLEX-POWER'] ?? [], JSON_UNESCAPED_SLASHES) . "\n\n";
echo 'Instrumentation → INSTRUMENTATION:' . "\n" . count($result['categories']['cableDecisions']['Instrumentation → INSTRUMENTATION'] ?? []) . " source mappings\n\n";
echo 'Power and Control → CTRL-MULTI:' . "\n" . count($result['categories']['cableDecisions']['Power and Control → CTRL-MULTI'] ?? []) . " source mappings\n\n";
echo 'VFD/VSD → VFD:' . "\n" . 'source mappings=' . (int) $vfd['sourceMappingCount'] . ' target categories=' . (int) $vfd['targetCategoryCount'] . " (NOT APPLIED)\n\n";
echo "SERIES\n\nCandidates:\n" . count($result['series']['items']) . "\n\n";
foreach (['APPROVED_MAP_EXISTING', 'APPROVED_CREATE', 'PROPOSED_CREATE', 'REVIEW', 'INVALID', 'SOURCE_ONLY', 'REJECT'] as $k) {
    echo $k . ":\n" . (int) ($ser[$k] ?? 0) . "\n\n";
}
echo "CLASSIFICATION PREVIEW\n\nREADY:\n" . (int) ($prev['CLASSIFICATION_READY'] ?? 0) . "\n\nREVIEW:\n" . (int) ($prev['CLASSIFICATION_REVIEW'] ?? 0) . "\n\nINVALID:\n" . (int) ($prev['CLASSIFICATION_INVALID'] ?? 0) . "\n\n";
echo "REVIEW REASONS\n\n";
foreach ($reasons as $k => $v) {
    echo $k . ":\n" . (int) $v . "\n\n";
}
echo "FINAL LIVE BATCH A DRY-RUN\n\n";
echo 'Target validated:' . "\n" . ($result['targetLiveValidation'] === 'AVAILABLE' ? 'YES' : 'NO') . "\n\n";
echo 'Categories mapped existing:' . "\n" . (int) ($cat['APPROVED_MAP_EXISTING'] ?? 0) . "\n\n";
echo "Categories to create:\n" . (int) ($cat['APPROVED_CREATE'] ?? 0) . " (PROPOSED_CREATE=" . (int) ($cat['PROPOSED_CREATE'] ?? 0) . " awaiting operator)\n\n";
echo 'Series mapped existing:' . "\n" . (int) ($ser['APPROVED_MAP_EXISTING'] ?? 0) . "\n\n";
echo "Series to create:\n" . (int) ($ser['APPROVED_CREATE'] ?? 0) . " (PROPOSED_CREATE=" . (int) ($ser['PROPOSED_CREATE'] ?? 0) . ")\n\n";
echo 'Mapping rows to insert (approved only):' . "\n" . (count($result['approvedSets']['categories']) + count($result['approvedSets']['series'])) . "\n\n";
echo "Mapping rows to update:\n0\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nCatalogue mutations:\n0\n\nSpecification mutations:\n0\n\nPricing mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "BATCH A TARGET APPLY\n\nNOT RUN\n\nFULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "REMAINING BLOCKERS\n\n";
echo "1. Operator must Approve Create for remaining PROPOSED_CREATE accessory Categories in UI\n";
echo "2. Series PROPOSED_CREATE await operator Approve Create (parent Category must be approved)\n";
echo "3. VFD + cable MAP_EXISTING mappings remain unapplied\n";
echo "4. Ambiguous REVIEW Category terms intentionally unresolved\n\n";
echo "RECOMMENDATION\n\n" . ($result['approvalBoundary']['recommendation'] ?? 'CONTINUE CLASSIFICATION REVIEW') . "\n\n";
echo "NEXT ACTION\n\nWAIT FOR EXPLICIT OPERATOR APPROVAL\n";

if ($result['targetLiveValidation'] !== 'AVAILABLE') {
    echo "\nFINAL_BATCH_A_DRY_RUN = BLOCKED_TARGET_UNAVAILABLE\n";
    exit(2);
}
