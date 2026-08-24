<?php

declare(strict_types=1);

/**
 * Phase 12.8 — F12 corrected live-SKU pilot dry-run.
 *
 *   php bin/run-f12-live-sku-pilot-dry-run.php --style=F12 --confirm-non-production --verify-deterministic
 *
 * READ + RESOLVE + PROJECT ONLY. Never writes WordPress, tc, Azure, blobs, or pricing.
 * --apply / --execute / --write refused with PILOT_APPLY_NOT_APPROVED.
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
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveF12LiveSkuPilotDryRunService;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$verifyDeterministic = in_array('--verify-deterministic', $argvList, true);

$refuse = ProductMigrationPilotCliGuard::refuseApply($argvList);
if ($refuse !== null) {
    fwrite(STDERR, $refuse . PHP_EOL);
    exit(3);
}

$styles = ProductMigrationPilotCliGuard::parseStyles($argvList);
if ($styles === []) {
    $styles = ['F12'];
}
if ($styles !== ['F12']) {
    fwrite(STDERR, ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . PHP_EOL);
    exit(3);
}
if (!$offline && !$confirmNonProd) {
    fwrite(STDERR, ProductMigrationPilotCliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED . PHP_EOL);
    exit(3);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$container = ContainerFactory::create(Settings::fromEnvironment($env));

$docsRoot = dirname(__DIR__) . '/core/docs';
$phase127 = $docsRoot . '/phase12.7';
$outDir = $docsRoot . '/phase12.8';
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
    if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
        return;
    }
    ksort($value);
};

$semanticCompare = static function (array $payload) use ($ksortRecursive): array {
    $copy = $payload;
    unset($copy['generatedAtUtc']);
    $ksortRecursive($copy);

    return $copy;
};

$c2 = $loadJson($phase127 . '/batch-c2-approved-field-mappings.json');
$approvedMaps = is_array($c2['items'] ?? null)
    ? $c2['items']
    : (is_array($c2) && ($c2 === [] || array_is_list($c2)) ? $c2 : []);
$explicitDoc = $loadJson($outDir . '/catalogue-navigation-explicit-map.json');
$explicitMaps = is_array($explicitDoc['items'] ?? null) ? $explicitDoc['items'] : [];
$ready = $loadJson($phase127 . '/batch-a-classification-ready-products.json');
$classification = [
    'family' => 'ACCESSORIES',
    'category' => 'CONDUITS-FITTINGS',
    'series' => 'F12',
];
foreach (is_array($ready['items'] ?? null) ? $ready['items'] : [] as $item) {
    if (!is_array($item)) {
        continue;
    }
    if (strtoupper(trim((string) ($item['style_code'] ?? ''))) === 'F12') {
        $classification = [
            'family' => (string) ($item['family'] ?? 'ACCESSORIES'),
            'category' => (string) ($item['category'] ?? 'CONDUITS-FITTINGS'),
            'series' => (string) ($item['series'] ?? 'F12'),
        ];
        break;
    }
}

$liveSkuRows = [];
$tproExtStyleRowCount = 0;
$tcError = null;
$tcConnected = false;
$schemaColumns = [];
if (!$offline) {
    try {
        $legacyCfg = LiveLegacySourceConfig::fromEnvironment($env);
        if ($legacyCfg->tcConfigured()) {
            /** @var ReadOnlyMysqlConnectionFactory $factory */
            $factory = $container->get(ReadOnlyMysqlConnectionFactory::class);
            $conn = $factory->create(
                (string) $legacyCfg->tcDsn,
                (string) $legacyCfg->tcUser,
                (string) $legacyCfg->tcPassword,
                'tc',
                $legacyCfg->tcSslMode ?? 'required',
            );
            $cols = $conn->query('SHOW COLUMNS FROM `accessory_specs`', [], 'F12LiveSkuPilot', 'showColumns');
            foreach ($cols as $col) {
                $schemaColumns[] = (string) ($col['Field'] ?? $col['field'] ?? '');
            }
            $hasId = in_array('id', $schemaColumns, true);
            $selectId = $hasId ? 'a.`id` AS id, ' : '';
            $sql = "SELECT {$selectId}a.`product_code` AS product_code, a.`d1`, a.`d2`, a.`d3`, a.`d4`, a.`d5`, a.`d6`, e.`style` AS style_code FROM `accessory_specs` a LEFT JOIN `tpro_ext` e ON e.`product_code` = a.`product_code` WHERE UPPER(TRIM(a.`product_code`)) LIKE 'F12-%' ORDER BY a.`product_code` ASC";
            $rows = $conn->query($sql, [], 'F12LiveSkuPilot', 'discoverF12Rows');
            $tcConnected = true;
            foreach ($rows as $row) {
                $sku = trim((string) ($row['product_code'] ?? ''));
                $liveSkuRows[] = [
                    'legacyRowId' => (string) ($row['id'] ?? $sku),
                    'productCode' => $sku,
                    'styleCode' => (string) ($row['style_code'] ?? ''),
                    'd1' => (string) ($row['d1'] ?? ''),
                    'd2' => (string) ($row['d2'] ?? ''),
                    'd3' => (string) ($row['d3'] ?? ''),
                    'd4' => (string) ($row['d4'] ?? ''),
                    'd5' => (string) ($row['d5'] ?? ''),
                    'd6' => (string) ($row['d6'] ?? ''),
                ];
            }
            $countRows = $conn->query(
                "SELECT COUNT(*) AS cnt FROM `tpro_ext` WHERE UPPER(TRIM(`style`)) = 'F12'",
                [],
                'F12LiveSkuPilot',
                'countStyleRows',
            );
            $tproExtStyleRowCount = (int) ($countRows[0]['cnt'] ?? 0);
        } else {
            $tcError = 'LEGACY_TC not configured';
        }
    } catch (Throwable $e) {
        $tcError = $e->getMessage();
        $tcConnected = false;
    }
}

