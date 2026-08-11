<?php

declare(strict_types=1);

/**
 * Seed / migrate hard-coded Product Panel catalogue into CatalogueTabs + CatalogueNodes.
 *
 * Source of truth (web):
 *   products.ts PRODUCT_CATEGORIES / SPECIAL_APPLICATIONS
 *   accessoryCategories.ts
 *   aliflex.ts
 *   plugSocketTabs.ts
 *   industrialPlugSocketCategories.ts
 *
 * Images preserved as static /assets/... ImageUrl values (Phase 9 Blob optional later).
 *
 * Usage:
 *   php database/scripts/development/seed-customer-catalogue-navigation.php
 *   php database/scripts/development/seed-customer-catalogue-navigation.php --dry-run
 *
 * Safe to re-run (upserts by TabCode / NodeCode within tab).
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$dryRun = in_array('--dry-run', $argv ?? [], true);

$c = ContainerFactory::create(Environment::settings(dirname(__DIR__, 3)));
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);

$actorUserId = 1;

function slugify(string $value): string
{
    $s = strtolower(trim($value));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'node';
}

function codeify(string $prefix, string $value): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');
    $s = trim($s, '-');
    $code = $prefix . '-' . $s;
    return substr($code, 0, 80);
}

/**
 * @param array<string, mixed> $params
 */
function execSql(SqlConnection $db, string $sql, array $params = []): void
{
    $db->execute($sql, $params);
}

/**
 * @param array<string, mixed> $params
 * @return list<array<string, mixed>>
 */
function fetchAll(SqlConnection $db, string $sql, array $params = []): array
{
    return $db->fetchAll($sql, $params);
}

/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>|null
 */
function fetchOne(SqlConnection $db, string $sql, array $params = []): ?array
{
    $rows = $db->fetchAll($sql, $params);
    return $rows[0] ?? null;
}

echo $dryRun ? "[DRY-RUN] Customer catalogue navigation seed\n" : "Seeding customer catalogue navigation...\n";

// Ensure schema exists
$hasTabs = fetchOne($db, "SELECT OBJECT_ID(N'[product].[CatalogueTabs]', N'U') AS [oid]");
if (($hasTabs['oid'] ?? null) === null) {
    fwrite(STDERR, "ERROR: [product].[CatalogueTabs] missing. Run create-customer-catalogue-navigation.sql first.\n");
    exit(1);
}

/** @var list<array{code:string,name:string,slug:string,order:int,description:?string}> $tabs */
$tabs = [
    [
        'code' => 'PRODUCTS',
        'name' => 'Products',
        'slug' => 'products',
        'order' => 10,
        'description' => 'Main product catalogue tab',
    ],
    [
        'code' => 'SPECIAL-APPLICATIONS',
        'name' => 'Special Applications',
        'slug' => 'special-applications',
        'order' => 20,
        'description' => 'Application-focused catalogue tab',
    ],
];

$tabIds = [];

