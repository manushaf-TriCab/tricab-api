<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only FINAL OPERATOR REVIEW of Style Family candidates.
 * Expands all APPROVE_CANDIDATE families. ZERO WRITES.
 *
 * Usage (from tricab-api):
 *   php bin/style-family-operator-review.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\DiscoverProductStyleFamilyTreeService;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$settings = Environment::settings($basePath);
printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping.\n");
    exit(2);
}
if (!$settings->isDevelopment() || !$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Development Azure SQL is required. Stopping.\n");
    exit(2);
}
if (strcasecmp($settings->dbHost, 'tricab-mel.database.windows.net') !== 0 || strcasecmp($settings->dbDatabase, 'tc') !== 0) {
    fwrite(STDERR, "Not the approved development target. Stopping.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll('SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL SUSER_SNAME()=%s\n", (string) ($identity[0]['LoginName'] ?? ''));
if (strcasecmp($serverName, 'tricab-mel') !== 0 || strcasecmp($databaseName, 'tc') !== 0) {
    fwrite(STDERR, "SQL identity mismatch. Stopping.\n");
    exit(2);
}

$count = static function (SqlConnection $db, string $sql): int {
    return (int) ($db->fetchAll($sql)[0]['cnt'] ?? 0);
};
$before = [
    'styles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'parents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'variants' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];

echo "\nDRY RUN — FINAL OPERATOR REVIEW — NO CHANGES APPLIED\n";

/** @var DiscoverProductStyleFamilyTreeService $service */
$service = $container->get(DiscoverProductStyleFamilyTreeService::class);
$report = $service->dryRun();

$after = [
    'styles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'parents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'variants' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];
foreach ($before as $key => $value) {
    printf("Delta %s=%d\n", $key, $after[$key] - $value);
    if ($after[$key] !== $value) {
        fwrite(STDERR, "Unexpected writes detected.\n");
        exit(3);
    }
}

$CANDIDATE_PARENTS = ['BH','DF','DV','EG','ES','EW','FC','FE','FJ','FK','FZ','HT','NJ','NT','NU','PJ','RB','RR','RS','UP'];

$familiesByParent = [];
foreach ($report['families'] as $family) {
    $familiesByParent[$family['parentStyleCode']] = $family;
}

$alreadyPersisted = [];
$confirmedApply = [];
$approveCandidates = [];
$reviewRels = $report['reviewRelationships'] ?? [];
$rejectRels = $report['rejectedRelationships'] ?? [];
foreach ($report['operatorConfirmedRelationships'] as $rel) {
    if (($rel['applicationState'] ?? '') === 'ALREADY_PERSISTED') {
        $alreadyPersisted[] = $rel;
    } else {
        $confirmedApply[] = $rel;
    }
}
foreach ($report['proposedAdditionalRelationships'] as $rel) {
    if (($rel['decision'] ?? '') === 'APPROVE_CANDIDATE') {
        $approveCandidates[] = $rel;
    } elseif (($rel['decision'] ?? '') === 'REVIEW') {
        $reviewRels[] = $rel;
    } elseif (($rel['decision'] ?? '') === 'REJECT') {
        $rejectRels[] = $rel;
    }
}
foreach ($report['smokeRelationships'] ?? [] as $smoke) {
    $reviewRels[] = $smoke;
}

$approvalTable = [];
foreach (array_merge($alreadyPersisted, $confirmedApply, $approveCandidates, $reviewRels, $rejectRels) as $rel) {
    $approvalTable[] = [
        'parent' => $rel['parentStyleCode'] ?? '',
        'child' => $rel['childStyleCode'] ?? '',
        'product' => $rel['productName'] ?? null,
        'productCode' => $rel['productCode'] ?? $rel['expectedProductStyle'] ?? $rel['childStyleCode'] ?? null,
        'productStyleExists' => !empty($rel['childExists']) ? 'YES' : 'NO',
        'currentParent' => $rel['currentParent'] ?? null,
        'decision' => $rel['decision'] ?? '',
        'confidence' => $rel['confidence'] ?? '',
        'applyAction' => $rel['applyAction'] ?? match (true) {
            ($rel['applicationState'] ?? '') === 'ALREADY_PERSISTED' => 'NO_ACTION_ALREADY_PERSISTED',
            ($rel['decision'] ?? '') === 'REJECT' => 'REJECT',
            ($rel['decision'] ?? '') === 'REVIEW' => 'REVIEW',
            empty($rel['childExists']) => 'CREATE_CHILD_AND_LINK',
            default => 'LINK_EXISTING_CHILD',
        },
    ];
}

$productActions = [];
$replacements = [];
$assignmentsRequired = 0;
foreach ($report['productMappings'] as $map) {
    $decision = (string) ($map['assignmentDecision'] ?? '');
    $action = match ($decision) {
        'ALREADY_ASSIGNED_SAME' => 'ALREADY_CORRECT',
        'EXACT_STYLE_MATCH' => 'ASSIGN_EXISTING_STYLE',
        'MISSING_PRODUCT_STYLE' => 'CREATE_STYLE_THEN_ASSIGN',
        'ASSIGNMENT_CONFLICT' => 'REPLACE_ASSIGNMENT',
        'REVIEW' => 'REVIEW',
        default => 'REVIEW',
    };
    if ($action !== 'ALREADY_CORRECT') {
        ++$assignmentsRequired;
    }
    if ($action === 'REPLACE_ASSIGNMENT') {
        $replacements[] = $map;
    }
    $productActions[] = [
        'productPublicId' => $map['productPublicId'],
        'productName' => $map['productName'],
        'productCode' => $map['productCode'],
        'exactProductStyle' => $map['exactProductStyle'],
        'parentProductStyle' => $map['proposedParentStyle'],
        'currentAssignment' => $map['currentAssignment'],
        'futureRequiredAction' => $action,
        'assignmentDecision' => $decision,
        'styleExists' => !empty($map['styleExists']),
    ];
}

$createCount = count($report['missingChildStyleCandidates']);
$linkConfirmed = count($confirmedApply);
$linkApprove = count($approveCandidates);
$newParentLinks = $linkConfirmed + $linkApprove;
$impact = [
    'productStylesBefore' => $before['styles'],
    'productStylesAfterPredicted' => $before['styles'] + $createCount,
    'parentLinksBefore' => $before['parents'],
    'parentLinksAfterPredicted' => $before['parents'] + $newParentLinks,
    'assignmentsBefore' => $before['assignments'],
    // one existing conflict becomes correct; 26 exact + 73 after-create + 0 already correct
    'assignmentsAfterPredicted' => count($report['productMappings']),
    'eligibleExactMappingsBefore' => (int) ($report['counters']['exactProductStyleAssignments'] ?? 0),
    'eligibleExactMappingsAfterPredicted' => count($report['productMappings']),
    'stylesToCreate' => $createCount,
    'parentLinksToAdd' => $newParentLinks,
    'replacements' => count($replacements),
];

$cycle = $report['cycleValidation'] ?? ['selfCycles' => 0, 'directCycles' => 0, 'deepCycles' => 0];

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$package = [
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'isProduction' => false,
    ],
    'before' => $before,
    'after' => $after,
    'writeGuard' => $report['writeGuard'],
    'cycleValidation' => $cycle,
    'packages' => [
        'A_ALREADY_PERSISTED_CONFIRMED' => $alreadyPersisted,
        'B_OPERATOR_CONFIRMED_REQUIRING_APPLY' => $confirmedApply,
        'C_ADDITIONAL_APPROVE_CANDIDATES' => $approveCandidates,
        'D_REVIEW' => $reviewRels,
        'E_REJECT' => $rejectRels,
        'F_STANDALONE' => $report['standaloneStyles'],
    ],
    'approvalTable' => $approvalTable,
    'missingChildStyles' => $report['missingChildStyleCandidates'],
    'productAssignmentPackage' => $productActions,
    'assignmentReplacements' => $replacements,
    'applyImpactPreview' => $impact,
    'families' => $report['families'],
    'proposedTree' => $report['proposedTree'],
    'counters' => $report['counters'],
];
file_put_contents($outDir . '/style-family-operator-review.json', json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// -------- PRINT FULL REPORT --------

echo "\n============================================================\n";
echo "1. OPERATOR-CONFIRMED FAMILIES\n";
echo "============================================================\n";
foreach (['BV', 'RC'] as $parent) {
    $family = $familiesByParent[$parent] ?? null;
    if ($family === null) {
        echo "{$parent}: MISSING FROM REPORT\n";
        continue;
    }
    printf("\n%s [%s]\n", $parent, $family['decision']);
    $meta = $family['parentMetadata'] ?? [];
    printf("  Parent ProductStyle exists: %s\n", !empty($family['parentExists']) ? 'YES' : 'NO');
    printf("  Parent Product exists: %s\n", !empty($family['parentProductExists']) ? 'YES' : 'NO');
    printf("  DisplayName: %s\n", $meta['displayName'] ?? 'null');
    printf("  ParentStyleId: %s\n", $meta['parentStyleId'] ?? 'NULL');
    printf("  IsActive: %s\n", isset($meta['isActive']) ? ($meta['isActive'] ? 'YES' : 'NO') : 'n/a');
    foreach ($family['relationships'] as $rel) {
        $state = ($rel['applicationState'] ?? '') === 'ALREADY_PERSISTED' ? 'PERSISTED' : 'REQUIRES_APPLY';
        printf("  ├── %s  [%s]\n", $rel['childStyleCode'], $state);
        printf("  │     Product: %s\n", $rel['productName'] ?? 'none');
        printf("  │     Product.Code: %s\n", $rel['productCode'] ?? $rel['childStyleCode']);
        printf("  │     ProductPublicId: %s\n", $rel['productPublicId'] ?? 'none');
        printf("  │     ProductStyle exists: %s\n", !empty($rel['childExists']) ? 'YES' : 'NO');
        printf("  │     Current Parent: %s\n", $rel['currentParent'] ?? 'none');
        printf("  │     Current Assignment: %s\n", $rel['currentAssignment'] ?? 'none');
        printf("  │     Expected Assignment: %s\n", $rel['expectedAssignment'] ?? $rel['childStyleCode']);
        printf("  │     Apply Action: %s\n", $rel['applyAction'] ?? '');
        printf("  │     Code evidence: %s\n", $rel['codeEvidence'] ?? 'NO_EVIDENCE');
        printf("  │     Classification: %s\n", $rel['classificationEvidence'] ?? 'NO_EVIDENCE');
        printf("  │     Specifications: %s\n", $rel['sharedSpecEvidence'] ?? 'NO_EVIDENCE');
        printf("  │     Media: %s\n", $rel['sharedMediaEvidence'] ?? 'NO_EVIDENCE');
        printf("  │     Confidence: %s\n", $rel['confidence'] ?? '');
    }
}

echo "\n============================================================\n";
echo "2–5. ALL 20 APPROVE_CANDIDATE FAMILIES (EXPANDED)\n";
echo "============================================================\n";
foreach ($CANDIDATE_PARENTS as $parent) {
    $family = $familiesByParent[$parent] ?? null;
    if ($family === null) {
        echo "\n{$parent} [NOT DISCOVERED]\n";
        continue;
    }
    $meta = $family['parentMetadata'] ?? [];
    $spec = $family['specificationShape'] ?? [];
    $group = $family['groupClassification'] ?? [];
    $inh = $family['inheritanceCandidate'] ?? [];
    $children = $family['childStyleCodes'] ?? [];
    $existingChildren = 0;
    $missingChildren = 0;
    foreach ($family['relationships'] as $rel) {
        if (!empty($rel['childExists'])) {
            ++$existingChildren;
        } else {
            ++$missingChildren;
        }
    }
    printf("\n%s [%s]\n", $parent, $family['decision']);
    foreach ($children as $i => $child) {
        $branch = $i === count($children) - 1 ? '└──' : '├──';
        echo "{$branch} {$child}\n";
    }
    printf("Parent ProductStyle exists: %s\n", !empty($family['parentExists']) ? 'YES' : 'NO');
    printf("Parent Product exists: %s\n", !empty($family['parentProductExists']) ? 'YES' : 'NO');
    printf("DisplayName: %s\n", $meta['displayName'] ?? 'null');
    printf("ParentStyleId: %s\n", $meta['parentStyleId'] ?? 'NULL');
    printf("IsActive: %s\n", isset($meta['isActive']) ? ($meta['isActive'] ? 'YES' : 'NO') : 'n/a');
    printf("Inventory-only parent: %s\n", !empty($meta['inventoryOnly']) ? 'YES' : 'NO');
    printf("Existing persisted children on parent: %s\n", (string) ($meta['existingChildCount'] ?? 0));
    printf("Child products: %d\n", count($children));
    printf("Child styles existing: %d\n", $existingChildren);
    printf("Child styles missing: %d\n", $missingChildren);
    printf("Shared Family: %s\n", implode(',', $group['sharedFamilyCodes'] ?? []) ?: 'NO_EVIDENCE');
    printf("Shared Category: %s\n", implode(',', $group['sharedCategoryCodes'] ?? []) ?: 'NO_EVIDENCE');
    printf("Series relationship: %s\n", implode(',', $group['sharedSeriesCodes'] ?? []) ?: 'NO_EVIDENCE');
    printf("Shared specification definitions: %d\n", (int) ($spec['sharedDefinitions'] ?? 0));
    printf("Common-value specifications: %d\n", (int) ($spec['commonValueDefinitions'] ?? 0));
    printf("Variable specifications: %d\n", (int) ($spec['variableDefinitions'] ?? 0));
    printf("Child-specific specifications: %d\n", (int) ($spec['childSpecificDefinitions'] ?? 0));
    printf("Media: %s\n", $family['mediaEvidence'] ?? 'NO_MEDIA_EVIDENCE');
    printf("Recommended inheritance potential:\n");
    printf("  Specifications: %s\n", $inh['specifications'] ?? 'NONE');
    printf("  Images: %s\n", $inh['images'] ?? 'NONE');
    $absent = $family['absentPatternSiblings'] ?? [];
    if ($absent !== []) {
        echo "Expected-pattern siblings absent (NO STYLE CREATION PROPOSED):\n";
        foreach ($absent as $code) {
            echo "  {$code}\n";
        }
    }
    echo "Edges:\n";
    foreach ($family['relationships'] as $rel) {
        printf("  Parent: %s\n", $rel['parentStyleCode']);
        printf("  Child: %s\n", $rel['childStyleCode']);
        printf("  Product: %s\n", $rel['productName'] ?? 'none');
        printf("  Product.Code: %s\n", $rel['productCode'] ?? $rel['childStyleCode']);
        printf("  ProductPublicId: %s\n", $rel['productPublicId'] ?? 'none');
        printf("  ProductStyle exists: %s\n", !empty($rel['childExists']) ? 'YES' : 'NO');
        printf("  Current Parent: %s\n", $rel['currentParent'] ?? 'none');
        printf("  Current Assignment: %s\n", $rel['currentAssignment'] ?? 'none');
        printf("  Expected Assignment: %s\n", $rel['expectedAssignment'] ?? $rel['childStyleCode']);
        printf("  Code/suffix evidence: %s (suffix=%s)\n", $rel['codeEvidence'] ?? 'NO_EVIDENCE', $rel['suffix'] ?? 'none');
        printf("  Classification evidence: %s\n", $rel['classificationEvidence'] ?? 'NO_EVIDENCE');
        printf("  Product Family/Category/Series: %s / %s / %s\n", $rel['productFamilyCode'] ?? 'NO_EVIDENCE', $rel['productCategoryCode'] ?? 'NO_EVIDENCE', $rel['productSeriesCode'] ?? 'NO_EVIDENCE');
        printf("  Specification evidence: %s\n", $rel['sharedSpecEvidence'] ?? 'NO_EVIDENCE');
        printf("  Media evidence: %s\n", $rel['sharedMediaEvidence'] ?? 'NO_EVIDENCE');
        printf("  Confidence: %s\n", $rel['confidence'] ?? '');
        printf("  Decision: %s\n", $rel['decision'] ?? '');
        printf("  Apply Action: %s\n", $rel['applyAction'] ?? '');
        echo "  ---\n";
    }
}

echo "\n============================================================\n";
echo "7. UP SPECIAL CASE\n";
echo "============================================================\n";
$upFamily = $familiesByParent['UP'] ?? null;
$upProduct = null;
foreach ($report['productMappings'] as $map) {
    if ($map['productCode'] === 'UP') {
        $upProduct = $map;
        break;
    }
}
if ($upProduct !== null) {
    printf("UP Product → Style UP\n");
    printf("  Product: %s\n", $upProduct['productName']);
    printf("  ProductPublicId: %s\n", $upProduct['productPublicId']);
    printf("  Exact ProductStyle: UP\n");
    printf("  Proposed Parent: %s\n", $upProduct['proposedParentStyle'] ?? 'NULL (root/standalone as product)');
    printf("  Current Assignment: %s\n", $upProduct['currentAssignment'] ?? 'none');
    printf("  Assignment Decision: %s\n", $upProduct['assignmentDecision']);
    printf("  mapsToParent forbidden: YES (Product UP maps to Style UP, never to children)\n");
}
if ($upFamily !== null) {
    echo "UP parent → child styles:\n";
    foreach ($upFamily['relationships'] as $rel) {
        printf(
            "  UP → %s  Decision=%s  Product=%s  StyleExists=%s  Apply=%s\n",
            $rel['childStyleCode'],
            $rel['decision'],
            $rel['productName'] ?? 'none',
            !empty($rel['childExists']) ? 'YES' : 'NO',
            $rel['applyAction'] ?? '',
        );
    }
}

echo "\n============================================================\n";
echo "11. BVA-X\n";
echo "============================================================\n";
foreach ($report['smokeRelationships'] ?? [] as $smoke) {
    printf(
        "%s → %s  Decision=%s  Status=%s\n",
        $smoke['parentStyleCode'],
        $smoke['childStyleCode'],
        $smoke['decision'],
        'REVIEW / EXISTING SMOKE — NOT IN APPROVED APPLY PACKAGE',
    );
    printf("  Reason: %s\n", $smoke['reason'] ?? '');
}

echo "\n============================================================\n";
echo "12. STANDALONE STYLES\n";
echo "============================================================\n";
foreach ($report['standaloneStyles'] as $row) {
    printf(
        "%s\n  Product: %s\n  ProductPublicId: %s\n  StyleExists: %s\n  Reason: %s\n",
        $row['styleCode'],
        $row['productName'] ?? 'none',
        $row['productPublicId'] ?? 'none',
        !empty($row['styleExists']) ? 'YES' : 'NO',
        $row['reason'] ?? '',
    );
}

echo "\n============================================================\n";
echo "13. FULL PROPOSED STYLE TREE (NO SUMMARIES)\n";
echo "============================================================\n";
$printTree = static function (array $nodes, string $indent = '') use (&$printTree): void {
    $n = count($nodes);
    foreach ($nodes as $i => $node) {
        $branch = $indent === '' ? '' : (($i === $n - 1) ? '└── ' : '├── ');
        $tags = [];
        $tags[] = $node['decision'] ?? $node['role'] ?? '';
        if (!empty($node['productMapped'])) {
            $tags[] = 'Product mapped';
        }
        if (empty($node['styleExists'])) {
            $tags[] = 'STYLE MISSING';
        }
        if (($node['applicationState'] ?? '') === 'ALREADY_PERSISTED') {
            $tags[] = 'PERSISTED';
        } else {
            $tags[] = 'NOT APPLIED';
        }
        printf("%s%s%s  [%s]\n", $indent, $branch, $node['styleCode'], implode('] [', $tags));
        $childIndent = $indent === ''
            ? (($i === $n - 1) ? '    ' : '│   ')
            : $indent . (($i === $n - 1) ? '    ' : '│   ');
        $printTree($node['children'] ?? [], $childIndent);
    }
};
$printTree($report['proposedTree'] ?? []);

echo "\n============================================================\n";
echo "14. EXACT APPROVAL TABLE\n";
echo "============================================================\n";
printf("%-6s %-8s %-55s %-8s %-5s %-8s %-20s %-10s %-28s\n", 'Parent', 'Child', 'Product', 'Code', 'Exists', 'CurPar', 'Decision', 'Conf', 'ApplyAction');
foreach ($approvalTable as $row) {
    printf(
        "%-6s %-8s %-55s %-8s %-5s %-8s %-20s %-10s %-28s\n",
        $row['parent'],
        $row['child'],
        substr((string) ($row['product'] ?? ''), 0, 55),
        (string) ($row['productCode'] ?? ''),
        $row['productStyleExists'],
        (string) ($row['currentParent'] ?? 'none'),
        $row['decision'],
        $row['confidence'],
        $row['applyAction'],
    );
}

echo "\n============================================================\n";
echo "15. SEPARATE PACKAGES\n";
echo "============================================================\n";
echo "\nA. ALREADY_PERSISTED_CONFIRMED (" . count($alreadyPersisted) . ")\n";
foreach ($alreadyPersisted as $rel) {
    printf("%s → %s\n", $rel['parentStyleCode'], $rel['childStyleCode']);
}
echo "\nB. OPERATOR_CONFIRMED_REQUIRING_APPLY (" . count($confirmedApply) . ")\n";
foreach ($confirmedApply as $rel) {
    printf("%s → %s  (Product=%s StyleExists=%s CurrentAssign=%s)\n", $rel['parentStyleCode'], $rel['childStyleCode'], $rel['productName'] ?? '', !empty($rel['childExists']) ? 'YES' : 'NO', $rel['currentAssignment'] ?? 'none');
}
echo "\nC. ADDITIONAL_APPROVE_CANDIDATES (" . count($approveCandidates) . ")\n";
foreach ($approveCandidates as $rel) {
    printf("%s → %s\n", $rel['parentStyleCode'], $rel['childStyleCode']);
}
echo "\nD. REVIEW (" . count($reviewRels) . ")\n";
foreach ($reviewRels as $rel) {
    printf("%s → %s  (%s)\n", $rel['parentStyleCode'], $rel['childStyleCode'], $rel['reason'] ?? $rel['decision']);
}
echo "\nE. REJECT (" . count($rejectRels) . ")\n";
if ($rejectRels === []) {
    echo "(none)\n";
}
foreach ($rejectRels as $rel) {
    printf("%s → %s\n", $rel['parentStyleCode'], $rel['childStyleCode']);
}
echo "\nF. STANDALONE (" . count($report['standaloneStyles']) . ")\n";
foreach ($report['standaloneStyles'] as $row) {
    echo $row['styleCode'] . "\n";
}

echo "\n============================================================\n";
echo "16. MISSING STYLE CREATION PACKAGE (ALL " . count($report['missingChildStyleCandidates']) . ")\n";
echo "============================================================\n";
foreach ($report['missingChildStyleCandidates'] as $row) {
    printf(
        "%s\n  Product: %s\n  ProductPublicId: %s\n  Proposed Parent: %s\n  Relationship Decision: %s\n",
        $row['productCode'] ?? $row['proposedProductStyle'],
        $row['productName'] ?? 'none',
        $row['productPublicId'] ?? 'none',
        $row['proposedParent'] ?? 'NULL',
        $row['relationshipDecision'] ?? '',
    );
}

echo "\n============================================================\n";
echo "17. PRODUCT ASSIGNMENT PACKAGE (ALL " . count($productActions) . ")\n";
echo "============================================================\n";
foreach ($productActions as $row) {
    printf(
        "%s\n  ProductName: %s\n  Product.Code: %s\n  Exact ProductStyle: %s\n  Parent ProductStyle: %s\n  Current Assignment: %s\n  Future Required Action: %s\n",
        $row['productPublicId'],
        $row['productName'],
        $row['productCode'],
        $row['exactProductStyle'],
        $row['parentProductStyle'] ?? 'NULL',
        $row['currentAssignment'] ?? 'none',
        $row['futureRequiredAction'],
    );
}

echo "\n============================================================\n";
echo "18. APPLY IMPACT PREVIEW (NOT APPLIED)\n";
echo "============================================================\n";
foreach ($impact as $k => $v) {
    printf("%s: %s\n", $k, (string) $v);
}

echo "\n============================================================\n";
echo "19. CYCLE VALIDATION\n";
echo "============================================================\n";
printf("Self cycles: %d\n", (int) ($cycle['selfCycles'] ?? 0));
printf("Direct cycles: %d\n", (int) ($cycle['directCycles'] ?? 0));
printf("Deep cycles: %d\n", (int) ($cycle['deepCycles'] ?? 0));

echo "\n============================================================\n";
echo "20. SAFETY\n";
echo "============================================================\n";
foreach ($report['writeGuard'] as $k => $v) {
    if (is_bool($v)) {
        printf("%s: %s\n", $k, $v ? 'true' : 'false');
    } else {
        printf("%s: %s\n", $k, (string) $v);
    }
}

echo "\n============================================================\n";
echo "21. FINAL APPROVAL BLOCK\n";
echo "============================================================\n";
echo "TRICAB STYLE FAMILY FINAL OPERATOR REVIEW\n\n";
printf("ALREADY_PERSISTED_CONFIRMED:\n%d\n\n", count($alreadyPersisted));
printf("OPERATOR_CONFIRMED_REQUIRING_APPLY:\n%d\n\n", count($confirmedApply));
printf("ADDITIONAL_APPROVE_CANDIDATES:\n%d\n\n", count($approveCandidates));
printf("REVIEW:\n%d\n\n", count($reviewRels));
printf("REJECT:\n%d\n\n", count($rejectRels));
printf("STANDALONE:\n%d\n\n", count($report['standaloneStyles']));
printf("MISSING_CHILD_STYLES:\n%d\n\n", count($report['missingChildStyleCandidates']));
printf("PRODUCT_ASSIGNMENTS_REQUIRED:\n%d\n\n", $assignmentsRequired);
printf("ASSIGNMENT_REPLACEMENTS:\n%d\n\n", count($replacements));
printf("CYCLE_CONFLICTS:\n%d\n\n", (int) ($cycle['selfCycles'] ?? 0) + (int) ($cycle['directCycles'] ?? 0) + (int) ($cycle['deepCycles'] ?? 0));

echo "EXACT RELATIONSHIPS REQUIRING OPERATOR APPROVAL\n\n";
foreach (array_merge($confirmedApply, $approveCandidates) as $rel) {
    printf("%s → %s\n", $rel['parentStyleCode'], $rel['childStyleCode']);
}

echo "\nEXACT STYLES TO CREATE\n\n";
foreach ($report['missingChildStyleCandidates'] as $row) {
    echo ($row['productCode'] ?? $row['proposedProductStyle']) . "\n";
}

echo "\nEXACT ASSIGNMENT REPLACEMENTS\n\n";
foreach ($replacements as $map) {
    printf(
        "%s Product:\n%s → %s\nProductPublicId: %s\nProductName: %s\nParent: %s\n\n",
        $map['productCode'],
        $map['currentAssignment'] ?? 'none',
        $map['expectedAssignment'],
        $map['productPublicId'],
        $map['productName'],
        $map['proposedParentStyle'] ?? 'NULL',
    );
}
if ($replacements === []) {
    echo "(none)\n";
}

printf("\nWrote %s/style-family-operator-review.json\n", $outDir);
echo "PRODUCTSTYLE_WRITES: 0\n";
echo "PARENT_RELATIONSHIP_WRITES: 0\n";
echo "PRODUCTSTYLE_ASSIGNMENT_WRITES: 0\n";
echo "READY_FOR_STYLE_FAMILY_APPLY: NO\n";
echo "PRODUCTION_READY: NO\n";
