<?php

declare(strict_types=1);

/**
 * Read-only export for the AU catalogue gap audit. SELECT statements only.
 */

$outDir = __DIR__;
$tokenJson = shell_exec('az account get-access-token --resource https://database.windows.net/ --output json 2>/dev/null');
$token = is_string($tokenJson) ? json_decode($tokenJson, true) : null;
if (!is_array($token) || !is_string($token['accessToken'] ?? null)) {
    fwrite(STDERR, "Could not get an Azure SQL access token.\n");
    exit(1);
}
$pdo = new PDO(
    'sqlsrv:server = tricab-mel.database.windows.net; Database = tc; AccessToken = ' . $token['accessToken'] . '; Encrypt = yes; TrustServerCertificate = no; LoginTimeout = 60',
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
unset($token, $tokenJson);

$select = static function (PDO $pdo, string $sql, array $params = []): array {
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql) || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
        throw new RuntimeException('Export refused a non-select statement');
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$counts = $select($pdo, <<<'SQL'
SELECT
    (SELECT COUNT(*) FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL) AS [ActiveNodes],
    (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts]) AS [Placements],
    (SELECT COUNT(*) FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1) AS [ActiveProducts],
    (SELECT COUNT(*) FROM [product].[ProductRegions]) AS [ProductRegions],
    DB_NAME() AS [DatabaseName],
    @@SERVERNAME AS [ServerName]
SQL)[0];

$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/bin/au-catalogue-population-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$expectedNodes = [];
$expectedPlacements = [];
foreach ($manifest['nodes'] as $node) {
    if (!in_array($node['batch'], ['A', 'B', 'C', 'D'], true)) {
        continue;
    }
    $expectedNodes[] = $node['nodeCode'];
    if ($node['internalCode'] !== null) {
        $expectedPlacements[] = $node['nodeCode'] . '|' . $node['internalCode'];
    }
}
foreach ($manifest['existingPlacements'] as $placement) {
    if (in_array($placement['batch'], ['A', 'B', 'C', 'D'], true)) {
        $expectedPlacements[] = $placement['nodeCode'] . '|' . $placement['internalCode'];
    }
}

$liveNodes = $select($pdo, 'SELECT [NodeCode] FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL AND [NodeCode] IN (' . implode(',', array_fill(0, count($expectedNodes), '?')) . ')', $expectedNodes);
$liveNodeCodes = array_column($liveNodes, 'NodeCode');
$missingNodes = array_values(array_diff($expectedNodes, $liveNodeCodes));

$livePlacements = $select($pdo, <<<'SQL'
SELECT n.[NodeCode], p.[InternalCode]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
WHERE n.[DeletedAtUtc] IS NULL
SQL);
$livePlacementKeys = [];
foreach ($livePlacements as $row) {
    $livePlacementKeys[$row['NodeCode'] . '|' . $row['InternalCode']] = true;
}
$missingPlacements = array_values(array_filter($expectedPlacements, static fn (string $key): bool => !isset($livePlacementKeys[$key])));

$orderProblems = [];
foreach ($manifest['orderUpdates'] as $order) {
    $rows = $select(
        $pdo,
        'SELECT [DisplayOrder] FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL AND [PublicId] = :publicId AND [NodeCode] = :nodeCode',
        [':publicId' => $order['publicId'], ':nodeCode' => $order['nodeCode']],
    );
    $live = $rows[0]['DisplayOrder'] ?? null;
    if ($live === null || (int) $live !== (int) $order['newOrder']) {
        $orderProblems[] = $order['nodeCode'] . ' order ' . ($live ?? 'missing') . ' expected ' . $order['newOrder'];
    }
}