foreach ($tabs as $tab) {
    $existing = fetchOne(
        $db,
        'SELECT [CatalogueTabId] AS [id] FROM [product].[CatalogueTabs] WHERE [TabCode] = :code',
        [':code' => $tab['code']],
    );
    if ($existing) {
        $tabIds[$tab['code']] = (int) $existing['id'];
        if (!$dryRun) {
            execSql(
                $db,
                'UPDATE [product].[CatalogueTabs]
                 SET [TabName] = :name, [Slug] = :slug, [Description] = :description,
                     [DisplayOrder] = :displayOrder, [IsActive] = 1, [IsPublic] = 1,
                     [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actor,
                     [DeletedAtUtc] = NULL, [DeletedByUserId] = NULL
                 WHERE [CatalogueTabId] = :id',
                [
                    ':name' => $tab['name'],
                    ':slug' => $tab['slug'],
                    ':description' => $tab['description'],
                    ':displayOrder' => $tab['order'],
                    ':actor' => $actorUserId,
                    ':id' => $tabIds[$tab['code']],
                ],
            );
        }
        echo "  tab upsert: {$tab['code']} (#{$tabIds[$tab['code']]})\n";
    } else {
        if ($dryRun) {
            echo "  tab create: {$tab['code']}\n";
            $tabIds[$tab['code']] = 0;
            continue;
        }
        execSql(
            $db,
            'INSERT INTO [product].[CatalogueTabs]
                ([TabCode], [TabName], [Description], [Slug], [DisplayOrder], [IsActive], [IsPublic], [CreatedByUserId], [UpdatedByUserId])
             VALUES
                (:code, :name, :description, :slug, :displayOrder, 1, 1, :createdBy, :updatedBy)',
            [
                ':code' => $tab['code'],
                ':name' => $tab['name'],
                ':description' => $tab['description'],
                ':slug' => $tab['slug'],
                ':displayOrder' => $tab['order'],
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );
        $row = fetchOne($db, 'SELECT [CatalogueTabId] AS [id] FROM [product].[CatalogueTabs] WHERE [TabCode] = :code', [':code' => $tab['code']]);
        $tabIds[$tab['code']] = (int) ($row['id'] ?? 0);
        echo "  tab created: {$tab['code']} (#{$tabIds[$tab['code']]})\n";
    }
}

/**
 * Upsert a catalogue node; returns CatalogueNodeId (0 in dry-run when missing).
 *
 * @param array{
 *   tabCode:string,
 *   parentId:?int,
 *   code:string,
 *   name:string,
 *   slug:string,
 *   order:int,
 *   description?:?string,
 *   imageUrl?:?string,
 *   imageAlt?:?string
 * } $node
 */
function upsertNode(SqlConnection $db, array $tabIds, array $node, int $actorUserId, bool $dryRun): int
{
    $tabId = $tabIds[$node['tabCode']] ?? 0;
    if ($tabId <= 0 && !$dryRun) {
        throw new RuntimeException('Missing tab for ' . $node['tabCode']);
    }

    $existing = null;
    if ($tabId > 0) {
        $existing = fetchOne(
            $db,
            'SELECT [CatalogueNodeId] AS [id] FROM [product].[CatalogueNodes]
             WHERE [CatalogueTabId] = :tabId AND [NodeCode] = :code',
            [':tabId' => $tabId, ':code' => $node['code']],
        );
    }

    if ($existing) {
        $id = (int) $existing['id'];
        if (!$dryRun) {
            execSql(
                $db,
                'UPDATE [product].[CatalogueNodes]
                 SET [ParentNodeId] = :parentId,
                     [NodeName] = :name,
                     [Slug] = :slug,
                     [Description] = :description,
                     [ImageUrl] = :imageUrl,
                     [ImageAltText] = :imageAlt,
                     [DisplayOrder] = :displayOrder,
                     [IsActive] = 1,
                     [IsPublic] = 1,
                     [NodeTypeCode] = N\'category\',
                     [UpdatedAtUtc] = SYSUTCDATETIME(),
                     [UpdatedByUserId] = :actor,
                     [DeletedAtUtc] = NULL,
                     [DeletedByUserId] = NULL
                 WHERE [CatalogueNodeId] = :id',
                [
                    ':parentId' => $node['parentId'],
                    ':name' => $node['name'],
                    ':slug' => $node['slug'],
                    ':description' => $node['description'] ?? null,
                    ':imageUrl' => $node['imageUrl'] ?? null,
                    ':imageAlt' => $node['imageAlt'] ?? null,
                    ':displayOrder' => $node['order'],
                    ':actor' => $actorUserId,
                    ':id' => $id,
                ],
            );
        }
        echo "    node upsert: {$node['code']} (#{$id})\n";
        return $id;
    }

    if ($dryRun) {
        echo "    node create: {$node['code']} ({$node['name']})\n";
        return 0;
    }

    execSql(
        $db,
        'INSERT INTO [product].[CatalogueNodes]
            ([CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [Description],
             [NodeTypeCode], [ImageUrl], [ImageAltText], [DisplayOrder], [IsActive], [IsPublic],
             [CreatedByUserId], [UpdatedByUserId])
         VALUES
            (:tabId, :parentId, :code, :name, :slug, :description,
             N\'category\', :imageUrl, :imageAlt, :displayOrder, 1, 1,
             :createdBy, :updatedBy)',
        [
            ':tabId' => $tabId,
            ':parentId' => $node['parentId'],
            ':code' => $node['code'],
            ':name' => $node['name'],
            ':slug' => $node['slug'],
            ':description' => $node['description'] ?? null,
            ':imageUrl' => $node['imageUrl'] ?? null,
            ':imageAlt' => $node['imageAlt'] ?? null,
            ':displayOrder' => $node['order'],
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );
    $row = fetchOne(
        $db,
        'SELECT [CatalogueNodeId] AS [id] FROM [product].[CatalogueNodes]
         WHERE [CatalogueTabId] = :tabId AND [NodeCode] = :code',
        [':tabId' => $tabId, ':code' => $node['code']],
    );
    $id = (int) ($row['id'] ?? 0);
    echo "    node created: {$node['code']} (#{$id})\n";
    return $id;
}

$productCategories = [
    'Accessories',
    'Solar',
    'ALI-FLEX Systems',
    'Steel / Alum Armour',
    'Control',
    'Submersible / Portable',
    'Electrical Contracting',
    'Switch board & Panel',
    'Fire Resistant',
    'Telecoms',
    'Instrumentation',
    'Termite Res/Nylon',
    'Marine IEC LSZH',
    'VFD/VSD',
    'Marine NEK 606',
    'Welding/Battery',
    'Medium Voltage',
];

$specialApplications = [
    'Food & Beverage',
    'Shore Power',
    'Industrial & Marine Switchboard Wire',
    'Temporary Power',
    'Ship Loaders & Cranes',
    'Water Treatment',
];

$accessories = [
    ['id' => 'lugs-links', 'title' => 'Lugs & Links', 'image' => '/assets/lugs.jpg'],
    ['id' => 'terminals', 'title' => 'Terminals', 'image' => '/assets/terminals.jpg'],
    ['id' => 'heat-shrink', 'title' => 'Heat Shrink', 'image' => '/assets/Heat-Shrink.jpg'],
    ['id' => 'glands', 'title' => 'Glands', 'image' => '/assets/glands.jpg'],
    ['id' => 'plugs-sockets', 'title' => 'Plugs & Sockets', 'image' => '/assets/plugs.jpg'],
    ['id' => 'cable-management', 'title' => 'Cable Management', 'image' => '/assets/cable-care.jpg'],
    ['id' => 'cable-ties', 'title' => 'Cable Ties', 'image' => '/assets/ties.jpg'],
    ['id' => 'switchboard', 'title' => 'Switchboard', 'image' => '/assets/switchboard.jpg'],
    ['id' => 'switches', 'title' => 'Switches', 'image' => '/assets/J18-sm.jpg'],
    ['id' => 'solar', 'title' => 'Solar', 'image' => '/assets/solar.jpg'],
    ['id' => 'tools-dies', 'title' => 'Tools & Dies', 'image' => '/assets/tools.jpg'],
    ['id' => 'safety', 'title' => 'Safety', 'image' => '/assets/S01-gr.jpg'],
    ['id' => 'lead', 'title' => 'Lead', 'image' => '/assets/Extension-Leads.png'],
    ['id' => 'certificates', 'title' => 'Certificates', 'image' => null, 'description' => 'Product certifications and compliance documentation'],
];

$plugSocketTabs = [
    ['id' => 'industrial', 'label' => 'Industrial'],
    ['id' => 'ceeform', 'label' => 'CeeForm'],
    ['id' => 'powersure', 'label' => 'PowerSure'],
    ['id' => 'techno-connectors', 'label' => 'Techno Connectors'],
    ['id' => 'connectors', 'label' => 'Connectors'],
];

$industrial = [
    ['id' => 'plug-flat-pin', 'title' => 'Plug Flat Pin', 'image' => '/assets/J09-gr.jpg'],
    ['id' => 'plug-round-pin', 'title' => 'Plug Round Pin', 'image' => '/assets/J01-gr.jpg'],
    ['id' => 'angle-plug-round-pin', 'title' => 'Angle Plug Round Pin', 'image' => '/assets/J02-gr.jpg'],
    ['id' => 'socket-flat-pin', 'title' => 'Socket Flat Pin', 'image' => '/assets/J10-gr.jpg'],
    ['id' => 'socket-round-pin', 'title' => 'Socket Round Pin', 'image' => '/assets/J03-gr.jpg'],
    ['id' => 'outlet-flat-pin', 'title' => 'Outlet Flat Pin', 'image' => '/assets/J06-gr.jpg'],
    ['id' => 'outlet-round-pin', 'title' => 'Outlet Round Pin', 'image' => '/assets/J05-gr.jpg'],
    ['id' => 'switch-outlet-flat-pin', 'title' => 'Switch-Outlet Flat-Pin', 'image' => '/assets/J08-gr.jpg'],
    ['id' => 'switched-outlet-round-pin', 'title' => 'Switched-Outlet Round Pin', 'image' => '/assets/J07-gr.jpg'],
    ['id' => 'appliance-inlet', 'title' => 'Appliance Inlet', 'image' => '/assets/J19-gr.jpg'],
    ['id' => 'enclosures', 'title' => 'Enclosures', 'image' => '/assets/J12-gr.jpg'],
    ['id' => 'rotary-switch', 'title' => 'Rotary Switch', 'image' => '/assets/J11-gr.jpg'],
];

$aliflexSections = [
    'Flexible Cables' => [
        'XL-Single multicore 90c',
        'KL-Single multicore 110c',
        'LC PVC Earth 105c',
        'LC LSZH Earth 125c',
        'XU SDI, Multi, Coll. Scr. & Braid 90c',
        'KU SDI, Multi, Coll. Scr. & Braid 110C',
        'XA SDI, Multi, Control 90C',
        'KA SDI, Multi, Control 110C',
        'XN Termite Resistant 90C',
        'KN Termite Resistant 110C',
        'EA Braid VFD/VSD 110C',
    ],
    'Solar' => [
        'SK 1.5/1.5kV SDI 90C',
        'SK 1.5/1.5kV SDI 110C',
    ],
];

echo "Products tab root categories...\n";
$rootIds = [];
$order = 10;
foreach ($productCategories as $name) {
    $slug = slugify($name);
    $code = codeify('PROD', $name);
    $rootIds[$name] = upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => null,
        'code' => $code,
        'name' => $name,
        'slug' => $slug,
        'order' => $order,
    ], $actorUserId, $dryRun);
    $order += 10;
}

echo "Special Applications roots...\n";
$order = 10;
foreach ($specialApplications as $name) {
    upsertNode($db, $tabIds, [
        'tabCode' => 'SPECIAL-APPLICATIONS',
        'parentId' => null,
        'code' => codeify('SPEC', $name),
        'name' => $name,
        'slug' => slugify($name),
        'order' => $order,
    ], $actorUserId, $dryRun);
    $order += 10;
}

$accessoriesParentId = $rootIds['Accessories'] ?? 0;
echo "Accessories children...\n";
$accessoryIds = [];
$order = 10;
foreach ($accessories as $acc) {
    $accessoryIds[$acc['id']] = upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => $accessoriesParentId > 0 ? $accessoriesParentId : null,
        'code' => codeify('ACC', $acc['id']),
        'name' => $acc['title'],
        'slug' => $acc['id'],
        'order' => $order,
        'description' => $acc['description'] ?? null,
        'imageUrl' => $acc['image'] ?? null,
        'imageAlt' => $acc['title'],
    ], $actorUserId, $dryRun);
    $order += 10;
}

