<?php

declare(strict_types=1);

/**
 * Phase 12.8 — Catalogue Navigation additive mapping dry-run.
 *
 *   php bin/resolve-catalogue-navigation-dry-run.php --dry-run --confirm-non-production
 *   php bin/resolve-catalogue-navigation-dry-run.php --dry-run --offline
 *
 * Never writes WordPress, tc, CatalogueTabs, CatalogueNodes, or product-node links.
 * --apply / --write / --execute are refused.
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
use TriCab\Core\Product\Migration\Application\Service\CatalogueNavigationDryRunCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveCatalogueNavigationAdditiveMappingService;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;

$argvList = $argv ?? [];
$offline = in_array('--offline', $argvList, true);
$confirm = in_array('--confirm-non-production', $argvList, true);
$dryRun = in_array('--dry-run', $argvList, true) || $offline;

$refuse = CatalogueNavigationDryRunCliGuard::refuseApply($argvList);
if ($refuse !== null) {
    fwrite(STDERR, $refuse . "\n");
    exit(3);
}
if (!$dryRun) {
    fwrite(STDERR, "ERROR: --dry-run is required\n");
    exit(1);
}
if (!$offline && !$confirm) {
    fwrite(STDERR, "ERROR: require --confirm-non-production or --offline\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$container = ContainerFactory::create(Settings::fromEnvironment($env));

$docs = dirname(__DIR__) . '/core/docs/phase12.8';
if (!is_dir($docs)) {
    mkdir($docs, 0775, true);
}

$loadJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
};

$write = static function (string $path, mixed $value): void {
    file_put_contents(
        $path,
        json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
};

$explicitDoc = $loadJson($docs . '/catalogue-navigation-explicit-map.json');
$explicitMaps = is_array($explicitDoc['items'] ?? null) ? $explicitDoc['items'] : [];
$identity = $loadJson(dirname(__DIR__) . '/core/docs/phase12.6-mappings/product-identity.proposed.json');
$navProposed = $loadJson(dirname(__DIR__) . '/core/docs/phase12.6-mappings/catalogue-navigation.proposed.json');

$f12Identity = null;
foreach (is_array($identity['products'] ?? null) ? $identity['products'] : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $style = strtoupper((string) ($row['styleCodeNormalized'] ?? $row['styleCodeRaw'] ?? ''));
    if ($style === 'F12') {
        $f12Identity = $row;
        break;
    }
}

$wordpressConnected = false;
$wpPlacements = [];
$wpError = null;
$taxonomySources = [];
if (!$offline) {
    try {
        /** @var LegacyWordPressRepository $wp */
        $wp = $container->get(LegacyWordPressRepository::class);
        $wpPlacements = $wp->discoverCataloguePlacements(['F12'], ['cable', 'accessory']);
        $wordpressConnected = $wpPlacements !== [] || method_exists($wp, 'discoverNavMenus');
        $menus = $wp->discoverNavMenus();
        $taxonomySources[] = 'wp_terms / wp_term_taxonomy / wp_term_relationships';
        $taxonomySources[] = 'wp_postmeta style_code';
        if ($menus !== []) {
            $taxonomySources[] = 'wp_posts nav_menu_item + nav_menu taxonomy';
        }
    } catch (Throwable $e) {
        $wpError = $e->getMessage();
        $wordpressConnected = false;
    }
}

$azure = [
    'connected' => false,
    'error' => null,
    'tabs' => [],
    'nodes' => [],
    'productNodeLinks' => [],
];
if (!$offline) {
    try {
        /** @var SqlConnection $sql */
        $sql = $container->get(SqlConnection::class);
        $azure['tabs'] = $sql->fetchAll(
            <<<'SQL'
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
SQL
        );
        $azure['nodes'] = $sql->fetchAll(
            <<<'SQL'
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
SQL
        );
        $azure['productNodeLinks'] = $sql->fetchAll(
            <<<'SQL'
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
SQL
        );
        $azure['connected'] = true;
    } catch (Throwable $e) {
        $azure['error'] = $e->getMessage();
        $azure['connected'] = false;
    }
}

if ($azure['tabs'] === [] && $azure['nodes'] === []) {
    foreach (is_array($navProposed['existingNodes'] ?? null) ? $navProposed['existingNodes'] : [] as $i => $node) {
        if (!is_array($node)) {
            continue;
        }
        $code = (string) ($node['code'] ?? ('NODE-' . $i));
        $azure['nodes'][] = [
            'publicId' => 'snapshot-node-' . $code,
            'tabPublicId' => 'snapshot-tab-products',
            'parentPublicId' => null,
            'nodeCode' => $code,
            'nodeName' => (string) ($node['name'] ?? $code),
            'slug' => strtolower((string) ($node['name'] ?? $code)),
            'displayOrder' => $i,
            'isActive' => true,
            'snapshot' => true,
        ];
    }
    $azure['tabs'][] = [
        'publicId' => 'snapshot-tab-products',
        'tabCode' => 'PRODUCTS',
        'tabName' => 'Products',
        'slug' => 'products',
        'displayOrder' => 1,
        'isActive' => true,
        'snapshot' => true,
    ];
}