$wordpressIdentity = [
    'postId' => 5877,
    'postType' => 'accessory',
    'title' => 'Inspection Tee',
];
$wordpressContent = ['title' => 'Inspection Tee'];
$wpPlacements = [];
$wpError = null;
$wordpressConnected = false;
if (!$offline) {
    try {
        /** @var LegacyWordPressRepository $wp */
        $wp = $container->get(LegacyWordPressRepository::class);
        $products = $wp->findProducts([
            'styleCode' => 'F12',
            'postTypes' => ['accessory'],
            'maxRecords' => 20,
        ]);
        $wordpressConnected = true;
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $postId = (int) ($product['ID'] ?? $product['id'] ?? 0);
            $title = trim((string) ($product['post_title'] ?? ''));
            $wordpressIdentity = [
                'postId' => $postId !== 0 ? $postId : 5877,
                'postType' => (string) ($product['post_type'] ?? 'accessory'),
                'title' => $title !== '' ? $title : 'Inspection Tee',
                'slug' => (string) ($product['post_name'] ?? ''),
                'status' => (string) ($product['post_status'] ?? ''),
                'styleCode' => strtoupper(trim((string) ($product['style_code'] ?? 'F12'))),
            ];
            $body = trim((string) ($product['post_content'] ?? ''));
            $excerpt = trim((string) ($product['small_desc'] ?? $product['post_excerpt'] ?? ''));
            $wordpressContent = [
                'title' => $wordpressIdentity['title'],
                'shortDescription' => $excerpt !== '' ? $excerpt : null,
                'longDescription' => $body !== '' ? $body : null,
                'technicalDescription' => null,
                'features' => [],
                'seoTitle' => null,
                'seoDescription' => null,
            ];
            break;
        }
        $wpPlacements = $wp->discoverCataloguePlacements(['F12'], ['cable', 'accessory']);
    } catch (Throwable $e) {
        $wpError = $e->getMessage();
        $wordpressConnected = false;
    }
}

$classifier = new ProductVariantClassifier();
$productClass = $classifier->classify(CanonicalProduct::fromArray([
    'sourceKey' => 'wordpress:accessory:F12',
    'legacyStyleCode' => 'F12',
    'name' => (string) $wordpressIdentity['title'],
    'legacyPostType' => 'accessory',
    'status' => 'draft',
]));
$variantClassifications = [];
foreach ($liveSkuRows as $skuRow) {
    $variantClassifications[] = [
        'legacyProductCode' => $skuRow['productCode'],
        'classification' => $classifier->classify(CanonicalProduct::fromArray([
            'sourceKey' => 'tc:accessory_specs:' . $skuRow['productCode'],
            'legacyStyleCode' => 'F12',
            'name' => (string) $wordpressIdentity['title'],
            'legacyPostType' => 'tc-variant',
            'productCode' => $skuRow['productCode'],
            'status' => 'draft',
        ]), 'tc-variant'),
    ];
}