$plugsParentId = $accessoryIds['plugs-sockets'] ?? 0;
echo "Plugs & Sockets children...\n";
$plugTabIds = [];
$order = 10;
foreach ($plugSocketTabs as $tab) {
    $plugTabIds[$tab['id']] = upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => $plugsParentId > 0 ? $plugsParentId : null,
        'code' => codeify('PS', $tab['id']),
        'name' => $tab['label'],
        'slug' => $tab['id'],
        'order' => $order,
    ], $actorUserId, $dryRun);
    $order += 10;
}

$industrialParentId = $plugTabIds['industrial'] ?? 0;
echo "Industrial plug categories...\n";
$order = 10;
foreach ($industrial as $item) {
    upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => $industrialParentId > 0 ? $industrialParentId : null,
        'code' => codeify('IND', $item['id']),
        'name' => $item['title'],
        'slug' => $item['id'],
        'order' => $order,
        'imageUrl' => $item['image'],
        'imageAlt' => $item['title'],
    ], $actorUserId, $dryRun);
    $order += 10;
}

$aliflexParentId = $rootIds['ALI-FLEX Systems'] ?? 0;
echo "ALI-FLEX sections...\n";
$order = 10;
foreach ($aliflexSections as $sectionName => $items) {
    $sectionId = upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => $aliflexParentId > 0 ? $aliflexParentId : null,
        'code' => codeify('ALI', $sectionName),
        'name' => $sectionName,
        'slug' => slugify($sectionName),
        'order' => $order,
    ], $actorUserId, $dryRun);
    $itemOrder = 10;
    foreach ($items as $itemName) {
        upsertNode($db, $tabIds, [
            'tabCode' => 'PRODUCTS',
            'parentId' => $sectionId > 0 ? $sectionId : null,
            'code' => codeify('ALI-ITEM', $itemName),
            'name' => $itemName,
            'slug' => slugify($itemName),
            'order' => $itemOrder,
        ], $actorUserId, $dryRun);
        $itemOrder += 10;
    }
    $order += 10;
}

