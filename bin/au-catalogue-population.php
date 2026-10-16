<?php

declare(strict_types=1);

/**
 * AU catalogue population executor.
 *
 * Dry-run is the default and runs SELECT statements only.
 *
 *   php bin/au-catalogue-population.php
 *
 * Production writes are refused unless the confirmation pair and one batch are present:
 *
 *   php bin/au-catalogue-population.php --apply --confirm-au-catalogue-population --batch A
 *
 * --apply without --batch is refused. Only the named batch is written.
 * The apply path inserts catalogue nodes and catalogue-node products, and
 * updates display order on the 16 approved nodes when batch E is named.
 * It does not call the admin action that deletes and replaces every product
 * on a node, and it does not delete rows.
 */

const MANIFEST = __DIR__ . '/au-catalogue-population-manifest.json';
const REPORT = '/tmp/tricab-nav/au-population-preflight.json';

$argvList = $argv ?? [];
$apply = in_array('--apply', $argvList, true);
$confirm = in_array('--confirm-au-catalogue-population', $argvList, true);
$batchArg = null;
foreach ($argvList as $index => $arg) {
    if ($arg === '--batch' && isset($argvList[$index + 1])) {
        $batchArg = strtoupper((string) $argvList[$index + 1]);
    } elseif (str_starts_with($arg, '--batch=')) {
        $batchArg = strtoupper(substr($arg, strlen('--batch=')));
    }
}
if ($apply && !$confirm) {
    fwrite(STDERR, "REFUSING WRITE: --apply also requires --confirm-au-catalogue-population\n");
    exit(3);
}
if ($apply && !in_array($batchArg, ['A', 'B', 'C', 'D', 'E'], true)) {
    fwrite(STDERR, "REFUSING WRITE: --apply requires exactly one of --batch A, B, C, D, or E\n");
    exit(3);
}
if (!$apply && $confirm) {
    fwrite(STDERR, "REFUSING: confirmation flag without --apply\n");
    exit(3);
}

$source = (string) file_get_contents(__FILE__);
$forbiddenCall = '->replace' . 'NodeProducts';
$forbiddenAssign = '->assign' . 'NodeProducts';
if (str_contains($source, $forbiddenCall) || str_contains($source, $forbiddenAssign)) {
    fwrite(STDERR, "REFUSING: executor source calls the replace-all assignment action\n");
    exit(3);
}

/** @var array<string, mixed> $manifest */
$manifest = json_decode((string) file_get_contents(MANIFEST), true, 512, JSON_THROW_ON_ERROR);
$problems = [];

$nodes = $manifest['nodes'];
$existingPlacements = $manifest['existingPlacements'];
$orders = $manifest['orderUpdates'];
$products = $manifest['products'];
if (count($nodes) !== 77) {
    $problems[] = 'Manifest node count is ' . count($nodes) . ', expected 77';
}
$newPlacementCount = count(array_filter($nodes, static fn (array $node): bool => $node['internalCode'] !== null));
if ($newPlacementCount + count($existingPlacements) !== 71) {
    $problems[] = 'Manifest placement count is not 71';
}
if (count($orders) !== 16 || count($products) !== 61) {
    $problems[] = 'Manifest order or product count drifted from 16 / 61';
}
$slugs = array_column($nodes, 'slug');
$codes = array_column($nodes, 'nodeCode');
if (count($slugs) !== count(array_unique($slugs)) || count($codes) !== count(array_unique($codes))) {
    $problems[] = 'Manifest node codes or slugs are not unique';
}
if (in_array('fire-resistant', $slugs, true) || in_array('solar', $slugs, true)) {
    $problems[] = 'A new slug reuses fire-resistant or solar';
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems) . "\n");
    exit(2);
}

