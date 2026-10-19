<?php

declare(strict_types=1);

/**
 * Places the eight flexible-cable products published after the AU catalogue population
 * (XL, KL, LS, LP, CC, FR, FD, EH) in the categories the legacy AU site lists them under.
 *
 * Dry-run is the default and runs SELECT statements only:
 *
 *   php bin/au-catalogue-new-products-2026-10-19.php
 *
 * Writing needs both flags:
 *
 *   php bin/au-catalogue-new-products-2026-10-19.php --apply --confirm-new-products-placement
 *
 * The apply path inserts catalogue nodes and catalogue-node products and renames the two
 * Flexible Cables "LC ... Earth" leaves to LP and LS. It runs in one transaction, never
 * deletes rows and never touches products or publication state.
 */

const OUT_DIR = __DIR__ . '/../var/au-catalogue-population/2026-10-19-new-products';

const PRODUCTS = [
    'LP' => 444, 'FD' => 445, 'EH' => 446, 'KL' => 447,
    'CC' => 448, 'FR' => 449, 'XL' => 450, 'LS' => 451,
];

/** Groups: nodeCode => [parentCode, nodeName, slug, displayOrder] */
const GROUPS = [
    'CTRL-0.6-1KV' => ['PROD-CONTROL', '0.6/1kV', 'ctrl-0-6-1kv', 30],
    'ELEC-COPPER' => ['PROD-ELECTRICAL-CONTRACTING', 'Copper', 'elec-copper', 10],
    'ELEC-ALUMINIUM' => ['PROD-ELECTRICAL-CONTRACTING', 'Aluminium', 'elec-aluminium', 20],
    'SWB-COPPER' => ['PROD-SWITCH-BOARD-PANEL', 'Copper', 'swb-copper', 10],
];

/** New leaves: parentCode => [prefix, slugPrefix, firstOrder, codes in legacy order] */
const LEAVES = [
    'CTRL-0.6-1KV' => ['CTRL-', 'ctrl-', 10, ['XL', 'KL', 'CC', 'FR']],
    'ELEC-COPPER' => ['ELEC-CU-', 'elec-cu-', 10, ['XL', 'KL', 'LS', 'LP', 'CC', 'FD', 'FR', 'EH']],
    'ELEC-ALUMINIUM' => ['ELEC-AL-', 'elec-al-', 10, ['XL', 'KL', 'LS', 'LP']],
    'SWB-COPPER' => ['SWB-CU-', 'swb-cu-', 10, ['LP', 'KL', 'LS', 'EH']],
    'SPEC-INDUSTRIAL-MARINE-SWITCHBOARD-WIRE' => ['SWIRE-', 'swire-', 20, ['LS']],
    'PROD-FIRE-RESISTANT' => ['FIRE-', 'fire-', 40, ['FR']],
];

/** Existing empty Flexible Cables leaves: nodeCode => [internalCode, expectedName, newName] */
const EXISTING_LEAVES = [
    'ALI-ITEM-XL-SINGLE-MULTICORE-90C' => ['XL', 'XL-Single multicore 90c', null],
    'ALI-ITEM-KL-SINGLE-MULTICORE-110C' => ['KL', 'KL-Single multicore 110c', null],
    'ALI-ITEM-LC-PVC-EARTH-105C' => ['LP', 'LC PVC Earth 105c', 'LP PVC Earth 105C'],
    'ALI-ITEM-LC-LSZH-EARTH-125C' => ['LS', 'LC LSZH Earth 125c', 'LS LSZH Earth 125C'],
];

$argvList = $argv ?? [];
$apply = in_array('--apply', $argvList, true);
$confirm = in_array('--confirm-new-products-placement', $argvList, true);
if ($apply !== $confirm) {
    fwrite(STDERR, "REFUSING: --apply and --confirm-new-products-placement must be given together\n");
    exit(3);
}

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

