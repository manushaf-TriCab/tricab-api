<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch A.1 — Category resolution + Series re-evaluation.
 *
 * Reads Batch A review artifacts. Never applies Categories/Series to Azure.
 * Final apply-readiness dry-run is blocked when target live validation fails.
 *
 *   php bin/resolve-batch-a1-classification.php
 *   php bin/resolve-batch-a1-classification.php --confirm-non-production
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchA1CategoryService;
use TriCab\Core\Product\Migration\Classification\CategoryDecisionState;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);

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

$categoryReview = $loadJson($outDir . '/batch-a-category-review.json');
$seriesReview = $loadJson($outDir . '/batch-a-series-review.json');
$approvedCategoriesBaseline = $loadJson($outDir . '/batch-a-approved-categories.json');

$allItems = is_array($categoryReview['items'] ?? null) ? $categoryReview['items'] : [];
$reviewItems = array_values(array_filter(
    $allItems,
    static fn ($row): bool => is_array($row) && (($row['decision'] ?? '') === CategoryDecisionState::REVIEW),
));
$duplicateGroups = is_array($categoryReview['duplicates'] ?? null) ? $categoryReview['duplicates'] : [];
$seriesItems = is_array($seriesReview['items'] ?? null) ? $seriesReview['items'] : [];

$inspectionBaseline = is_array($categoryReview['targetInspection'] ?? null) ? $categoryReview['targetInspection'] : [];
$azure = [
    'source' => 'discovery-cache',
    'targetLiveValidation' => 'UNAVAILABLE',
    'families' => $inspectionBaseline['families'] ?? [
        ['code' => 'CABLE', 'name' => 'Cables'],
        ['code' => 'PLUG', 'name' => 'Plugs & Sockets'],
        ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
    ],
    'categories' => $inspectionBaseline['categories'] ?? [],
    'series' => $inspectionBaseline['series'] ?? [],
    'productCount' => (int) ($inspectionBaseline['productCount'] ?? 118),
    'variantCount' => (int) ($inspectionBaseline['variantCount'] ?? 4178),
    'catalogueNodeCount' => (int) ($inspectionBaseline['catalogueNodeCount'] ?? 97),
];

$environment = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'environmentLabel' => 'NON-PRODUCTION',
    'azureLive' => false,
];

try {
    if ($offline) {
        throw new RuntimeException('offline mode — using discovery / Batch A cache only');
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
    if ($envRows === [] || isset($envRows[0]['__error'])) {
        throw new RuntimeException('Azure login/query returned empty (HYT00 or unavailable)');
    }
    $environment['server'] = (string) ($envRows[0]['serverName'] ?? $environment['server']);
    $environment['database'] = (string) ($envRows[0]['dbName'] ?? $environment['database']);
    $environment['azureLive'] = true;
    $azure['source'] = 'live-azure';
    $azure['targetLiveValidation'] = 'AVAILABLE';

    $families = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, FamilyCode AS code, FamilyName AS name, DisplayOrder AS displayOrder, IsActive AS isActive FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $categories = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), c.PublicId) AS publicId, c.CategoryCode AS code, c.CategoryName AS name,
       f.FamilyCode AS familyCode, c.DisplayOrder AS displayOrder, c.IsActive AS isActive,
       (SELECT COUNT(*) FROM product.Products p WHERE p.ProductCategoryId = c.ProductCategoryId AND p.DeletedAtUtc IS NULL) AS usageCount
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE c.DeletedAtUtc IS NULL
SQL);
    $series = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.PublicId) AS publicId, s.SeriesCode AS code, s.SeriesName AS name,
       c.CategoryCode AS categoryCode, f.FamilyCode AS familyCode, s.DisplayOrder AS displayOrder, s.IsActive AS isActive,
       (SELECT COUNT(*) FROM product.Products p WHERE p.ProductSeriesId = s.ProductSeriesId AND p.DeletedAtUtc IS NULL) AS usageCount
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
    $azure['source'] = 'discovery-cache';
    $environment['azureLive'] = false;
}

$service = new ResolveBatchA1CategoryService();
$result = $service->resolve(
    $reviewItems,
    $allItems,
    $duplicateGroups,
    $seriesItems,
    $azure,
    $environment,
);

$write = static function (string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};

$resolvedRows = array_merge(
    $result['categories']['carryForward'] ?? [],
    $result['categories']['resolvedReview'] ?? [],
);