$tokenJson = shell_exec('az account get-access-token --resource https://database.windows.net/ --output json 2>/dev/null');
$token = is_string($tokenJson) ? json_decode($tokenJson, true) : null;
if (!is_array($token) || !is_string($token['accessToken'] ?? null)) {
    fwrite(STDERR, "Could not get an Azure SQL access token.\n");
    exit(1);
}
$pdo = new PDO(
    sprintf(
        'sqlsrv:server = %s; Database = %s; AccessToken = %s; Encrypt = yes; TrustServerCertificate = no; LoginTimeout = 60',
        'tricab-mel.database.windows.net',
        'tc',
        $token['accessToken'],
    ),
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
unset($token, $tokenJson);

$select = static function (PDO $pdo, string $sql, array $params = []): array {
    if (!preg_match('/^\s*SELECT\b|^\s*WITH\b/i', $sql)) {
        throw new RuntimeException('Dry-run refused a non-select statement');
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
        throw new RuntimeException('Dry-run refused a mutating statement');
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$fail = static function (string $message) use (&$problems): void {
    $problems[] = $message;
};

$codesSql = implode(',', array_fill(0, count($products), '?'));
$productRows = $select(
    $pdo,
    <<<SQL
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[InternalCode],
    p.[ProductName],
    p.[IsActive],
    ps.[StatusCode],
    p.[PublishAtUtc],
    p.[UnpublishAtUtc],
    CASE WHEN ps.[StatusCode] = N'published'
          AND p.[IsActive] = 1
          AND p.[DeletedAtUtc] IS NULL
          AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
          AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
          AND EXISTS (
              SELECT 1
              FROM [product].[ProductRegions] prv
              INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
              WHERE prv.[ProductId] = p.[ProductId]
                AND prv.[DeletedAtUtc] IS NULL
                AND prv.[IsVisible] = 1
                AND rv.[RegionCode] = N'AU'
                AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
                AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
          )
         THEN 1 ELSE 0 END AS [PublicAu],
    CASE WHEN EXISTS (
              SELECT 1
              FROM [product].[ProductRegions] prv
              INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
              WHERE prv.[ProductId] = p.[ProductId]
                AND prv.[DeletedAtUtc] IS NULL
                AND prv.[IsVisible] = 1
                AND rv.[RegionCode] = N'AU'
                AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
                AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
          )
         THEN 1 ELSE 0 END AS [RegionAu]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
  AND p.[InternalCode] IN ({$codesSql})
SQL,
    array_keys($products),
);
$byCode = [];
foreach ($productRows as $row) {
    $byCode[$row['InternalCode']][] = $row;
}
foreach ($products as $code => $expected) {
    $rows = $byCode[$code] ?? [];
    if (count($rows) !== 1) {
        $fail("{$code} matched " . count($rows) . ' product rows');
        continue;
    }
    $row = $rows[0];
    if ((int) $row['ProductId'] !== (int) $expected['productId'] || strcasecmp($row['PublicId'], $expected['publicId']) !== 0) {
        $fail("{$code} identity drifted: live {$row['ProductId']} {$row['PublicId']}");
    }
    if ($row['StatusCode'] !== $expected['status']) {
        $fail("{$code} status is {$row['StatusCode']}, expected {$expected['status']}");
    }
    $regionAu = (int) $row['RegionAu'] === 1;
    $publicAu = (int) $row['PublicAu'] === 1;
    if ($regionAu !== (bool) $expected['auVisible']) {
        $fail("{$code} AU region visibility is " . ($regionAu ? 'yes' : 'no'));
    }
    $expectPublic = $expected['status'] === 'published' && $expected['auVisible'] === true;
    if ($publicAu !== $expectPublic) {
        $fail("{$code} AU public visibility is " . ($publicAu ? 'yes' : 'no'));
    }
}

$parentCodes = array_keys($manifest['existingParents']);
$parentSql = implode(',', array_fill(0, count($parentCodes), '?'));
$parentRows = $select(
    $pdo,
    <<<SQL
SELECT
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[DisplayOrder],
    n.[IsActive],
    n.[IsPublic],
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [TabPublicId],
    (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts] cnp WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]) AS [PlacementCount]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
WHERE n.[DeletedAtUtc] IS NULL
  AND n.[NodeCode] IN ({$parentSql})
SQL,
    $parentCodes,
);
$liveParents = [];
foreach ($parentRows as $row) {
    $liveParents[$row['NodeCode']][] = $row;
}
foreach ($manifest['existingParents'] as $code => $expected) {
    $rows = $liveParents[$code] ?? [];
    if (count($rows) !== 1) {
        $fail("Parent {$code} matched " . count($rows) . ' nodes');
        continue;
    }
    $row = $rows[0];
    if (strcasecmp($row['PublicId'], $expected['publicId']) !== 0 || $row['Slug'] !== $expected['slug']) {
        $fail("Parent {$code} drifted: {$row['PublicId']} {$row['Slug']}");
    }
    if ((int) $row['IsActive'] !== 1 || (int) $row['IsPublic'] !== 1) {
        $fail("Parent {$code} is not active and public");
    }
}

$proposedCodes = array_column($nodes, 'nodeCode');
$codeParams = array_merge($proposedCodes, $slugs);
$occupied = $select(
    $pdo,
    'SELECT n.[NodeCode], n.[NodeName], n.[Slug], n.[DisplayOrder],
            CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
            parent.[NodeCode] AS [ParentCode]
     FROM [product].[CatalogueNodes] n
     LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
     WHERE n.[DeletedAtUtc] IS NULL
       AND (n.[NodeCode] IN (' . implode(',', array_fill(0, count($proposedCodes), '?')) . ')
         OR n.[Slug] IN (' . implode(',', array_fill(0, count($slugs), '?')) . '))',
    $codeParams,
);
$manifestNodes = [];
foreach ($nodes as $node) {
    $manifestNodes[$node['nodeCode']] = $node;
}
$appliedNodeCodes = [];
foreach ($occupied as $row) {
    $manifestNode = $manifestNodes[$row['NodeCode']] ?? null;
    if ($manifestNode === null) {
        $fail("Slug {$row['Slug']} is already used by {$row['NodeCode']}");
        continue;
    }
    $matches = $row['NodeName'] === $manifestNode['nodeName']
        && $row['Slug'] === $manifestNode['slug']
        && (int) $row['DisplayOrder'] === (int) $manifestNode['displayOrder']
        && $row['ParentCode'] === $manifestNode['parentCode'];
    if (!$matches) {
        $fail("Node {$row['NodeCode']} exists and does not match the manifest");
        continue;
    }
    $appliedNodeCodes[$row['NodeCode']] = $row['PublicId'];
}

$slugHits = $select(
    $pdo,
    "SELECT n.[Slug], n.[NodeCode]
     FROM [product].[CatalogueNodes] n
     WHERE n.[DeletedAtUtc] IS NULL AND n.[IsActive] = 1
       AND n.[Slug] IN (N'fire-resistant', N'solar')",
);
$reportSlugs = [];
foreach ($slugHits as $row) {
    $reportSlugs[$row['Slug']][] = $row['NodeCode'];
}

$placementRows = $select(
    $pdo,
    <<<SQL
SELECT
    p.[InternalCode],
    n.[NodeCode],
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId],
    cnp.[CatalogueNodeProductId],
    cnp.[DisplayOrder],
    cnp.[IsActive]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
WHERE p.[DeletedAtUtc] IS NULL
  AND n.[DeletedAtUtc] IS NULL
  AND p.[InternalCode] IN ({$codesSql})
SQL,
    array_keys($products),
);
$placementsByCode = [];
foreach ($placementRows as $row) {
    $placementsByCode[$row['InternalCode']][] = $row['NodeCode'];
}
$plannedPlacementNodes = [];
foreach ($nodes as $node) {
    if ($node['internalCode'] !== null) {
        $plannedPlacementNodes[$node['internalCode']][$node['batch']][] = $node['nodeCode'];
    }
}
foreach ($existingPlacements as $placement) {
    $plannedPlacementNodes[$placement['internalCode']][$placement['batch']][] = $placement['nodeCode'];
}
$appliedPlacementKeys = [];
foreach ($products as $code => $expected) {
    $live = $placementsByCode[$code] ?? [];
    sort($live);
    $kept = $manifest['preservedPlacementNodes'][$code] ?? [];
    sort($kept);
    $allowed = $kept;
    foreach ($plannedPlacementNodes[$code] ?? [] as $batchNodes) {
        $allowed = array_merge($allowed, $batchNodes);
    }
    sort($allowed);
    $unknown = array_values(array_diff($live, $allowed));
    if ($unknown !== []) {
        $fail($code . ' has unexpected placements [' . implode(', ', $unknown) . ']');
    }
    foreach ($live as $nodeCode) {
        if (!in_array($nodeCode, $kept, true)) {
            $appliedPlacementKeys[$code . '|' . $nodeCode] = true;
        }
    }
}
foreach ($existingPlacements as $placement) {
    $parent = $liveParents[$placement['nodeCode']][0] ?? null;
    $key = $placement['internalCode'] . '|' . $placement['nodeCode'];
    if ($parent === null) {
        $fail("Existing target {$placement['nodeCode']} was not found");
        continue;
    }
    $count = (int) $parent['PlacementCount'];
    if ($count === 0) {
        continue;
    }
    if ($count === 1 && isset($appliedPlacementKeys[$key])) {
        continue;
    }
    $fail("Existing target {$placement['nodeCode']} has {$count} placements");
}