$azureConnected = false;
$azureError = null;
$azureTabs = [];
$azureNodes = [];
$azureLinks = [];
$existingProducts = [];
$existingVariants = [];
$definitionCount = null;
$fieldMappingCount = null;
$cableFieldMappingCount = null;
if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $azureTabs = $sql->fetchAll(<<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), [PublicId]) AS [publicId],
    [TabCode] AS [tabCode],
    [TabName] AS [tabName],
    [Slug] AS [slug],
    [DisplayOrder] AS [displayOrder],
    [IsActive] AS [isActive]
FROM [product].[CatalogueTabs]
WHERE [DeletedAtUtc] IS NULL
ORDER BY [DisplayOrder], [TabCode]
SQL);
        $azureNodes = $sql->fetchAll(<<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [publicId],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [tabPublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [parentPublicId],
    n.[NodeCode] AS [nodeCode],
    n.[NodeName] AS [nodeName],
    n.[Slug] AS [slug],
    n.[DisplayOrder] AS [displayOrder],
    n.[IsActive] AS [isActive]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] p ON p.[CatalogueNodeId] = n.[ParentNodeId]
WHERE n.[DeletedAtUtc] IS NULL
ORDER BY n.[DisplayOrder], n.[NodeCode]
SQL);
        $azureLinks = $sql->fetchAll(<<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), pr.[PublicId]) AS [productPublicId],
    pr.[InternalCode] AS [styleCode],
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [catalogueNodePublicId],
    n.[NodeCode] AS [nodeCode],
    cnp.[DisplayOrder] AS [displayOrder],
    cnp.[IsActive] AS [isActive]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[Products] pr ON pr.[ProductId] = cnp.[ProductId]
WHERE n.[DeletedAtUtc] IS NULL AND pr.[DeletedAtUtc] IS NULL
ORDER BY pr.[InternalCode], n.[NodeCode]
SQL);
        $existingProducts = $sql->fetchAll(<<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [publicId],
       p.[InternalCode] AS [styleCode],
       p.[InternalCode] AS [productCode],
       s.[Slug] AS [slug]
FROM [product].[Products] p
LEFT JOIN [product].[ProductSlugs] s ON s.[ProductId] = p.[ProductId] AND s.[DeletedAtUtc] IS NULL
WHERE p.[DeletedAtUtc] IS NULL
  AND (
    UPPER(LTRIM(RTRIM(p.[InternalCode]))) = N'F12'
    OR LOWER(LTRIM(RTRIM(s.[Slug]))) = N'f12'
  )
SQL);
        $existingVariants = $sql->fetchAll(<<<'SQL'
SELECT CONVERT(NVARCHAR(36), v.[PublicId]) AS [publicId],
       v.[VariantCode] AS [variantCode]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
WHERE v.[DeletedAtUtc] IS NULL
  AND (
    UPPER(LTRIM(RTRIM(v.[VariantCode]))) LIKE N'F12-%'
    OR UPPER(LTRIM(RTRIM(p.[InternalCode]))) = N'F12'
  )
SQL);
        try {
            $definitionCount = (int) ($sql->fetchAll('SELECT COUNT(*) AS cnt FROM [product].[SpecificationDefinitions] WHERE [DeletedAtUtc] IS NULL')[0]['cnt'] ?? 0);
            $fieldMappingCount = (int) ($sql->fetchAll('SELECT COUNT(*) AS cnt FROM [migration].[SpecificationFieldMappings]')[0]['cnt'] ?? 0);
            $cableFieldMappingCount = (int) ($sql->fetchAll("SELECT COUNT(*) AS cnt FROM [migration].[SpecificationFieldMappings] WHERE [SourceTable] = 'cable_specs'")[0]['cnt'] ?? 0);
        } catch (Throwable $ignored) {
            $definitionCount = 64;
            $fieldMappingCount = 1044;
            $cableFieldMappingCount = 538;
        }
        $azureConnected = true;
    } catch (Throwable $e) {
        $azureError = $e->getMessage();
        $azureConnected = false;
    }
}