/**
 * @return list<array{code:string,label:string,productCodes:list<string>}>
 */
function marineSeries(string $code, string $label): array
{
    $upper = strtoupper($code);
    $productCodes = $upper === 'BV' ? ['BVA', 'BV'] : [$upper . 'A', $upper];

    return [
        'code' => $upper,
        'label' => $label,
        'productCodes' => $productCodes,
    ];
}

$marineTabs = [
    'shipboard' => [
        'label' => 'Shipboard',
        'sections' => [
            'Power and Control' => [
                marineSeries('BV', '110C Flame Retardant'),
                marineSeries('DV', '110C Braid, Flame Retardant'),
                marineSeries('RR', '110C Fire Resistant'),
                marineSeries('BH', '110C Braid, Fire Resistant'),
            ],
            'Instrumentation - Collective' => [
                marineSeries('RC', '110C Flame Retardant'),
                marineSeries('RS', '110C Braid, Flame Retardant'),
                marineSeries('ES', '110C Fire Resistant'),
                marineSeries('FJ', '110C Braid, Fire Resistant'),
            ],
            'Instrumentation - Individual and Collective' => [
                marineSeries('EW', '110C Flame Retardant'),
                marineSeries('EG', '110C Braid, Flame Retardant'),
                marineSeries('FC', '110C Fire Resistant'),
                marineSeries('FL', '110C Braid, Fire Resistant'),
            ],
            'Switchboard/Panel Wire' => [],
            'VFD / VSD' => [],
        ],
    ],
    'offshore' => [
        'label' => 'Offshore',
        'sections' => [
            'Power and Control' => [
                marineSeries('PJ', '110C Braid Armoured, Flame Retardant'),
                marineSeries('HT', '110C Braid Armoured, Fire Resistant'),
            ],
            'Instrumentation - Collective' => [
                marineSeries('RB', '110C Braid Armoured, Flame Retardant'),
                marineSeries('FK', '110C Braid Armoured, Fire Resistant'),
            ],
            'Instrumentation - Individual and Collective' => [
                marineSeries('FZ', '110C Braid Armoured, Flame Retardant'),
                marineSeries('FE', '110C Braid Armoured, Fire Resistant'),
            ],
        ],
    ],
];