$legacyProducts = [];
if ($wpPlacements === [] && is_array($f12Identity)) {
    $legacyProducts[] = [
        'styleCode' => 'F12',
        'legacyProductName' => (string) ($f12Identity['title'] ?? 'Inspection Tee'),
        'wordpressPostId' => $f12Identity['wordpressPostId'] ?? null,
        'productPublicId' => null,
        'migrationBatchId' => 'projected-catalogue-navigation-f12',
        'catalogueRelationships' => [],
        'productCategoryCode' => 'CONDUITS-FITTINGS',
        'familyCode' => 'ACCESSORIES',
    ];
}
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
    foreach (is_array($placement['navMenus'] ?? null) ? $placement['navMenus'] : [] as $menu) {
        if (!is_array($menu)) {
            continue;
        }
        $rels[] = [
            'sourceKind' => 'nav_menu',
            'legacyPath' => trim((string) ($menu['menuSlug'] ?? '') . '/' . (string) ($menu['menuItemTitle'] ?? ''), '/'),
            'legacyTaxonomy' => 'nav_menu',
            'legacyTermId' => (string) ($menu['menuTermId'] ?? ''),
            'legacyTermSlug' => (string) ($menu['menuSlug'] ?? ''),
            'legacyTermName' => (string) ($menu['menuName'] ?? ''),
        ];
    }
    $legacyProducts[] = [
        'styleCode' => (string) ($placement['styleCode'] ?? ''),
        'legacyProductName' => (string) ($placement['postTitle'] ?? ''),
        'wordpressPostId' => $placement['wordpressPostId'] ?? null,
        'productPublicId' => null,
        'migrationBatchId' => 'projected-catalogue-navigation-batch',
        'catalogueRelationships' => $rels,
    ];
}

$input = [
    'forbiddenWriteAttempts' => 0,
    'legacyProducts' => $legacyProducts,
    'explicitMaps' => $explicitMaps,
    'target' => [
        'tabs' => $azure['tabs'],
        'nodes' => $azure['nodes'],
        'productNodeLinks' => $azure['productNodeLinks'],
    ],
    'legacySource' => [
        'wordpressConnected' => $wordpressConnected,
        'wordpressError' => $wpError,
        'azureConnected' => $azure['connected'],
        'azureError' => $azure['error'],
        'offline' => $offline,
        'taxonomySources' => array_values(array_unique($taxonomySources)),
        'wordpressWrites' => 0,
        'tcWrites' => 0,
    ],
];

$report = (new ResolveCatalogueNavigationAdditiveMappingService())->dryRun($input);
$payload = $report->toArray()['payload'];
$generatedAt = gmdate('c');

$existingState = [
    'generatedAtUtc' => $generatedAt,
    'source' => $azure['connected'] ? 'live-azure-readonly' : 'phase12.6-existing-node-snapshot',
    'azureConnected' => $azure['connected'],
    'tabs' => $azure['tabs'],
    'nodes' => $azure['nodes'],
    'counts' => [
        'tabs' => count($azure['tabs']),
        'nodes' => count($azure['nodes']),
        'productNodeLinks' => count($azure['productNodeLinks']),
    ],
    'writes' => 0,
];
$legacyMap = [
    'generatedAtUtc' => $generatedAt,
    'wordpressConnected' => $wordpressConnected,
    'wordpressError' => $wpError,
    'taxonomySources' => $taxonomySources,
    'placements' => $wpPlacements,
    'f12Identity' => $f12Identity,
    'wordpressWrites' => 0,
    'tcWrites' => 0,
];
$resolution = [
    'generatedAtUtc' => $generatedAt,
    'decisionCounts' => $payload['decisionCounts'] ?? [],
    'resolutions' => $payload['resolutions'] ?? [],
    'ignoredProductCategorySignals' => $payload['ignoredProductCategorySignals'] ?? [],
];
$protected = [
    'generatedAtUtc' => $generatedAt,
    'protectedExistingLinks' => $payload['protectedExistingLinks'] ?? [],
    'count' => count($payload['protectedExistingLinks'] ?? []),
    'modified' => 0,
    'deleted' => 0,
];
$f12Projection = [
    'generatedAtUtc' => $generatedAt,
    'f12' => $payload['f12'] ?? [],
    'apply' => 'NOT_ATTEMPTED',
];
$final = $report->toArray() + [
    'generatedAtUtc' => $generatedAt,
    'artifacts' => [
        'catalogue-navigation-existing-state.json',
        'catalogue-navigation-legacy-map.json',
        'catalogue-navigation-resolution.json',
        'catalogue-navigation-protected-links.json',
        'catalogue-navigation-f12-projection.json',
        'catalogue-navigation-final-dry-run.json',
        'catalogue-navigation-explicit-map.json',
    ],
];

$write($docs . '/catalogue-navigation-existing-state.json', $existingState);
$write($docs . '/catalogue-navigation-legacy-map.json', $legacyMap);
$write($docs . '/catalogue-navigation-resolution.json', $resolution);
$write($docs . '/catalogue-navigation-protected-links.json', $protected);
$write($docs . '/catalogue-navigation-f12-projection.json', $f12Projection);
$write($docs . '/catalogue-navigation-final-dry-run.json', $final);

echo json_encode([
    'status' => 'DRY_RUN_COMPLETE',
    'banner' => 'CATALOGUE NAVIGATION — ADDITIVE ONLY. EXISTING STRUCTURE AND RELATIONSHIPS ARE PROTECTED.',
    'safety' => $payload['safety'] ?? [],
    'f12' => $payload['f12'] ?? [],
    'decisionCounts' => $payload['decisionCounts'] ?? [],
    'projectedMutations' => $payload['projectedMutations'] ?? [],
    'wordpressConnected' => $wordpressConnected,
    'azureConnected' => $azure['connected'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
