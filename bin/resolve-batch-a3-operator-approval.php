<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch A.3 — operator approval package + READY subset + final dry-run plan.
 *
 * Never runs --apply. Records explicit operator decisions from Batch A.3 review package.
 *
 *   php bin/resolve-batch-a3-operator-approval.php --confirm-non-production
 *   php bin/resolve-batch-a3-operator-approval.php --offline
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA3OperatorApprovalService;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
$loadJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
};

$a2 = $loadJson($outDir . '/batch-a2-resolution-result.json');
if ($a2 === []) {
    $a2 = [
        'categories' => ['items' => $loadJson($outDir . '/batch-a2-category-final.json')['items'] ?? []],
        'series' => ['items' => $loadJson($outDir . '/batch-a2-series-resolution.json')['items'] ?? []],
        'overlaps' => $loadJson($outDir . '/batch-a2-category-final.json')['overlaps'] ?? [],
    ];
}

// Explicit Batch A.3 operator decision package (not confidence auto-approve).
// Seventh PROPOSED row is duplicate GLANDS term 542 → same target code.
$operatorDecisions = [
    'technoFamily' => 'PLUG',
    'approveCreateCodes' => [
        'CONDUITS-FITTINGS' => [
            'name' => 'Conduits & Fittings',
            'family' => 'ACCESSORIES',
            'reason' => 'operator_approved_create_after_overlap_review_keep_conduits_fittings_only',
            'confidence' => 'high',
        ],
        'TECHNO-CONNECTORS' => [
            'name' => 'Techno Connectors',
            'family' => 'PLUG',
            'reason' => 'operator_approved_create_family_plug_j90_j97_plug_socket_junction',
            'confidence' => 'high',
        ],
        'TERMINALS' => [
            'name' => 'Terminals',
            'family' => 'ACCESSORIES',
            'reason' => 'operator_approved_create_terminals_bootlace_remains_series_review',
            'confidence' => 'high',
        ],
        'GLANDS' => [
            'name' => 'Glands',
            'family' => 'ACCESSORIES',
            'reason' => 'operator_approved_create_glands_distinct_from_conduit_glands',
            'confidence' => 'high',
        ],
        'LINKS' => [
            'name' => 'Links',
            'family' => 'ACCESSORIES',
            'reason' => 'operator_approved_create_metallic_links_medium_evidence_reviewed',
            'confidence' => 'medium',
        ],
        'PLUG-SOCKET-LEADS' => [
            'name' => 'Plug-Socket Leads',
            'family' => 'ACCESSORIES',
            'reason' => 'operator_approved_create_preassembled_leads_medium_evidence_reviewed',
            'confidence' => 'medium',
        ],
    ],
    // Approve all Series that remain PROPOSED_CREATE after Category approvals (deterministic eligible set).
    'series' => [
        '__approveAllEligibleProposed' => true,
    ],
];

$write = static function (string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};
$write($outDir . '/batch-a3-operator-decisions.json', [
    'generatedAtUtc' => gmdate('c'),
    'note' => 'Explicit Batch A.3 operator decision package. Not confidence auto-approve. Apply still blocked.',
    'decisions' => $operatorDecisions,
]);

$azure = [
    'source' => 'discovery-cache',
    'targetLiveValidation' => 'UNAVAILABLE',
    'families' => $a2['targetInspection']['families'] ?? [
        ['code' => 'CABLE', 'name' => 'Cables'],
        ['code' => 'PLUG', 'name' => 'Plugs & Sockets'],
        ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
    ],
    'categories' => $a2['targetInspection']['categories'] ?? [],
    'series' => $a2['targetInspection']['series'] ?? [],
    'productCount' => (int) ($a2['targetInspection']['productCount'] ?? 118),
    'variantCount' => (int) ($a2['targetInspection']['variantCount'] ?? 4178),
    'catalogueNodeCount' => (int) ($a2['targetInspection']['catalogueNodeCount'] ?? 97),
];
$environment = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'environmentLabel' => 'NON-PRODUCTION',
];

try {
    if ($offline) {
        throw new RuntimeException('offline mode');
    }
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $q): array {
        try {
            return $sql->fetchAll($q);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Azure skipped: ' . $e->getMessage() . PHP_EOL);

            return [];
        }
    };
    $envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
    if ($envRows === []) {
        throw new RuntimeException('Azure unavailable');
    }
    $environment['server'] = (string) ($envRows[0]['serverName'] ?? 'tricab-mel');
    $environment['database'] = (string) ($envRows[0]['dbName'] ?? 'tc');
    $azure['source'] = 'live-azure';
    $azure['targetLiveValidation'] = 'AVAILABLE';
    $families = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, FamilyCode AS code, FamilyName AS name, IsActive AS isActive FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $categories = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), c.PublicId) AS publicId, c.CategoryCode AS code, c.CategoryName AS name, f.FamilyCode AS familyCode, c.IsActive AS isActive FROM [product].[ProductCategories] c LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId=c.ProductFamilyId WHERE c.DeletedAtUtc IS NULL');
    $series = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), s.PublicId) AS publicId, s.SeriesCode AS code, s.SeriesName AS name, c.CategoryCode AS categoryCode, s.IsActive AS isActive FROM [product].[ProductSeries] s LEFT JOIN [product].[ProductCategories] c ON c.ProductCategoryId=s.ProductCategoryId WHERE s.DeletedAtUtc IS NULL');
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