$baseline = [
    'database' => ['name' => $counts['DatabaseName'], 'server' => $counts['ServerName']],
    'counts' => [
        'activeNodes' => (int) $counts['ActiveNodes'],
        'placements' => (int) $counts['Placements'],
        'activeProducts' => (int) $counts['ActiveProducts'],
        'productRegions' => (int) $counts['ProductRegions'],
    ],
    'expected' => ['activeNodes' => 347, 'placements' => 190, 'activeProducts' => 190, 'productRegions' => 210, 'newNodes' => 77, 'newPlacements' => 71, 'orderUpdates' => 16],
    'foundNewNodes' => count($liveNodeCodes),
    'foundNewPlacements' => count($expectedPlacements) - count($missingPlacements),
    'missingNodes' => $missingNodes,
    'missingPlacements' => $missingPlacements,
    'orderProblems' => $orderProblems,
];
$baseline['ok'] = $baseline['counts'] === [
    'activeNodes' => 347,
    'placements' => 190,
    'activeProducts' => 190,
    'productRegions' => 210,
] && $missingNodes === [] && $missingPlacements === [] && $orderProblems === [] && count($expectedNodes) === 77 && count($expectedPlacements) === 71;
file_put_contents($outDir . '/baseline.json', json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
if (!$baseline['ok']) {
    fwrite(STDERR, "BASELINE MISMATCH\n" . json_encode($baseline, JSON_PRETTY_PRINT) . "\n");
    exit(2);
}

$products = $select($pdo, <<<'SQL'
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[InternalCode],
    p.[LegacyProductId],
    p.[ProductName],
    p.[ShortDescription],
    ps.[StatusCode],
    p.[IsActive],
    CASE WHEN EXISTS (
        SELECT 1 FROM [product].[ProductRegions] prv
        INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
        WHERE prv.[ProductId] = p.[ProductId] AND prv.[DeletedAtUtc] IS NULL AND prv.[IsVisible] = 1
          AND rv.[RegionCode] = N'AU'
          AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
          AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
    ) THEN 1 ELSE 0 END AS [RegionAu],
    CASE WHEN ps.[StatusCode] = N'published' AND p.[IsActive] = 1 AND p.[DeletedAtUtc] IS NULL
          AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
          AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
          AND EXISTS (
        SELECT 1 FROM [product].[ProductRegions] prv
        INNER JOIN [product].[Regions] rv ON rv.[RegionId] = prv.[RegionId]
        WHERE prv.[ProductId] = p.[ProductId] AND prv.[DeletedAtUtc] IS NULL AND prv.[IsVisible] = 1
          AND rv.[RegionCode] = N'AU'
          AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
          AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
    ) THEN 1 ELSE 0 END AS [PublicAu]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
ORDER BY p.[ProductId]
SQL);
$slugs = $select($pdo, <<<'SQL'
SELECT [ProductId], [Slug], [IsPrimary]
FROM [product].[ProductSlugs]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [ProductId], [IsPrimary] DESC, [ProductSlugId]
SQL);
$slugByProduct = [];
foreach ($slugs as $row) {
    $slugByProduct[$row['ProductId']][] = $row['Slug'];
}
foreach ($products as &$product) {
    $product['slugs'] = $slugByProduct[$product['ProductId']] ?? [];
}
unset($product);

$nodes = $select($pdo, <<<'SQL'
SELECT [CatalogueNodeId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [DisplayOrder], [IsActive], [IsPublic]
FROM [product].[CatalogueNodes]
WHERE [DeletedAtUtc] IS NULL
ORDER BY [CatalogueNodeId]
SQL);
$placements = $select($pdo, <<<'SQL'
SELECT cnp.[CatalogueNodeProductId], cnp.[CatalogueNodeId], cnp.[ProductId], cnp.[DisplayOrder], p.[InternalCode]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
ORDER BY cnp.[CatalogueNodeProductId]
SQL);

file_put_contents($outDir . '/new-inventory.json', json_encode([
    'exportedAtUtc' => gmdate('c'),
    'products' => $products,
    'nodes' => $nodes,
    'placements' => $placements,
], JSON_UNESCAPED_SLASHES));
echo "baseline ok\n";