function linkNodeProduct(SqlConnection $db, int $nodeId, array $internalCodes, int $actorUserId, bool $dryRun): void
{
    if ($nodeId <= 0) {
        return;
    }

    $product = null;
    foreach ($internalCodes as $code) {
        $product = fetchOne(
            $db,
            'SELECT [ProductId] AS [id], [InternalCode] AS [code]
             FROM [product].[Products]
             WHERE [InternalCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $code],
        );
        if ($product) {
            break;
        }
    }

    if (!$product) {
        echo '    product not found for ' . implode('/', $internalCodes) . "\n";
        return;
    }

    if ($dryRun) {
        echo "    would link {$product['code']} -> node #{$nodeId}\n";
        return;
    }

    $existing = fetchOne(
        $db,
        'SELECT [CatalogueNodeProductId] AS [id]
         FROM [product].[CatalogueNodeProducts]
         WHERE [CatalogueNodeId] = :nodeId AND [ProductId] = :productId',
        [':nodeId' => $nodeId, ':productId' => (int) $product['id']],
    );
    if ($existing) {
        echo "    product already linked: {$product['code']} -> node #{$nodeId}\n";
        return;
    }

    execSql(
        $db,
        'INSERT INTO [product].[CatalogueNodeProducts]
            ([CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive], [CreatedByUserId], [UpdatedByUserId])
         VALUES
            (:nodeId, :productId, 0, 0, 1, :createdBy, :updatedBy)',
        [
            ':nodeId' => $nodeId,
            ':productId' => (int) $product['id'],
            ':createdBy' => $actorUserId,
            ':updatedBy' => $actorUserId,
        ],
    );
    echo "    linked {$product['code']} -> node #{$nodeId}\n";
}