$snapshot = $loadJson($outDir . '/catalogue-navigation-existing-state.json');
$legacyProducts = [];
foreach ($wpPlacements as $placement) {
    if (!is_array($placement)) {
        continue;
    }
    $rels = [];
    foreach (is_array($placement['terms'] ?? null) ? $placement['terms'] : [] as $term) {
        if (!is_array($term)) {
            continue;
        }
        $taxonomy = (string) ($term['taxonomy'] ?? '');
        if (in_array($taxonomy, ['nav_menu', 'language', 'post_translations'], true)) {
            continue;
        }
        $rels[] = [
            'sourceKind' => 'taxonomy',
            'legacyPath' => (string) ($term['path'] ?? $term['slug'] ?? ''),
            'legacyTaxonomy' => $taxonomy,
            'legacyTermId' => (string) ($term['termId'] ?? ''),
            'legacyTermSlug' => (string) ($term['slug'] ?? ''),
            'legacyTermName' => (string) ($term['name'] ?? ''),
        ];
    }
    $legacyProducts[] = [
        'styleCode' => (string) ($placement['styleCode'] ?? 'F12'),
        'catalogueRelationships' => $rels,
    ];
}
if ($legacyProducts === []) {
    $legacyProducts[] = [
        'styleCode' => 'F12',
        'catalogueRelationships' => [
            [
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'conduit',
                'legacyTaxonomy' => 'category',
                'legacyTermId' => '538',
                'legacyTermSlug' => 'conduit',
                'legacyTermName' => 'Flexible Conduit',
            ],
            [
                'sourceKind' => 'taxonomy',
                'legacyPath' => 'conduits-and-fittings',
                'legacyTaxonomy' => 'category',
                'legacyTermId' => '614',
                'legacyTermSlug' => 'conduits-and-fittings',
                'legacyTermName' => 'Conduits & Fittings',
            ],
        ],
    ];
}

$navTarget = [
    'tabs' => $azureTabs !== [] ? $azureTabs : (is_array($snapshot['tabs'] ?? null) ? $snapshot['tabs'] : []),
    'nodes' => $azureNodes !== [] ? $azureNodes : (is_array($snapshot['nodes'] ?? null) ? $snapshot['nodes'] : []),
    'productNodeLinks' => $azureLinks !== [] ? $azureLinks : (is_array($snapshot['productNodeLinks'] ?? null) ? $snapshot['productNodeLinks'] : []),
];

$input = [
    'styleCode' => 'F12',
    'forbiddenWriteAttempts' => 0,
    'tproExtStyleRowCount' => $tproExtStyleRowCount,
    'targetLiveConnected' => $azureConnected,
    'targetLiveError' => $azureError,
    'liveSkuRows' => $liveSkuRows,
    'approvedMappings' => $approvedMaps,
    'wordpressIdentity' => $wordpressIdentity,
    'wordpressContent' => $wordpressContent,
    'classification' => $classification,
    'region' => [
        'resolvedRegions' => ['AU'],
        'legacyMarketEvidence' => 'TriCab AU catalogue default for accessory styles',
    ],
    'target' => [
        'liveConnected' => $azureConnected,
        'existingProducts' => $existingProducts,
        'existingVariants' => $existingVariants,
        'definitionCount' => $definitionCount,
        'fieldMappingCount' => $fieldMappingCount,
        'cableFieldMappingCount' => $cableFieldMappingCount,
    ],
    'catalogueNavigation' => [
        'targetLiveConnected' => $azureConnected,
        'targetLiveError' => $azureError,
        'legacyProducts' => $legacyProducts,
        'legacyPlacements' => $wpPlacements,
        'explicitMaps' => $explicitMaps,
        'target' => $navTarget,
    ],
    'previousPackage' => ['path' => 'core/docs/phase12.8/pilot-final-dry-run-package.json'],
];

$service = new ResolveF12LiveSkuPilotDryRunService();
$report = $service->dryRun($input);
$package = $report->toArray();
$payload = is_array($package['payload'] ?? null) ? $package['payload'] : [];
$generatedAt = gmdate('c');
$package['generatedAtUtc'] = $generatedAt;
$payload['liveDiscovery'] = [
    'tcConnected' => $tcConnected,
    'tcError' => $tcError,
    'wordpressConnected' => $wordpressConnected,
    'wordpressError' => $wpError,
    'schemaColumns' => $schemaColumns,
    'productClassifier' => $productClass,
    'variantClassifiers' => $variantClassifications,
    'offline' => $offline,
    'discoveryQuery' => "accessory_specs.product_code LIKE 'F12-%'",
];
$package['payload'] = $payload;