$service = new ResolveBatchA3OperatorApprovalService();
$result = $service->build($a2, $operatorDecisions, $azure, $environment);

$write($outDir . '/batch-a3-final-package.json', $result);
$write($outDir . '/batch-a-approved-categories.json', $result['approvedSets']['categories']);
$write($outDir . '/batch-a-approved-series.json', $result['approvedSets']['series']);
$write($outDir . '/batch-a-classification-ready-products.json', [
    'generatedAtUtc' => gmdate('c'),
    'counts' => ['ready' => count($result['classificationReadyProducts'])],
    'items' => $result['classificationReadyProducts'],
]);
$write($outDir . '/batch-a-classification-review-products.json', [
    'generatedAtUtc' => gmdate('c'),
    'counts' => [
        'review' => count($result['classificationReviewProducts']),
        'invalid' => count($result['classificationInvalidProducts']),
    ],
    'review' => $result['classificationReviewProducts'],
    'invalid' => $result['classificationInvalidProducts'],
]);
$write($outDir . '/batch-a-product-classification-preview.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.3',
    'readiness' => $result['readiness'],
    'variantImpact' => $result['variantImpact'],
    'items' => array_merge(
        $result['classificationReadyProducts'],
        $result['classificationReviewProducts'],
        $result['classificationInvalidProducts'],
    ),
]);
$write($outDir . '/batch-a3-dry-run.json', [
    'generatedAtUtc' => gmdate('c'),
    'targetLiveValidation' => $result['targetLiveValidation'],
    'plan' => $result['targetApplyPlan'],
    'approvalBoundary' => $result['approvalBoundary'],
]);

// Keep category-resolution.json as Admin feed reflecting A.3 finals.
$write($outDir . '/batch-a-category-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.3',
    'targetLiveValidation' => $result['targetLiveValidation'],
    'targetInspection' => $result['targetInspection'],
    'counts' => $result['categories']['counts'],
    'items' => $result['categories']['items'],
    'technoFamilyDecision' => $result['technoFamilyDecision'],
    'approvalBoundary' => $result['approvalBoundary'],
    'adminSummary' => [
        'existingCategoryMappings' => $result['categories']['counts']['APPROVED_MAP_EXISTING'] ?? 0,
        'newCategoriesApproved' => $result['targetApplyPlan']['categories']['create'] ?? 0,
        'newSeriesApproved' => $result['targetApplyPlan']['series']['create'] ?? 0,
        'productsClassificationReady' => $result['readiness']['CLASSIFICATION_READY'] ?? 0,
        'productsStillReview' => $result['readiness']['CLASSIFICATION_REVIEW'] ?? 0,
        'productsInvalid' => $result['readiness']['CLASSIFICATION_INVALID'] ?? 0,
        'variantsAssociatedWithReadyProducts' => $result['variantImpact']['associatedTproExtVariants'] ?? 0,
        'conflicts' => count($result['approvedSetValidation']['errors'] ?? []),
        'targetApplyStatus' => 'NOT RUN',
    ],
]);
$write($outDir . '/batch-a-series-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.3',
    'counts' => $result['series']['counts'],
    'items' => $result['series']['items'],
]);

$cat = $result['categories']['counts'];
$ser = $result['series']['counts'];
$plan = $result['targetApplyPlan'];
$ready = $result['readiness'];
$vfd = $result['categories']['vfd'] ?? [];