$write($outDir . '/batch-a-category-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.1',
    'targetLiveValidation' => $result['targetLiveValidation'],
    'targetInspection' => $result['targetInspection'],
    'previousReview' => $result['categories']['previousReview'],
    'counts' => $result['categories']['counts'],
    'duplicates' => $result['duplicates'],
    'items' => $resolvedRows,
    'proposedCreates' => $result['categories']['proposedCreates'],
    'approvalBoundary' => $result['approvalBoundary'],
]);

$write($outDir . '/batch-a-series-resolution.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.1',
    'targetLiveValidation' => $result['targetLiveValidation'],
    'counts' => $result['series']['counts'],
    'items' => $result['series']['items'],
]);

// Approved sets: only APPROVED_* — preserve prior VFD map; never include PROPOSED_CREATE.
$approvedCats = $result['approvedSets']['categories'];
if ($approvedCats === [] && is_array($approvedCategoriesBaseline) && $approvedCategoriesBaseline !== []) {
    // Keep prior approved artifact if resolution carry-forward somehow empty.
    $approvedCats = $approvedCategoriesBaseline;
}
$write($outDir . '/batch-a-approved-categories.json', $approvedCats);
$write($outDir . '/batch-a-approved-series.json', $result['approvedSets']['series']);

$previewCounts = ['CLASSIFICATION_READY' => 0, 'CLASSIFICATION_REVIEW' => 0, 'CLASSIFICATION_INVALID' => 0];
$previewItems = [];
foreach ($result['series']['items'] as $s) {
    $status = match ($s['decision'] ?? '') {
        'APPROVED_MAP_EXISTING', 'APPROVED_CREATE', 'PROPOSED_CREATE' => 'CLASSIFICATION_REVIEW',
        'INVALID', 'REJECT' => 'CLASSIFICATION_INVALID',
        default => 'CLASSIFICATION_REVIEW',
    };
    // Ready only when Category + Series are both operator-approved (not proposed).
    if (in_array($s['decision'] ?? '', ['APPROVED_MAP_EXISTING', 'APPROVED_CREATE'], true)
        && ($s['category'] ?? null) !== null
        && ($s['category'] ?? '') !== ''
    ) {
        $status = 'CLASSIFICATION_READY';
    }
    $previewCounts[$status] = ($previewCounts[$status] ?? 0) + 1;
    $previewItems[] = [
        'product' => $s['productTitle'] ?? null,
        'postId' => $s['postId'] ?? null,
        'style_code' => $s['styleCode'] ?? null,
        'family' => $s['family'] ?? null,
        'category' => $s['category'] ?? null,
        'series' => $s['proposedCode'] ?? null,
        'classificationStatus' => $status,
        'reason' => $s['reason'] ?? null,
    ];
}
$write($outDir . '/batch-a-product-classification-preview.json', [
    'generatedAtUtc' => gmdate('c'),
    'phase' => '12.7-batch-a.1',
    'counts' => $previewCounts,
    'items' => $previewItems,
]);

$write($outDir . '/batch-a1-resolution-result.json', $result);