$select = static function (string $sql, array $params = []) use ($pdo): array {
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql) || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|TRUNCATE|EXEC|EXECUTE)\b/i', $sql)) {
        throw new RuntimeException('Refused a non-select statement in a read');
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$mutate = static function (string $sql, array $params) use ($pdo, $apply): array {
    if (!$apply) {
        throw new RuntimeException('Refused a write during a dry run');
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
};

// Build the plan.
$newNodes = [];
foreach (GROUPS as $code => [$parent, $name, $slug, $order]) {
    $newNodes[] = ['nodeCode' => $code, 'parentCode' => $parent, 'nodeName' => $name, 'slug' => $slug, 'displayOrder' => $order, 'internalCode' => null];
}
foreach (LEAVES as $parent => [$prefix, $slugPrefix, $firstOrder, $codes]) {
    foreach ($codes as $index => $internalCode) {
        $newNodes[] = [
            'nodeCode' => $prefix . $internalCode,
            'parentCode' => $parent,
            'nodeName' => $internalCode,
            'slug' => $slugPrefix . strtolower($internalCode),
            'displayOrder' => $firstOrder + $index * 10,
            'internalCode' => $internalCode,
        ];
    }
}
$placementCount = count(EXISTING_LEAVES) + count(array_filter($newNodes, static fn (array $n): bool => $n['internalCode'] !== null));

// Preflight, read-only.
$problems = [];
$products = [];
foreach ($select(
    'SELECT p.[ProductId], CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId], p.[InternalCode], p.[IsActive], ps.[StatusCode]
     FROM [product].[Products] p INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
     WHERE p.[DeletedAtUtc] IS NULL AND p.[ProductId] IN (' . implode(',', array_values(PRODUCTS)) . ')',
) as $row) {
    $products[$row['InternalCode']] = $row;
}
foreach (PRODUCTS as $code => $id) {
    $row = $products[$code] ?? null;
    if ($row === null || (int) $row['ProductId'] !== $id) {
        $problems[] = "Product {$code} is not product {$id}";
    } elseif ($row['StatusCode'] !== 'published' || (int) $row['IsActive'] !== 1) {
        $problems[] = "Product {$code} is not published and active";
    }
}

$nodeRows = $select(
    'SELECT n.[CatalogueNodeId], CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId], n.[NodeCode], n.[NodeName], n.[Slug], n.[CatalogueTabId],
            (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts] cnp WHERE cnp.[CatalogueNodeId] = n.[CatalogueNodeId]) AS [PlacementCount]
     FROM [product].[CatalogueNodes] n WHERE n.[DeletedAtUtc] IS NULL',
);
$byCode = [];
$slugsByTab = [];
foreach ($nodeRows as $row) {
    $byCode[$row['NodeCode']] = $row;
    $slugsByTab[$row['CatalogueTabId']][$row['Slug']] = true;
}
foreach ($newNodes as $node) {
    if (isset($byCode[$node['nodeCode']])) {
        $problems[] = "Node {$node['nodeCode']} already exists";
    }
    $parent = $byCode[$node['parentCode']] ?? null;
    if ($parent === null && !isset(GROUPS[$node['parentCode']])) {
        $problems[] = "Parent {$node['parentCode']} is missing";
    }
    $rootParent = $parent ?? $byCode[GROUPS[$node['parentCode']][0] ?? ''] ?? null;
    if ($rootParent !== null && isset($slugsByTab[$rootParent['CatalogueTabId']][$node['slug']])) {
        $problems[] = "Slug {$node['slug']} is already used in its tab";
    }
}
foreach (EXISTING_LEAVES as $code => [$internalCode, $expectedName]) {
    $row = $byCode[$code] ?? null;
    if ($row === null) {
        $problems[] = "Existing leaf {$code} is missing";
    } elseif ((int) $row['PlacementCount'] !== 0) {
        $problems[] = "Existing leaf {$code} already has a product";
    } elseif ($row['NodeName'] !== $expectedName) {
        $problems[] = "Existing leaf {$code} is named '{$row['NodeName']}', expected '{$expectedName}'";
    }
}

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'newNodes' => count($newNodes),
    'newGroups' => count(GROUPS),
    'placements' => $placementCount,
    'renames' => 2,
    'problems' => $problems,
    'plan' => ['newNodes' => $newNodes, 'existingLeaves' => EXISTING_LEAVES],
];
if (!is_dir(OUT_DIR)) {
    mkdir(OUT_DIR, 0775, true);
}
file_put_contents(OUT_DIR . '/preflight.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("%s: %d new nodes (%d groups), %d placements, 2 renames, %d problems\n", $report['mode'], count($newNodes), count(GROUPS), $placementCount, count($problems));
foreach ($problems as $problem) {
    echo "  PROBLEM: {$problem}\n";
}
if ($problems !== []) {
    exit(2);
}
if (!$apply) {
    foreach ($newNodes as $node) {
        printf("  + %-14s %-26s under %-40s order %3d%s\n", $node['nodeCode'], $node['nodeName'], $node['parentCode'], $node['displayOrder'], $node['internalCode'] ? ' -> ' . $node['internalCode'] : '');
    }
    foreach (EXISTING_LEAVES as $code => [$internalCode, $expectedName, $newName]) {
        printf("  = %-40s -> %s%s\n", $code, $internalCode, $newName ? " (rename '{$expectedName}' to '{$newName}')" : '');
    }
    exit(0);
}

// Before-snapshot of everything the apply touches.
file_put_contents(OUT_DIR . '/before-snapshot.json', json_encode([
    'takenAtUtc' => gmdate('c'),
    'existingLeaves' => array_map(static fn (string $code): array => $byCode[$code], array_keys(EXISTING_LEAVES)),
    'parents' => array_values(array_filter($byCode, static fn (array $row): bool => in_array($row['NodeCode'], array_unique(array_column($newNodes, 'parentCode')), true))),
    'counts' => $select('SELECT (SELECT COUNT(*) FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL) AS [ActiveNodes], (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts]) AS [Placements]')[0],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$created = [];
$placements = [];
$pdo->beginTransaction();
try {
    foreach ($newNodes as $node) {
        $parentCode = $node['parentCode'];
        $parentId = $created[$parentCode] ?? (int) $byCode[$parentCode]['CatalogueNodeId'];
        $inserted = $mutate(
            <<<'SQL'
INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [NodeTypeCode], [DisplayOrder], [IsActive], [IsPublic]
)
OUTPUT INSERTED.[CatalogueNodeId] AS [CatalogueNodeId]
SELECT parent.[CatalogueTabId], parent.[CatalogueNodeId], :nodeCode, :nodeName, :slug, N'category', :displayOrder, 1, 1
FROM [product].[CatalogueNodes] parent
WHERE parent.[DeletedAtUtc] IS NULL AND parent.[CatalogueNodeId] = :parentId
  AND NOT EXISTS (SELECT 1 FROM [product].[CatalogueNodes] e WHERE e.[DeletedAtUtc] IS NULL AND e.[NodeCode] = :nodeCodeAbsent)
SQL,
            [
                ':nodeCode' => $node['nodeCode'],
                ':nodeName' => $node['nodeName'],
                ':slug' => $node['slug'],
                ':displayOrder' => $node['displayOrder'],
                ':parentId' => $parentId,
                ':nodeCodeAbsent' => $node['nodeCode'],
            ],
        );
        if (count($inserted) !== 1) {
            throw new RuntimeException("Stopped: node {$node['nodeCode']} was not inserted");
        }
        $created[$node['nodeCode']] = (int) $inserted[0]['CatalogueNodeId'];
        if ($node['internalCode'] !== null) {
            $placements[] = [$created[$node['nodeCode']], $node['internalCode'], $node['nodeCode']];
        }
    }
    foreach (EXISTING_LEAVES as $code => [$internalCode, $expectedName, $newName]) {
        $placements[] = [(int) $byCode[$code]['CatalogueNodeId'], $internalCode, $code];
        if ($newName !== null) {
            $renamed = $mutate(
                'UPDATE [product].[CatalogueNodes] SET [NodeName] = :newName, [UpdatedAtUtc] = SYSUTCDATETIME()
                 OUTPUT INSERTED.[CatalogueNodeId] AS [CatalogueNodeId]
                 WHERE [DeletedAtUtc] IS NULL AND [NodeCode] = :nodeCode AND [NodeName] = :expectedName',
                [':newName' => $newName, ':nodeCode' => $code, ':expectedName' => $expectedName],
            );
            if (count($renamed) !== 1) {
                throw new RuntimeException("Stopped: {$code} was not renamed");
            }
        }
    }
    $placementIds = [];
    foreach ($placements as [$nodeId, $internalCode, $nodeCode]) {
        $product = $products[$internalCode];
        $inserted = $mutate(
            <<<'SQL'
INSERT INTO [product].[CatalogueNodeProducts] ([CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive])
OUTPUT INSERTED.[CatalogueNodeProductId] AS [PlacementId]
SELECT :nodeId, p.[ProductId], 10, 0, 1
FROM [product].[Products] p
WHERE p.[ProductId] = :productId AND p.[PublicId] = :publicId AND p.[InternalCode] = :internalCode AND p.[DeletedAtUtc] IS NULL
  AND NOT EXISTS (SELECT 1 FROM [product].[CatalogueNodeProducts] cnp WHERE cnp.[CatalogueNodeId] = :nodeIdAbsent)
SQL,
            [
                ':nodeId' => $nodeId,
                ':productId' => (int) $product['ProductId'],
                ':publicId' => $product['PublicId'],
                ':internalCode' => $internalCode,
                ':nodeIdAbsent' => $nodeId,
            ],
        );
        if (count($inserted) !== 1) {
            throw new RuntimeException("Stopped: {$internalCode} was not placed on {$nodeCode}");
        }
        $placementIds[] = (int) $inserted[0]['PlacementId'];
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, $exception->getMessage() . "\nRolled back; nothing was written.\n");
    exit(1);
}

$rollback = [
    '-- Manual rollback for the 2026-10-19 new-products placement.',
    'BEGIN TRANSACTION;',
    'DELETE FROM [product].[CatalogueNodeProducts] WHERE [CatalogueNodeProductId] IN (' . implode(', ', $placementIds) . ');',
];
$leafIds = [];
$groupIds = [];
foreach ($created as $code => $id) {
    if (isset(GROUPS[$code])) {
        $groupIds[] = $id;
    } else {
        $leafIds[] = $id;
    }
}
$rollback[] = 'DELETE FROM [product].[CatalogueNodes] WHERE [CatalogueNodeId] IN (' . implode(', ', $leafIds) . ');';
$rollback[] = 'DELETE FROM [product].[CatalogueNodes] WHERE [CatalogueNodeId] IN (' . implode(', ', $groupIds) . ');';
foreach (EXISTING_LEAVES as $code => [, $expectedName, $newName]) {
    if ($newName !== null) {
        $rollback[] = sprintf("UPDATE [product].[CatalogueNodes] SET [NodeName] = N'%s' WHERE [NodeCode] = N'%s' AND [NodeName] = N'%s';", $expectedName, $code, $newName);
    }
}
$rollback[] = 'COMMIT TRANSACTION;';
file_put_contents(OUT_DIR . '/rollback.sql', implode("\n", $rollback) . "\n");

$after = $select('SELECT (SELECT COUNT(*) FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL) AS [ActiveNodes], (SELECT COUNT(*) FROM [product].[CatalogueNodeProducts]) AS [Placements]')[0];
file_put_contents(OUT_DIR . '/apply-result.json', json_encode([
    'appliedAtUtc' => gmdate('c'),
    'createdNodes' => $created,
    'placementIds' => $placementIds,
    'counts' => $after,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("applied: %d nodes created, %d placements, 2 renames. Active nodes %d, placements %d\n", count($created), count($placementIds), $after['ActiveNodes'], $after['Placements']);