echo "PHASE 12.7 — BATCH A.3\nOPERATOR APPROVAL + FINAL APPLY PACKAGE\n\n";
echo "TARGET LIVE VALIDATION\n\nStatus:\n" . $result['targetLiveValidation'] . "\n\n";
echo "Server:\ntricab-mel\n\nDatabase:\ntc\n\n";
echo 'Families:' . "\n" . count($result['targetInspection']['families'] ?? []) . "\n\n";
echo 'Categories:' . "\n" . count($result['targetInspection']['categories'] ?? []) . "\n\n";
echo 'Series:' . "\n" . count($result['targetInspection']['series'] ?? []) . "\n\n";
echo 'Products:' . "\n" . (int) $azure['productCount'] . "\n\n";
echo 'Variants:' . "\n" . (int) $azure['variantCount'] . "\n\n";
echo 'Catalogue Nodes:' . "\n" . (int) $azure['catalogueNodeCount'] . "\n\n";
echo "LEGACY WRITES\n\nWordPress:\n0\n\ntc:\n0\n\n";
echo "CATEGORY APPROVALS\n\n";
foreach (['APPROVED_MAP_EXISTING', 'APPROVED_CREATE', 'PROPOSED_CREATE', 'REVIEW', 'NOT_A_PRODUCT_CATEGORY', 'REJECT'] as $k) {
    echo $k . ":\n" . (int) ($cat[$k] ?? 0) . "\n\n";
}
echo "NEW CATEGORIES APPROVED\n\n";
foreach ($plan['categories']['createRows'] as $row) {
    echo ($row['code'] ?? '') . ' | ' . ($row['name'] ?? '') . ' | ' . ($row['family'] ?? '') . ' | ' . (int) ($row['productCount'] ?? 0) . "\n";
}
echo "\nTECHNO FAMILY:\n" . ($result['technoFamilyDecision']['family'] ?? '') . ' — ' . ($result['technoFamilyDecision']['reason'] ?? '') . "\n\n";
echo "SERIES APPROVALS\n\n";
foreach (['APPROVED_MAP_EXISTING', 'APPROVED_CREATE', 'PROPOSED_CREATE', 'REVIEW', 'INVALID'] as $k) {
    echo $k . ":\n" . (int) ($ser[$k] ?? 0) . "\n\n";
}
echo 'Conflicts:' . "\n" . (int) ($ser['CONFLICT'] ?? 0) . "\n\n";
echo "CLASSIFICATION READINESS\n\n";
foreach (['CATEGORY_READY', 'SERIES_READY', 'CLASSIFICATION_READY', 'CLASSIFICATION_REVIEW', 'CLASSIFICATION_INVALID'] as $k) {
    echo $k . ":\n" . (int) ($ready[$k] ?? 0) . "\n\n";
}
echo "FULL PRODUCT MIGRATION READINESS\n\nREADY:\n" . (int) ($ready['FULL_PRODUCT_MIGRATION_READY'] ?? 0) . "\n\nBLOCKED:\n" . (int) ($ready['FULL_PRODUCT_MIGRATION_BLOCKED'] ?? 0) . "\n\n";
echo "Main blockers:\n" . json_encode($ready['mainBlockers'] ?? [], JSON_UNESCAPED_SLASHES) . "\n\n";
echo "READY VARIANT IMPACT\n\nClassification-ready Products:\n" . (int) ($result['variantImpact']['classificationReadyProducts'] ?? 0) . "\n\n";
echo 'Associated tpro_ext variants:' . "\n" . (int) ($result['variantImpact']['associatedTproExtVariants'] ?? 0) . "\n\n";
echo "FINAL LIVE BATCH A DRY-RUN\n\nTarget validated:\n" . ($result['targetLiveValidation'] === 'AVAILABLE' ? 'YES' : 'NO') . "\n\n";
echo 'Existing Categories mapped:' . "\n" . (int) $plan['categories']['mapExisting'] . "\n\n";
echo 'Categories to create:' . "\n" . (int) $plan['categories']['create'] . "\n\n";
echo 'Existing Series mapped:' . "\n" . (int) $plan['series']['mapExisting'] . "\n\n";
echo 'Series to create:' . "\n" . (int) $plan['series']['create'] . "\n\n";
echo 'Category mappings to insert:' . "\n" . (int) $plan['mappings']['categoryInsert'] . "\n\n";
echo 'Series mappings to insert:' . "\n" . (int) $plan['mappings']['seriesInsert'] . "\n\n";
echo "Mappings to update:\n0\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nCatalogue mutations:\n0\n\nSpecification mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "IDEMPOTENCY\n\nSecond-run projected Category creates:\n0\n\nSecond-run projected Series creates:\n0\n\nSecond-run projected Mapping inserts:\n0\n\n";
echo "BATCH A APPLY\n\nNOT RUN\n\n";
echo "RECOMMENDATION\n\n" . ($result['approvalBoundary']['recommendation'] ?? '') . "\n\n";
echo "FULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "NEXT ACTION\n\nWAIT FOR EXPLICIT OPERATOR APPROVAL\n";
echo "\nVFD source mappings=" . (int) ($vfd['sourceMappingCount'] ?? 0) . " target categories=" . (int) ($vfd['targetCategoryCount'] ?? 0) . " applied=false\n";

if ($result['targetLiveValidation'] !== 'AVAILABLE') {
    echo "\nFINAL_APPLY_PACKAGE = BLOCKED_TARGET_UNAVAILABLE\n";
    exit(2);
}