$marineParentId = $rootIds['Marine IEC LSZH'] ?? 0;
echo "Marine IEC LSZH Shipboard / Offshore...\n";
$tabOrder = 10;
foreach ($marineTabs as $tabKey => $tab) {
    $tabNodeId = upsertNode($db, $tabIds, [
        'tabCode' => 'PRODUCTS',
        'parentId' => $marineParentId > 0 ? $marineParentId : null,
        'code' => codeify('MARINE', $tabKey),
        'name' => $tab['label'],
        'slug' => 'marine-' . $tabKey,
        'order' => $tabOrder,
    ], $actorUserId, $dryRun);

    $sectionOrder = 10;
    foreach ($tab['sections'] as $sectionName => $items) {
        $sectionId = upsertNode($db, $tabIds, [
            'tabCode' => 'PRODUCTS',
            'parentId' => $tabNodeId > 0 ? $tabNodeId : null,
            'code' => codeify('MARINE-' . $tabKey, $sectionName),
            'name' => $sectionName,
            'slug' => slugify('marine-' . $tabKey . '-' . $sectionName),
            'order' => $sectionOrder,
        ], $actorUserId, $dryRun);

        $itemOrder = 10;
        foreach ($items as $series) {
            $seriesId = upsertNode($db, $tabIds, [
                'tabCode' => 'PRODUCTS',
                'parentId' => $sectionId > 0 ? $sectionId : null,
                'code' => codeify('MARINE-' . $tabKey, $series['code']),
                'name' => $series['code'] . ' ' . $series['label'],
                'slug' => 'marine-' . $tabKey . '-' . strtolower($series['code']),
                'order' => $itemOrder,
                'description' => $series['code'],
            ], $actorUserId, $dryRun);
            linkNodeProduct($db, $seriesId, $series['productCodes'], $actorUserId, $dryRun);
            $itemOrder += 10;
        }
        $sectionOrder += 10;
    }
    $tabOrder += 10;
}

$counts = fetchOne(
    $db,
    'SELECT
        (SELECT COUNT(*) FROM [product].[CatalogueTabs] WHERE [DeletedAtUtc] IS NULL) AS [tabs],
        (SELECT COUNT(*) FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL) AS [nodes]',
);

echo $dryRun
    ? "[DRY-RUN] complete. Existing DB counts — tabs: " . ($counts['tabs'] ?? '?') . ", nodes: " . ($counts['nodes'] ?? '?') . "\n"
    : "Done. tabs=" . ($counts['tabs'] ?? 0) . " nodes=" . ($counts['nodes'] ?? 0) . "\n";

echo "Legacy static web config left in place until Product Panel cutover is validated.\n";