$orderIds = array_column($orders, 'publicId');
$orderRows = $select(
    $pdo,
    'SELECT n.[NodeCode], CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId], n.[DisplayOrder],
            CASE WHEN parent.[CatalogueNodeId] IS NULL THEN 1 ELSE 0 END AS [IsRoot]
     FROM [product].[CatalogueNodes] n
     LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
     WHERE n.[DeletedAtUtc] IS NULL
       AND n.[PublicId] IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')',
    $orderIds,
);
$liveOrders = [];
foreach ($orderRows as $row) {
    $liveOrders[strtoupper($row['PublicId'])] = $row;
}
$appliedOrders = 0;
foreach ($orders as $order) {
    $row = $liveOrders[strtoupper($order['publicId'])] ?? null;
    if ($row === null || $row['NodeCode'] !== $order['nodeCode']) {
        $fail("Order target missing: {$order['nodeCode']}");
        continue;
    }
    $liveOrder = (int) $row['DisplayOrder'];
    if ($liveOrder === (int) $order['newOrder']) {
        $appliedOrders++;
    } elseif ($liveOrder !== (int) $order['expectedOrder']) {
        $fail("{$order['nodeCode']} display order is {$row['DisplayOrder']}, expected {$order['expectedOrder']} or {$order['newOrder']}");
    }
    if ((int) $row['IsRoot'] === 1) {
        $fail("{$order['nodeCode']} is a root category");
    }
}
$orderRecognition = [
    'updates' => count($orders),
    'applied' => $appliedOrders,
    'pending' => count($orders) - $appliedOrders,
];