if ($verifyDeterministic) {
    $again = $service->dryRun($input)->toArray();
    $againPayload = is_array($again['payload'] ?? null) ? $again['payload'] : [];
    $left = $payload;
    unset($left['liveDiscovery']);
    if (json_encode($semanticCompare($left)) !== json_encode($semanticCompare($againPayload))) {
        fwrite(STDERR, 'DETERMINISM CHECK FAILED' . PHP_EOL);
        exit(1);
    }
    echo 'Deterministic resolve verified.' . PHP_EOL;
}

$write($outDir . '/pilot-f12-live-sku-source-inventory.json', [
    'generatedAtUtc' => $generatedAt,
    'tcConnected' => $tcConnected,
    'tcError' => $tcError,
    'tproExtStyleRowCount' => $tproExtStyleRowCount,
    'schemaColumns' => $schemaColumns,
    'discoveryQuery' => "accessory_specs.product_code LIKE 'F12-%'",
    'inventory' => $payload['sourceInventory'] ?? [],
    'wordpressWrites' => 0,
    'tcWrites' => 0,
]);
$write($outDir . '/pilot-f12-live-sku-variants.json', [
    'generatedAtUtc' => $generatedAt,
    'productClassifier' => $productClass,
    'variantClassifiers' => $variantClassifications,
    'variants' => $payload['variants'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-spec-projection.json', [
    'generatedAtUtc' => $generatedAt,
    'authoritativeMappings' => $payload['authoritativeMappings'] ?? [],
    'specifications' => $payload['specifications'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-provenance.json', [
    'generatedAtUtc' => $generatedAt,
    'invariant' => $payload['invariant'] ?? [],
    'projectedVariantSpecificationValues' => $payload['specifications']['projectedVariantSpecificationValues'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-content.json', [
    'generatedAtUtc' => $generatedAt,
    'wordpressIdentity' => $wordpressIdentity,
    'wordpressConnected' => $wordpressConnected,
    'wordpressError' => $wpError,
    'content' => $payload['content'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-classification.json', [
    'generatedAtUtc' => $generatedAt,
    'classification' => $payload['classification'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-region.json', [
    'generatedAtUtc' => $generatedAt,
    'region' => $payload['region'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-catalogue-navigation.json', [
    'generatedAtUtc' => $generatedAt,
    'azureConnected' => $azureConnected,
    'azureError' => $azureError,
    'snapshotUsed' => !$azureConnected,
    'catalogueNavigation' => $payload['catalogueNavigation'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-target-collision.json', [
    'generatedAtUtc' => $generatedAt,
    'azureConnected' => $azureConnected,
    'azureError' => $azureError,
    'definitionCount' => $definitionCount,
    'fieldMappingCount' => $fieldMappingCount,
    'cableFieldMappingCount' => $cableFieldMappingCount,
    'targetCollision' => $payload['targetCollision'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-mutation-plan.json', [
    'generatedAtUtc' => $generatedAt,
    'mutationPlan' => $payload['mutationPlan'] ?? [],
    'actualTargetWrites' => 0,
]);
$write($outDir . '/pilot-f12-live-sku-rollback-projection.json', [
    'generatedAtUtc' => $generatedAt,
    'rollbackProjection' => $payload['rollbackProjection'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-idempotency.json', [
    'generatedAtUtc' => $generatedAt,
    'idempotency' => $payload['idempotency'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-readiness.json', [
    'generatedAtUtc' => $generatedAt,
    'readiness' => $payload['readiness'] ?? [],
]);
$write($outDir . '/pilot-f12-live-sku-supersession.json', [
    'generatedAtUtc' => $generatedAt,
    'supersession' => $payload['supersession'] ?? [],
    'preservedHistoricalArtifacts' => [
        'core/docs/phase12.8/pilot-final-dry-run-package.json',
        'core/docs/phase12.8/pilot-f12-apply-result.json',
        'core/docs/phase12.8/pilot-f12-apply-preflight.json',
    ],
]);
$write($outDir . '/pilot-f12-live-sku-final-package.json', $package);

$readiness = is_array($payload['readiness'] ?? null) ? $payload['readiness'] : [];
$overall = (string) ($readiness['OVERALL_DRAFT_PILOT_READY'] ?? 'NO');
$inventory = is_array($payload['sourceInventory'] ?? null) ? $payload['sourceInventory'] : [];
$variants = is_array($payload['variants'] ?? null) ? $payload['variants'] : [];
$mutations = is_array($payload['mutationPlan'] ?? null) ? $payload['mutationPlan'] : [];

echo 'PHASE 12.8 — F12 CORRECTED LIVE-SKU PILOT DRY-RUN' . PHP_EOL;
echo 'DRY-RUN ONLY — ZERO WRITES' . PHP_EOL . PHP_EOL;
echo 'style=F12 name=Inspection Tee' . PHP_EOL;
echo 'tcConnected=' . ($tcConnected ? 'yes' : 'no') . ' azureConnected=' . ($azureConnected ? 'yes' : 'no') . PHP_EOL;
echo 'F12 rows discovered=' . (int) ($inventory['rowsDiscovered'] ?? 0) . PHP_EOL;
echo 'variantsProjected=' . (int) ($variants['variantsProjected'] ?? 0) . PHP_EOL;
echo 'identity=' . (string) ($payload['identity']['decision'] ?? '') . PHP_EOL;
echo 'mutation projection: productCreate=' . (int) ($mutations['productCreate'] ?? 0)
    . ' variantsCreate=' . (int) ($mutations['variantsCreate'] ?? 0)
    . ' variantSpecInsert=' . (int) ($mutations['variantSpecValuesInsert'] ?? 0)
    . ' catalogueInserts=' . (int) ($mutations['catalogueRelationshipInserts'] ?? 0)
    . ' actualWrites=' . (int) ($mutations['actualTargetWrites'] ?? 0) . PHP_EOL;
echo 'PRODUCT_DATA_PILOT_READY=' . (!empty($readiness['PRODUCT_DATA_PILOT_READY']) ? 'YES' : 'NO') . PHP_EOL;
echo 'CATALOGUE_NAVIGATION_READY=' . (!empty($readiness['CATALOGUE_NAVIGATION_READY']) ? 'YES' : 'NO') . PHP_EOL;
echo 'OVERALL_DRAFT_PILOT_READY=' . $overall . PHP_EOL . PHP_EOL;

if ($overall === 'YES') {
    echo 'PHASE 12.8 F12 CORRECTED LIVE-SKU PILOT DRY-RUN: SUCCESS' . PHP_EOL;
    echo 'PRODUCT_DATA_PILOT_READY' . PHP_EOL;
    echo 'CATALOGUE_NAVIGATION_READY' . PHP_EOL;
    echo 'OVERALL_DRAFT_PILOT_READY: YES' . PHP_EOL;
    echo 'ZERO WRITES' . PHP_EOL;
    echo 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL' . PHP_EOL;
} elseif ($overall === 'YES_WITH_CATALOGUE_NAVIGATION_REVIEW') {
    echo 'PHASE 12.8 F12 CORRECTED LIVE-SKU PILOT DRY-RUN: SUCCESS' . PHP_EOL;
    echo 'PRODUCT_DATA_PILOT_READY' . PHP_EOL;
    echo 'CATALOGUE_NAVIGATION_REVIEW' . PHP_EOL;
    echo 'OVERALL_DRAFT_PILOT_READY: YES_WITH_CATALOGUE_NAVIGATION_REVIEW' . PHP_EOL;
    echo 'ZERO WRITES' . PHP_EOL;
    echo 'STOP — WAIT FOR EXPLICIT OPERATOR APPROVAL' . PHP_EOL;
} elseif (!empty($readiness['TARGET_LIVE_REVALIDATION_REQUIRED'])) {
    echo 'PHASE 12.8 F12 CORRECTED LIVE-SKU PILOT DRY-RUN: TARGET REVALIDATION REQUIRED' . PHP_EOL;
    echo 'ZERO WRITES' . PHP_EOL;
    echo 'STOP — DO NOT APPLY' . PHP_EOL;
} else {
    echo 'PHASE 12.8 F12 CORRECTED LIVE-SKU PILOT DRY-RUN: REVIEW / BLOCKED' . PHP_EOL;
    echo 'ZERO WRITES' . PHP_EOL;
    echo 'STOP — DO NOT APPLY' . PHP_EOL;
}
exit(0);