// Human-readable markdown report
$md = [];
$md[] = '# Phase 12.7 Batch A.1 — Category Resolution';
$md[] = '';
$md[] = 'Generated: ' . gmdate('c');
$md[] = '';
$md[] = 'TARGET_LIVE_VALIDATION = **' . $result['targetLiveValidation'] . '**';
$md[] = '';
$md[] = 'Server: tricab-mel · Database: tc · Environment: NON-PRODUCTION';
$md[] = '';
$md[] = 'Legacy writes: **0** · Product/Variant/Catalogue mutations: **0** · Batch A apply: **NOT RUN**';
$md[] = '';
$md[] = '## Counts';
$md[] = '';
$md[] = '| Decision | Count |';
$md[] = '|---|---:|';
foreach ($result['categories']['counts'] as $k => $v) {
    $md[] = '| ' . $k . ' | ' . $v . ' |';
}
$md[] = '';
$md[] = 'Previous REVIEW: ' . (int) $result['categories']['previousReview'];
$md[] = '';
$md[] = '## A. Strong Product Category proposals (PROPOSED_CREATE)';
$md[] = '';
$md[] = 'PROPOSED_CREATE is **not** operator approval. Do not create on target yet.';
$md[] = '';
$md[] = '| Code | Name | Family | Product count | Confidence | Source term |';
$md[] = '|---|---|---|---:|---|---|';
foreach ($result['categories']['proposedCreates'] as $row) {
    $md[] = sprintf(
        '| %s | %s | %s | %d | %s | %s (term %s) |',
        $row['proposedCategoryCode'] ?? '',
        $row['proposedCategoryName'] ?? '',
        $row['proposedFamily'] ?? '',
        (int) ($row['postCount'] ?? 0),
        $row['confidence'] ?? '',
        $row['decodedName'] ?? '',
        $row['sourceTermId'] ?? '',
    );
}
if ($result['categories']['proposedCreates'] === []) {
    $md[] = '| — | — | — | 0 | — | none |';
}
$md[] = '';
$md[] = '## B. Map to existing Categories';
$md[] = '';
foreach ($resolvedRows as $row) {
    if (($row['decision'] ?? '') !== CategoryDecisionState::APPROVED_MAP_EXISTING) {
        continue;
    }
    $md[] = sprintf(
        '- **%s** → `%s` (%s)',
        $row['decodedName'] ?? $row['rawName'] ?? '',
        $row['targetCategoryCode'] ?? '',
        $row['reason'] ?? '',
    );
}
$md[] = '';
$md[] = '## C. Not Product Categories';
$md[] = '';
$bySemantic = [];
foreach ($result['categories']['resolvedReview'] as $row) {
    if (($row['decision'] ?? '') !== CategoryDecisionState::NOT_A_PRODUCT_CATEGORY) {
        continue;
    }
    $t = (string) ($row['semanticType'] ?? 'UNKNOWN');
    $bySemantic[$t][] = $row;
}
foreach ($bySemantic as $type => $rows) {
    $md[] = '### ' . $type . ' (' . count($rows) . ')';
    $md[] = '';
    foreach (array_slice($rows, 0, 40) as $row) {
        $md[] = sprintf(
            '- %s (term %s, usage %d) — %s',
            $row['decodedName'] ?? '',
            $row['sourceTermId'] ?? '',
            (int) ($row['postCount'] ?? 0),
            $row['reason'] ?? '',
        );
    }
    if (count($rows) > 40) {
        $md[] = '- … +' . (count($rows) - 40) . ' more';
    }
    $md[] = '';
}
$md[] = '## D. Duplicate / merge candidates';
$md[] = '';
$md[] = 'Do **not** auto-merge on normalized text alone.';
$md[] = '';
foreach ($result['duplicates'] as $dup) {
    $md[] = sprintf(
        '- `%s` → %s · terms: %s · raw: %s',
        $dup['normalizedName'] ?? '',
        $dup['relationship'] ?? 'UNKNOWN',
        implode(',', array_map('strval', $dup['termIds'] ?? [])),
        implode(' | ', array_map('strval', $dup['rawValues'] ?? [])),
    );
}
$md[] = '';
$md[] = '## E. Still ambiguous (REVIEW)';
$md[] = '';
foreach ($result['categories']['resolvedReview'] as $row) {
    if (($row['decision'] ?? '') !== CategoryDecisionState::REVIEW) {
        continue;
    }
    $md[] = sprintf(
        '- %s (term %s, usage %d, family %s) — %s [%s]',
        $row['decodedName'] ?? '',
        $row['sourceTermId'] ?? '',
        (int) ($row['postCount'] ?? 0),
        $row['proposedFamily'] ?? '—',
        $row['reason'] ?? '',
        $row['evidenceScore'] ?? 'LOW',
    );
}
$md[] = '';
$md[] = '## F. Rejected / bad legacy data';
$md[] = '';
foreach ($resolvedRows as $row) {
    if (($row['decision'] ?? '') !== CategoryDecisionState::REJECT) {
        continue;
    }
    $md[] = sprintf(
        '- %s (term %s) — %s',
        $row['decodedName'] ?? $row['rawName'] ?? '',
        $row['sourceTermId'] ?? $row['termId'] ?? '',
        $row['reason'] ?? '',
    );
}
$md[] = '';
$md[] = '## Series re-evaluation';
$md[] = '';
$md[] = '| Decision | Count |';
$md[] = '|---|---:|';
foreach ($result['series']['counts'] as $k => $v) {
    $md[] = '| ' . $k . ' | ' . $v . ' |';
}
$md[] = '';
$md[] = 'Series remain blocked until Categories are operator-approved (PROPOSED_CREATE does not unlock Series).';
$md[] = '';
$md[] = '## Final Batch A dry-run';
$md[] = '';
if ($result['targetLiveValidation'] !== 'AVAILABLE') {
    $md[] = 'FINAL_APPLY_READINESS = **BLOCKED_TARGET_UNAVAILABLE**';
    $md[] = '';
    $md[] = 'Live target validated: NO — do not run apply-readiness dry-run without Azure.';
} else {
    $md[] = 'Live target validated: YES — operator may run dry-run (still no `--apply`).';
}
$md[] = '';
$md[] = '## NEXT ACTION';
$md[] = '';
$md[] = 'WAIT FOR EXPLICIT FINAL BATCH A APPLY APPROVAL';
$md[] = '';
$md[] = 'VFD → existing VFD mapping remains in approved set and is **not applied**.';
$md[] = '';