$protected = $select(
    $pdo,
    <<<'SQL'
WITH tree AS (
    SELECT n.[CatalogueNodeId], n.[NodeCode] AS [RootCode]
    FROM [product].[CatalogueNodes] n
    WHERE n.[DeletedAtUtc] IS NULL
      AND n.[NodeCode] IN (N'PUR MARINE', N'ALI-FLEX MARINE', N'PROD-MARINE-IEC-LSZH', N'PROD-ACCESSORIES')
    UNION ALL
    SELECT child.[CatalogueNodeId], tree.[RootCode]
    FROM [product].[CatalogueNodes] child
    INNER JOIN tree ON child.[ParentNodeId] = tree.[CatalogueNodeId]
    WHERE child.[DeletedAtUtc] IS NULL
)
SELECT
    tree.[RootCode],
    COUNT(DISTINCT tree.[CatalogueNodeId]) AS [NodeCount],
    COUNT(cnp.[CatalogueNodeProductId]) AS [PlacementCount]
FROM tree
LEFT JOIN [product].[CatalogueNodeProducts] cnp ON cnp.[CatalogueNodeId] = tree.[CatalogueNodeId]
GROUP BY tree.[RootCode]
SQL,
);
$fingerprintRows = $select(
    $pdo,
    <<<'SQL'
WITH tree AS (
    SELECT n.[CatalogueNodeId], n.[NodeCode] AS [RootCode]
    FROM [product].[CatalogueNodes] n
    WHERE n.[DeletedAtUtc] IS NULL
      AND n.[NodeCode] IN (N'PUR MARINE', N'ALI-FLEX MARINE', N'PROD-MARINE-IEC-LSZH', N'PROD-ACCESSORIES')
    UNION ALL
    SELECT child.[CatalogueNodeId], tree.[RootCode]
    FROM [product].[CatalogueNodes] child
    INNER JOIN tree ON child.[ParentNodeId] = tree.[CatalogueNodeId]
    WHERE child.[DeletedAtUtc] IS NULL
)
SELECT
    tree.[RootCode],
    CONVERT(NVARCHAR(36), node.[PublicId]) AS [NodePublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    cnp.[DisplayOrder],
    cnp.[CatalogueNodeProductId]
FROM tree
INNER JOIN [product].[CatalogueNodes] node ON node.[CatalogueNodeId] = tree.[CatalogueNodeId]
INNER JOIN [product].[CatalogueNodeProducts] cnp ON cnp.[CatalogueNodeId] = tree.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
ORDER BY tree.[RootCode], node.[PublicId], p.[PublicId], cnp.[DisplayOrder]
SQL,
);
$fingerprints = [];
foreach ($fingerprintRows as $row) {
    $fingerprints[$row['RootCode']][] = $row['NodePublicId'] . '|' . $row['ProductPublicId'] . '|' . $row['DisplayOrder'] . '|' . $row['CatalogueNodeProductId'];
}
$fingerprintHashes = [];
foreach ($fingerprints as $root => $lines) {
    $fingerprintHashes[$root] = hash('sha256', implode("\n", $lines));
}

$roots = $select(
    $pdo,
    <<<'SQL'
SELECT
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[DisplayOrder],
    n.[IsActive],
    n.[IsPublic],
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    (SELECT COUNT(*) FROM [product].[CatalogueNodes] c WHERE c.[ParentNodeId] = n.[CatalogueNodeId] AND c.[DeletedAtUtc] IS NULL) AS [ChildCount],
    (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts] cnp WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]) AS [PlacementCount]
FROM [product].[CatalogueNodes] n
WHERE n.[DeletedAtUtc] IS NULL
  AND n.[ParentNodeId] IS NULL
ORDER BY n.[NodeCode]
SQL,
);
$emptyRoots = [];
foreach ($roots as $root) {
    if ((int) $root['ChildCount'] === 0 && (int) $root['PlacementCount'] === 0) {
        $emptyRoots[] = $root['NodeCode'];
    }
}

$drafts = ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'RH', 'RD', 'RF', 'RG', 'V2', 'V3', 'V4'];
foreach ($drafts as $code) {
    $row = $byCode[$code][0] ?? null;
    if ($row === null || $row['StatusCode'] !== 'draft' || (int) $row['PublicAu'] !== 0) {
        $fail("{$code} would be visible on the AU public tree");
    }
}

$visibleLeaves = [];
$hiddenLeaves = [];
foreach ($nodes as $node) {
    if ($node['internalCode'] === null) {
        continue;
    }
    $product = $products[$node['internalCode']];
    $entry = $node['nodeCode'] . ' ' . $node['slug'];
    if ($product['status'] === 'published' && $product['auVisible'] === true) {
        $visibleLeaves[] = $entry;
    } else {
        $hiddenLeaves[] = $entry;
    }
}
foreach ($existingPlacements as $placement) {
    $product = $products[$placement['internalCode']];
    if ($product['status'] === 'published' && $product['auVisible'] === true) {
        $visibleLeaves[] = $placement['nodeCode'] . ' existing';
    }
}

$batchRecognition = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
    $letterNodes = array_values(array_filter($nodes, static fn (array $node): bool => $node['batch'] === $letter));
    $appliedNodes = 0;
    foreach ($letterNodes as $node) {
        if (isset($appliedNodeCodes[$node['nodeCode']])) {
            $appliedNodes++;
        }
    }
    $letterPlacements = array_values(array_filter(
        $letterNodes,
        static fn (array $node): bool => $node['internalCode'] !== null,
    ));
    foreach ($existingPlacements as $placement) {
        if ($placement['batch'] === $letter) {
            $letterPlacements[] = $placement;
        }
    }
    $appliedPlacements = 0;
    foreach ($letterPlacements as $placement) {
        $code = $placement['internalCode'];
        $nodeCode = $placement['nodeCode'];
        if (isset($appliedPlacementKeys[$code . '|' . $nodeCode])) {
            $appliedPlacements++;
        }
    }
    $batchRecognition[$letter] = [
        'nodes' => count($letterNodes),
        'appliedNodes' => $appliedNodes,
        'pendingNodes' => count($letterNodes) - $appliedNodes,
        'placements' => count($letterPlacements),
        'appliedPlacements' => $appliedPlacements,
        'pendingPlacements' => count($letterPlacements) - $appliedPlacements,
    ];
}
$database = $select($pdo, 'SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName]');
$ready = $problems === [];
$report = [
    'readyForExecution' => $ready,
    'mode' => $apply ? 'apply-batch-' . $batchArg : 'dry-run-select-only',
    'requestedBatch' => $batchArg,
    'database' => $database[0] ?? null,
    'batchRecognition' => $batchRecognition,
    'orderRecognition' => $orderRecognition,
    'applyInvoked' => false,
    'replaceAllAssignmentInvoked' => false,
    'counts' => [
        'products' => count($products),
        'nodesToInsert' => count($nodes),
        'placementsToInsert' => $newPlacementCount + count($existingPlacements),
        'orderUpdates' => count($orders),
        'visibleAuLeaves' => count($visibleLeaves),
        'hiddenDraftLeaves' => count($hiddenLeaves),
    ],
    'protected' => $protected,
    'fingerprintSha256' => $fingerprintHashes,
    'preexistingSlugCollisions' => $reportSlugs,
    'emptyRootCategories' => $emptyRoots,
    'rootCategories' => array_map(
        static fn (array $root): array => [
            'nodeCode' => $root['NodeCode'],
            'nodeName' => $root['NodeName'],
            'slug' => $root['Slug'],
            'displayOrder' => (int) $root['DisplayOrder'],
            'childCount' => (int) $root['ChildCount'],
        ],
        $roots,
    ),
    'problems' => $problems,
    'rollbackFile' => '/tmp/tricab-nav/au-population-rollback.sql',
];
file_put_contents('/tmp/tricab-nav/au-population-rollback.sql', rollbackSql($manifest));
if ($apply) {
    if (!$ready) {
        fwrite(STDERR, "REFUSING WRITE: preflight failed\n");
        file_put_contents(REPORT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(2);
    }
    $report['execution'] = applyPopulation($pdo, $manifest, $fingerprintHashes, (string) $batchArg);
    $report['applyInvoked'] = true;
}
file_put_contents(REPORT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($ready ? 0 : 2);

/**
 * Insert-only apply. One transaction per batch. Rolls back that batch and stops
 * when a row is not in the expected state. Does not remove existing placements.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, string> $expectedFingerprints
 * @return array<string, mixed>
 */
function applyPopulation(PDO $pdo, array $manifest, array $expectedFingerprints, string $onlyBatch): array
{
    $mutate = static function (PDO $pdo, string $sql, array $params): array {
        if (preg_match('/\b(DELETE|MERGE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
            throw new RuntimeException('Stopped: mutation is not insert-only');
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    $one = static function (PDO $pdo, string $sql, array $params): ?array {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    };

    if (!in_array($onlyBatch, ['A', 'B', 'C', 'D', 'E'], true)) {
        throw new RuntimeException('Stopped: batch must be one letter');
    }
    $records = ['batch' => $onlyBatch, 'nodes' => [], 'placements' => []];
    $batches = [$onlyBatch];
    foreach ($batches as $batch) {
        $pdo->beginTransaction();
        try {
            foreach (protectedFingerprints($pdo) as $root => $hash) {
                if (in_array($root, ['PUR MARINE', 'ALI-FLEX MARINE', 'PROD-MARINE-IEC-LSZH'], true)
                    && ($expectedFingerprints[$root] ?? null) !== $hash) {
                    throw new RuntimeException("Stopped: {$root} fingerprint drifted before batch {$batch}");
                }
            }
            $createdPublicIds = [];
            if ($batch !== 'E') {
                foreach ($manifest['nodes'] as $node) {
                    if ($node['batch'] !== $batch) {
                        continue;
                    }
                    $record = insertNode($pdo, $mutate, $one, $node, $manifest, $createdPublicIds);
                    $records['nodes'][] = $record;
                    $createdPublicIds[$record['nodeCode']] = $record['publicId'];
                }
                foreach ($manifest['nodes'] as $node) {
                    if ($node['batch'] === $batch && $node['internalCode'] !== null) {
                        $records['placements'][] = insertPlacement($pdo, $mutate, $one, $node['nodeCode'], $node['internalCode'], (int) $node['displayOrder'], $manifest);
                    }
                }
                foreach ($manifest['existingPlacements'] as $placement) {
                    if ($placement['batch'] === $batch) {
                        $records['placements'][] = insertPlacement($pdo, $mutate, $one, $placement['nodeCode'], $placement['internalCode'], (int) $placement['displayOrder'], $manifest);
                    }
                }
            } else {
                foreach ($manifest['orderUpdates'] as $order) {
                    updateOrder($pdo, $mutate, $one, $order);
                }
            }
            foreach (protectedFingerprints($pdo) as $root => $hash) {
                if (in_array($root, ['PUR MARINE', 'ALI-FLEX MARINE', 'PROD-MARINE-IEC-LSZH'], true)
                    && ($expectedFingerprints[$root] ?? null) !== $hash) {
                    throw new RuntimeException("Stopped: {$root} fingerprint changed in batch {$batch}");
                }
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    return $records;
}

/** @param array<string, mixed> $manifest */
function rollbackSql(array $manifest): string
{
    $lines = [
        '-- Manual rollback for the AU catalogue population.',
        '-- Run only after an applied batch, and only if the protected marine fingerprints still match the preflight.',
        '-- This script is not executed by the dry-run.',
        'SET XACT_ABORT ON;',
        'BEGIN TRANSACTION;',
    ];
    foreach ($manifest['orderUpdates'] as $order) {
        $lines[] = sprintf(
            "UPDATE [product].[CatalogueNodes] SET [DisplayOrder] = %d, [UpdatedAtUtc] = SYSUTCDATETIME() WHERE [PublicId] = '%s' AND [NodeCode] = N'%s' AND [DisplayOrder] = %d AND [DeletedAtUtc] IS NULL;",
            (int) $order['expectedOrder'],
            $order['publicId'],
            $order['nodeCode'],
            (int) $order['newOrder'],
        );
    }
    foreach ($manifest['existingPlacements'] as $placement) {
        $productId = (int) $manifest['products'][$placement['internalCode']]['productId'];
        $lines[] = sprintf(
            "DELETE cnp FROM [product].[CatalogueNodeProducts] cnp INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId] INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId] WHERE n.[NodeCode] = N'%s' AND p.[ProductId] = %d AND n.[DeletedAtUtc] IS NULL;",
            $placement['nodeCode'],
            $productId,
        );
    }
    $leafCodes = [];
    $groupCodes = [];
    foreach ($manifest['nodes'] as $node) {
        if ($node['internalCode'] === null) {
            $groupCodes[] = $node['nodeCode'];
        } else {
            $leafCodes[] = $node['nodeCode'];
        }
    }
    $leafList = implode(', ', array_map(static fn (string $code): string => "N'" . $code . "'", $leafCodes));
    $groupList = implode(', ', array_map(static fn (string $code): string => "N'" . $code . "'", $groupCodes));
    $lines[] = "DELETE cnp FROM [product].[CatalogueNodeProducts] cnp INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId] WHERE n.[NodeCode] IN ({$leafList});";
    $lines[] = "DELETE FROM [product].[CatalogueNodes] WHERE [NodeCode] IN ({$leafList}) AND [DeletedAtUtc] IS NULL;";
    $lines[] = "DELETE FROM [product].[CatalogueNodes] WHERE [NodeCode] IN ({$groupList}) AND [DeletedAtUtc] IS NULL;";
    $lines[] = 'COMMIT TRANSACTION;';

    return implode("\n", $lines) . "\n";
}

/** @param callable(PDO, string, array<string, mixed>): array<int, array<string, mixed>> $mutate
 * @return array<string, mixed>
 */
function insertNode(PDO $pdo, callable $mutate, callable $one, array $node, array $manifest, array $createdPublicIds): array
{
    $parent = $manifest['existingParents'][$node['parentCode']] ?? null;
    $parentPublicId = $parent['publicId'] ?? ($createdPublicIds[$node['parentCode']] ?? '');
    if (!preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', (string) $parentPublicId)) {
        throw new RuntimeException('Stopped: parent public id missing for ' . $node['nodeCode']);
    }
    $inserted = $mutate(
        $pdo,
        <<<'SQL'
INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [NodeTypeCode], [DisplayOrder], [IsActive], [IsPublic]
)
OUTPUT INSERTED.[CatalogueNodeId] AS [CatalogueNodeId], CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId], INSERTED.[NodeCode] AS [NodeCode]
SELECT parent.[CatalogueTabId], parent.[CatalogueNodeId], :nodeCode, :nodeName, :slug, N'category', :displayOrder, 1, 1
FROM [product].[CatalogueNodes] parent
WHERE parent.[DeletedAtUtc] IS NULL
  AND parent.[NodeCode] = :parentCode
  AND parent.[PublicId] = :parentPublicId
  AND NOT EXISTS (
      SELECT 1 FROM [product].[CatalogueNodes] existing
      WHERE existing.[DeletedAtUtc] IS NULL
        AND existing.[CatalogueTabId] = parent.[CatalogueTabId]
        AND existing.[NodeCode] = :nodeCodeAbsent
  )
SQL,
        [
            ':nodeCode' => $node['nodeCode'],
            ':nodeName' => $node['nodeName'],
            ':slug' => $node['slug'],
            ':displayOrder' => (int) $node['displayOrder'],
            ':parentCode' => $node['parentCode'],
            ':parentPublicId' => $parentPublicId,
            ':nodeCodeAbsent' => $node['nodeCode'],
        ],
    );
    if ($inserted !== []) {
        return [
            'action' => 'inserted',
            'nodeCode' => $node['nodeCode'],
            'catalogueNodeId' => (int) $inserted[0]['CatalogueNodeId'],
            'publicId' => $inserted[0]['PublicId'],
        ];
    }
    $current = $one(
        $pdo,
        'SELECT n.[CatalogueNodeId], CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId], n.[NodeName], n.[Slug], n.[DisplayOrder], parent.[NodeCode] AS [ParentCode]
         FROM [product].[CatalogueNodes] n
         INNER JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
         WHERE n.[DeletedAtUtc] IS NULL AND n.[NodeCode] = :nodeCode',
        [':nodeCode' => $node['nodeCode']],
    );
    if ($current !== null
        && $current['NodeName'] === $node['nodeName']
        && $current['Slug'] === $node['slug']
        && (int) $current['DisplayOrder'] === (int) $node['displayOrder']
        && $current['ParentCode'] === $node['parentCode']) {
        return [
            'action' => 'already-present',
            'nodeCode' => $node['nodeCode'],
            'catalogueNodeId' => (int) $current['CatalogueNodeId'],
            'publicId' => $current['PublicId'],
        ];
    }
    throw new RuntimeException('Stopped: node ' . $node['nodeCode'] . ' is missing or does not match the manifest');
}

/** @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function insertPlacement(PDO $pdo, callable $mutate, callable $one, string $nodeCode, string $internalCode, int $displayOrder, array $manifest): array
{
    $product = $manifest['products'][$internalCode];
    $existing = $one(
        $pdo,
        'SELECT n.[CatalogueNodeId], CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId],
                cnp.[CatalogueNodeProductId], p.[InternalCode], cnp.[DisplayOrder],
                (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts] allRows WHERE allRows.[CatalogueNodeId] = n.[CatalogueNodeId]) AS [PlacementCount]
         FROM [product].[CatalogueNodes] n
         LEFT JOIN [product].[CatalogueNodeProducts] cnp ON cnp.[CatalogueNodeId] = n.[CatalogueNodeId]
         LEFT JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
         WHERE n.[DeletedAtUtc] IS NULL AND n.[NodeCode] = :nodeCode',
        [':nodeCode' => $nodeCode],
    );
    if ($existing === null) {
        throw new RuntimeException("Stopped: placement node {$nodeCode} does not exist");
    }
    if ((int) $existing['PlacementCount'] === 1 && $existing['InternalCode'] === $internalCode && (int) $existing['DisplayOrder'] === $displayOrder) {
        return [
            'action' => 'already-present',
            'nodeCode' => $nodeCode,
            'internalCode' => $internalCode,
            'catalogueNodeId' => (int) $existing['CatalogueNodeId'],
            'nodePublicId' => $existing['NodePublicId'],
            'placementId' => (int) $existing['CatalogueNodeProductId'],
        ];
    }
    if ((int) $existing['PlacementCount'] !== 0) {
        throw new RuntimeException("Stopped: {$nodeCode} already has placements");
    }
    $inserted = $mutate(
        $pdo,
        <<<'SQL'
INSERT INTO [product].[CatalogueNodeProducts] ([CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive])
OUTPUT INSERTED.[CatalogueNodeProductId] AS [PlacementId], INSERTED.[CatalogueNodeId] AS [CatalogueNodeId]
SELECT n.[CatalogueNodeId], p.[ProductId], :displayOrder, 0, 1
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[Products] p ON p.[ProductId] = :productId AND p.[PublicId] = :productPublicId AND p.[InternalCode] = :internalCode AND p.[DeletedAtUtc] IS NULL
WHERE n.[DeletedAtUtc] IS NULL
  AND n.[NodeCode] = :nodeCode
  AND NOT EXISTS (
      SELECT 1 FROM [product].[CatalogueNodeProducts] cnp
      WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]
  )
SQL,
        [
            ':displayOrder' => $displayOrder,
            ':productId' => (int) $product['productId'],
            ':productPublicId' => $product['publicId'],
            ':internalCode' => $internalCode,
            ':nodeCode' => $nodeCode,
        ],
    );
    if ($inserted === []) {
        throw new RuntimeException("Stopped: placement {$internalCode} on {$nodeCode} was not inserted");
    }

    return [
        'action' => 'inserted',
        'nodeCode' => $nodeCode,
        'internalCode' => $internalCode,
        'catalogueNodeId' => (int) $inserted[0]['CatalogueNodeId'],
        'placementId' => (int) $inserted[0]['PlacementId'],
    ];
}

/** @param array<string, int|string> $order */
function updateOrder(PDO $pdo, callable $mutate, callable $one, array $order): void
{
    $updated = $mutate(
        $pdo,
        <<<'SQL'
UPDATE [product].[CatalogueNodes]
SET [DisplayOrder] = :newOrder, [UpdatedAtUtc] = SYSUTCDATETIME()
OUTPUT INSERTED.[NodeCode] AS [NodeCode]
WHERE [DeletedAtUtc] IS NULL
  AND [PublicId] = :publicId
  AND [NodeCode] = :nodeCode
  AND [DisplayOrder] = :expectedOrder
SQL,
        [
            ':newOrder' => (int) $order['newOrder'],
            ':publicId' => $order['publicId'],
            ':nodeCode' => $order['nodeCode'],
            ':expectedOrder' => (int) $order['expectedOrder'],
        ],
    );
    if ($updated !== []) {
        return;
    }
    $current = $one(
        $pdo,
        'SELECT [DisplayOrder] FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL AND [PublicId] = :publicId AND [NodeCode] = :nodeCode',
        [':publicId' => $order['publicId'], ':nodeCode' => $order['nodeCode']],
    );
    if ($current !== null && (int) $current['DisplayOrder'] === (int) $order['newOrder']) {
        return;
    }
    throw new RuntimeException('Stopped: display order drifted for ' . $order['nodeCode']);
}

/** @return array<string, string> */
function protectedFingerprints(PDO $pdo): array
{
    $statement = $pdo->query(
        <<<'SQL'
WITH tree AS (
    SELECT n.[CatalogueNodeId], n.[NodeCode] AS [RootCode]
    FROM [product].[CatalogueNodes] n
    WHERE n.[DeletedAtUtc] IS NULL
      AND n.[NodeCode] IN (N'PUR MARINE', N'ALI-FLEX MARINE', N'PROD-MARINE-IEC-LSZH')
    UNION ALL
    SELECT child.[CatalogueNodeId], tree.[RootCode]
    FROM [product].[CatalogueNodes] child
    INNER JOIN tree ON child.[ParentNodeId] = tree.[CatalogueNodeId]
    WHERE child.[DeletedAtUtc] IS NULL
)
SELECT tree.[RootCode], CONVERT(NVARCHAR(36), node.[PublicId]) AS [NodePublicId],
       CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId], cnp.[DisplayOrder], cnp.[CatalogueNodeProductId]
FROM tree
INNER JOIN [product].[CatalogueNodes] node ON node.[CatalogueNodeId] = tree.[CatalogueNodeId]
INNER JOIN [product].[CatalogueNodeProducts] cnp ON cnp.[CatalogueNodeId] = tree.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
ORDER BY tree.[RootCode], node.[PublicId], p.[PublicId], cnp.[DisplayOrder]
SQL,
    );
    $lines = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[$row['RootCode']][] = $row['NodePublicId'] . '|' . $row['ProductPublicId'] . '|' . $row['DisplayOrder'] . '|' . $row['CatalogueNodeProductId'];
    }
    $hashes = [];
    foreach ($lines as $root => $rootLines) {
        $hashes[$root] = hash('sha256', implode("\n", $rootLines));
    }

    return $hashes;
}