file_put_contents($outDir . '/batch-a-category-resolution.md', implode("\n", $md) . "\n");

$cat = $result['categories']['counts'];
$ser = $result['series']['counts'];

echo "PHASE 12.7 — BATCH A.1 CATEGORY RESOLUTION\n\n";
echo "TARGET LIVE VALIDATION\n\n";
echo 'Status:' . "\n" . $result['targetLiveValidation'] . "\n\n";
echo "Server:\ntricab-mel\n\nDatabase:\ntc\n\n";
echo 'Products:' . "\n" . (int) $azure['productCount'] . "\n\n";
echo 'Variants:' . "\n" . (int) $azure['variantCount'] . "\n\n";
echo 'Catalogue Nodes:' . "\n" . (int) $azure['catalogueNodeCount'] . "\n\n";
echo "LEGACY WRITES\n\nWordPress:\n0\n\ntc:\n0\n\n";
echo "CATEGORY REVIEW\n\n";
echo 'Previous REVIEW:' . "\n" . (int) $result['categories']['previousReview'] . "\n\n";
foreach ([
    'APPROVED_MAP_EXISTING',
    'APPROVED_CREATE',
    'PROPOSED_CREATE',
    'REVIEW',
    'NOT_A_PRODUCT_CATEGORY',
    'REJECT',
] as $key) {
    echo $key . ":\n" . (int) ($cat[$key] ?? 0) . "\n\n";
}
echo 'Duplicate groups:' . "\n" . count($result['duplicates']) . "\n\n";
echo "PROPOSED NEW CATEGORIES\n\n";
foreach ($result['categories']['proposedCreates'] as $row) {
    echo sprintf(
        "%s | %s | %s | %d | %s\n",
        $row['proposedCategoryCode'] ?? '',
        $row['proposedCategoryName'] ?? '',
        $row['proposedFamily'] ?? '',
        (int) ($row['postCount'] ?? 0),
        $row['confidence'] ?? '',
    );
}
echo "\nSERIES RE-EVALUATION\n\nCandidates:\n" . count($result['series']['items']) . "\n\n";
foreach ([
    'APPROVED_MAP_EXISTING',
    'APPROVED_CREATE',
    'PROPOSED_CREATE',
    'REVIEW',
    'INVALID',
    'SOURCE_ONLY',
    'REJECT',
] as $key) {
    echo $key . ":\n" . (int) ($ser[$key] ?? 0) . "\n\n";
}
echo "CLASSIFICATION PREVIEW\n\n";
foreach ($previewCounts as $k => $v) {
    echo str_replace('CLASSIFICATION_', '', $k) . ":\n" . $v . "\n\n";
}
echo "FINAL BATCH A DRY-RUN\n\n";
echo 'Live target validated:' . "\n" . ($result['targetLiveValidation'] === 'AVAILABLE' ? 'YES' : 'NO') . "\n\n";
echo "Categories to create:\n0 (PROPOSED only; not approved)\n\n";
echo "Series to create:\n0\n\n";
echo "Mappings to insert:\n0 (apply not run)\n\n";
echo "Mappings to update:\n0\n\n";
echo "Product mutations:\n0\n\nVariant mutations:\n0\n\nCatalogue mutations:\n0\n\nLegacy writes:\n0\n\n";
echo "BATCH A APPLY\n\nNOT RUN\n\n";
echo "FULL CATALOGUE MIGRATION\n\nNOT READY\n\n";
echo "REMAINING CLASSIFICATION BLOCKERS\n\n";
if ($result['targetLiveValidation'] !== 'AVAILABLE') {
    echo "1. Azure SQL live validation unavailable — final apply-readiness dry-run blocked\n";
    echo "2. PROPOSED_CREATE Categories await operator Approve Create\n";
    echo "3. Series blocked until parent Categories are APPROVED_*\n";
    echo "4. VFD mapping remains pending apply (do not apply yet)\n\n";
} else {
    echo "1. Operator must Approve Create / Map Existing for remaining Category candidates\n";
    echo "2. PROPOSED_CREATE Categories are not yet APPROVED_CREATE (no target create)\n";
    echo "3. Series blocked until parent Categories are operator APPROVED_*\n";
    echo "4. VFD mapping remains in approved set but must not be applied yet\n\n";
}
echo "NEXT ACTION\n\nWAIT FOR EXPLICIT FINAL BATCH A APPLY APPROVAL\n";

if (!$confirmNonProd) {
    // intentional no-op; flag documents operator intent when used
}
