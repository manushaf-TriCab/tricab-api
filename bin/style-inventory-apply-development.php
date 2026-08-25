<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: create VALID + MISSING_TARGET ProductStyles as LOCAL roots.
 *
 * Does not write WordPress, tc MySQL, filesystem, blob, assignments, hierarchy,
 * specifications, or media. Does not apply parent relationships.
 *
 * Usage (from tricab-api):
 *   php bin/style-inventory-apply-development.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal;
use TriCab\Core\Product\Application\Service\CreateMissingProductStylesFromInventoryService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleInventoryService;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

set_time_limit(0);

$settings = Environment::settings($basePath);
$expectedHost = 'tricab-mel.database.windows.net';
$expectedDatabase = 'tc';
$expectedServer = 'tricab-mel';

printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping. PRODUCTION WRITES = 0\n");
    exit(2);
}
if (!$settings->isDevelopment() || !$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Development Azure SQL is required. Stopping.\n");
    exit(2);
}
if (strcasecmp($settings->dbHost, $expectedHost) !== 0 || strcasecmp($settings->dbDatabase, $expectedDatabase) !== 0) {
    fwrite(STDERR, "DB_HOST/DB_DATABASE are not the approved development target. Stopping.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll(
    'SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]',
);
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
$loginName = (string) ($identity[0]['LoginName'] ?? '');
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL SUSER_SNAME()=%s\n", $loginName);

if (strcasecmp($databaseName, $expectedDatabase) !== 0 || strcasecmp($databaseName, $settings->dbDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}
if (strcasecmp($serverName, $expectedServer) !== 0) {
    fwrite(STDERR, "SQL @@SERVERNAME is not tricab-mel. Stopping.\n");
    exit(2);
}
if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false || $settings->isProduction()) {
    fwrite(STDERR, "Server/database looks like production. Stopping.\n");
    exit(2);
}

$countSql = static function (SqlConnection $db, string $sql): int {
    $rows = $db->fetchAll($sql);

    return (int) ($rows[0]['cnt'] ?? 0);
};

$safeCount = static function (SqlConnection $db, callable $countSql, string $sql): int {
    try {
        return $countSql($db, $sql);
    } catch (\Throwable) {
        return -1;
    }
};

$snapshot = static function (SqlConnection $db, callable $countSql, callable $safeCount): array {
    return [
        'styles' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
        'parents' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
        'roots' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NULL'),
        'assignments' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
        'specs' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
        'media' => $countSql($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
        'families' => $safeCount($db, $countSql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductFamilies] WHERE [DeletedAtUtc] IS NULL'),
        'categories' => $safeCount($db, $countSql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductCategories] WHERE [DeletedAtUtc] IS NULL'),
        'series' => $safeCount($db, $countSql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductSeries] WHERE [DeletedAtUtc] IS NULL'),
        'catalogueNodes' => $safeCount($db, $countSql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes]'),
        'auditEvents' => $safeCount($db, $countSql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductAuditEvents]'),
    ];
};

$before = $snapshot($db, $countSql, $safeCount);
printf(
    "Pre-apply ProductStyles=%d roots=%d parents=%d assignments=%d specs=%d media=%d families=%d categories=%d series=%d catalogueNodes=%d audit=%d\n",
    $before['styles'],
    $before['roots'],
    $before['parents'],
    $before['assignments'],
    $before['specs'],
    $before['media'],
    $before['families'],
    $before['categories'],
    $before['series'],
    $before['catalogueNodes'],
    $before['auditEvents'],
);

$userRows = $db->fetchAll(
    'SELECT TOP (1) [UserId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [EmailAddress] AS [Email] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
    [':email' => 'thilanh@tricab.com'],
);
if ($userRows === []) {
    fwrite(STDERR, "Could not resolve thilanh@tricab.com.\n");
    exit(1);
}
$actor = new ActorContext(
    userId: (int) $userRows[0]['UserId'],
    publicId: (string) $userRows[0]['PublicId'],
    emailAddress: (string) $userRows[0]['Email'],
);

/** @var DiscoverLegacyStyleInventoryService $inventory */
$inventory = $container->get(DiscoverLegacyStyleInventoryService::class);
$report = $inventory->discover();
$counters = $report->counters;
printf(
    "\nPre-apply inventory canonical=%d valid=%d review=%d invalid=%d matched=%d missing=%d targetOnly=%d ready=%s\n",
    (int) ($counters['canonicalDistinctStyles'] ?? 0),
    (int) ($counters['valid'] ?? 0),
    (int) ($counters['review'] ?? 0),
    (int) ($counters['invalid'] ?? 0),
    (int) ($counters['matchedTargetStyles'] ?? 0),
    (int) ($counters['missingTargetStyles'] ?? 0),
    (int) ($counters['targetOnlyStyles'] ?? 0),
    $report->readyToCreate ? 'YES' : 'NO',
);

if (!$report->readyToCreate) {
    fwrite(STDERR, "STYLE_INVENTORY_READY_TO_CREATE=NO. Stopping.\n");
    exit(2);
}

$approved = [];
$approvedDisplay = [];
$reviewCodes = [];
foreach ($report->items as $item) {
    if ($item->status === 'REVIEW' || $item->targetMatch === 'REVIEW') {
        $reviewCodes[] = (string) ($item->normalizedStyleCode ?? $item->rawStyleCode);
    }
    if ($item->status === 'VALID' && $item->targetMatch === 'MISSING_TARGET' && $item->normalizedStyleCode !== null) {
        $approved[] = $item->normalizedStyleCode;
        $approvedDisplay[$item->normalizedStyleCode] = $item->displayNameSource;
    }
}
$approvedSet = array_fill_keys($approved, true);
printf("Approved VALID_MISSING_TARGET candidates=%d\n", count($approved));
printf("REVIEW exclusions=%d (not created)\n", count($reviewCodes));

/** @var CreateMissingProductStylesFromInventoryService $apply */
$apply = $container->get(CreateMissingProductStylesFromInventoryService::class);
$seen = 0;
echo "Creating approved missing styles...\n";
$applied = $apply->createApprovedMissing(
    $actor,
    $report,
    true,
    true,
    static function (array $event) use (&$seen): void {
        ++$seen;
        if ($event['result'] === 'CREATED' || $seen % 50 === 0) {
            printf("  [%d] %s %s\n", $seen, $event['result'], $event['styleCode']);
        }
    },
);

printf(
    "\nApply created=%d already=%d review=%d invalid=%d targetOnly=%d failed=%d conflicts=%d attempts=%d\n",
    $applied['counters']['created'],
    $applied['counters']['alreadyExisted'],
    $applied['counters']['skippedReview'],
    $applied['counters']['skippedInvalid'],
    $applied['counters']['skippedTargetOnly'],
    $applied['counters']['failed'],
    $applied['counters']['conflicts'],
    $applied['counters']['createAttempts'],
);

$approvedCreated = 0;
$approvedAlready = 0;
$approvedFailed = 0;
$approvedConflicts = 0;
foreach ($applied['results'] as $row) {
    $code = (string) ($row['styleCode'] ?? '');
    if (!isset($approvedSet[$code])) {
        continue;
    }
    match ((string) ($row['result'] ?? '')) {
        'CREATED' => ++$approvedCreated,
        'SKIPPED_ALREADY_EXISTS' => ++$approvedAlready,
        'FAILED' => ++$approvedFailed,
        'CONFLICT' => ++$approvedConflicts,
        default => null,
    };
}

$after = $snapshot($db, $countSql, $safeCount);
$allStyles = $db->fetchAll(
    <<<'SQL'
SELECT
    [StyleCode], [DisplayName], [ParentStyleId],
    [SpecificationsInheritanceMode], [ImagesInheritanceMode], [DocumentsInheritanceMode],
    [Spin360InheritanceMode], [Models3DInheritanceMode], [ArAssetsInheritanceMode],
    [IsActive], [DeletedAtUtc]
FROM [product].[ProductStyles]
WHERE [DeletedAtUtc] IS NULL
SQL
);

$missingAfter = [];
$presentAfter = 0;
$importedWithParent = 0;
$importedNonLocal = 0;
$importedInactive = 0;
$displayFallback = 0;
$displayDerived = 0;
foreach ($approved as $code) {
    $source = $approvedDisplay[$code] ?? 'FALLBACK_STYLE_CODE';
    if ($source === 'SOURCE_DERIVED') {
        ++$displayDerived;
    } else {
        ++$displayFallback;
    }
}
$byCode = [];
foreach ($allStyles as $row) {
    $byCode[(string) ($row['StyleCode'] ?? '')] = $row;
}
foreach ($approved as $code) {
    if (!isset($byCode[$code])) {
        $missingAfter[] = $code;
        continue;
    }
    ++$presentAfter;
    $row = $byCode[$code];
    if ($row['ParentStyleId'] !== null) {
        ++$importedWithParent;
    }
    foreach ([
        'SpecificationsInheritanceMode',
        'ImagesInheritanceMode',
        'DocumentsInheritanceMode',
        'Spin360InheritanceMode',
        'Models3DInheritanceMode',
        'ArAssetsInheritanceMode',
    ] as $col) {
        if (strcasecmp((string) ($row[$col] ?? ''), 'LOCAL') !== 0) {
            ++$importedNonLocal;
            break;
        }
    }
    if (!(bool) $row['IsActive'] || $row['DeletedAtUtc'] !== null) {
        ++$importedInactive;
    }
}

$numericCreated = 0;
foreach (['1', '10', '11', '20'] as $numeric) {
    if (isset($byCode[$numeric])) {
        ++$numericCreated;
    }
}

$sampleCodes = ['F12', 'J01', 'A01', 'SD', 'PS', 'B1', 'B10', 'CABLE_STYLE'];
$samples = [];
foreach ($sampleCodes as $code) {
    if (isset($byCode[$code])) {
        $samples[] = $byCode[$code];
    }
}

$smoke = $db->fetchAll(
    <<<'SQL'
SELECT
    s.[StyleCode],
    parent.[StyleCode] AS [ParentStyleCode],
    s.[ParentStyleId],
    (SELECT COUNT(*) FROM [product].[ProductStyleAssignments] a WHERE a.[ProductStyleId] = s.[ProductStyleId]) AS [Assignments],
    (SELECT COUNT(*) FROM [product].[ProductStyles] c WHERE c.[ParentStyleId] = s.[ProductStyleId] AND c.[DeletedAtUtc] IS NULL) AS [Children],
    (SELECT COUNT(*) FROM [product].[ProductStyleSpecificationValues] v WHERE v.[ProductStyleId] = s.[ProductStyleId] AND v.[DeletedAtUtc] IS NULL) AS [SpecRows],
    (SELECT COUNT(*) FROM [product].[ProductStyleMediaAssets] m WHERE m.[ProductStyleId] = s.[ProductStyleId] AND m.[DeletedAtUtc] IS NULL) AS [MediaRows]
FROM [product].[ProductStyles] s
LEFT JOIN [product].[ProductStyles] parent ON parent.[ProductStyleId] = s.[ParentStyleId]
WHERE s.[StyleCode] IN (N'BVA', N'BVC', N'BVM', N'BVP', N'BVA-X', N'BV')
  AND s.[DeletedAtUtc] IS NULL
ORDER BY s.[StyleCode]
SQL
);

$auditSample = $db->fetchAll(
    <<<'SQL'
SELECT TOP (5)
    [ActionCode], [SourceCode], [EntityTypeCode], [NewValuesJson],
    CONVERT(VARCHAR(33), [CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [product].[ProductAuditEvents]
WHERE [ActionCode] IN (N'created', N'legacy-style-inventory-created')
  AND [EntityTypeCode] = N'product-style'
ORDER BY [ProductAuditEventId] DESC
SQL
);

printf(
    "\nPost-apply ProductStyles=%d roots=%d parents=%d approvedPresent=%d approvedMissing=%d numericSampleCreated=%d\n",
    $after['styles'],
    $after['roots'],
    $after['parents'],
    $presentAfter,
    count($missingAfter),
    $numericCreated,
);
printf(
    "Approved invariant created=%d already=%d failed=%d conflicts=%d importedWithParent=%d importedNonLocal=%d\n",
    $approvedCreated,
    $approvedAlready,
    $approvedFailed,
    $approvedConflicts,
    $importedWithParent,
    $importedNonLocal,
);
printf(
    "Delta styles=%d assignments=%d specs=%d media=%d parents=%d families=%d categories=%d series=%d catalogue=%d\n",
    $after['styles'] - $before['styles'],
    $after['assignments'] - $before['assignments'],
    $after['specs'] - $before['specs'],
    $after['media'] - $before['media'],
    $after['parents'] - $before['parents'],
    $after['families'] - $before['families'],
    $after['categories'] - $before['categories'],
    $after['series'] - $before['series'],
    $after['catalogueNodes'] - $before['catalogueNodes'],
);

echo "\nSample created/existing styles:\n";
foreach ($samples as $row) {
    printf(
        "  %s name=%s parent=%s spec=%s img=%s doc=%s 360=%s 3d=%s ar=%s active=%s deleted=%s\n",
        (string) ($row['StyleCode'] ?? ''),
        (string) ($row['DisplayName'] ?? ''),
        $row['ParentStyleId'] === null ? 'NULL' : (string) $row['ParentStyleId'],
        (string) ($row['SpecificationsInheritanceMode'] ?? ''),
        (string) ($row['ImagesInheritanceMode'] ?? ''),
        (string) ($row['DocumentsInheritanceMode'] ?? ''),
        (string) ($row['Spin360InheritanceMode'] ?? ''),
        (string) ($row['Models3DInheritanceMode'] ?? ''),
        (string) ($row['ArAssetsInheritanceMode'] ?? ''),
        (string) ($row['IsActive'] ?? ''),
        $row['DeletedAtUtc'] === null ? 'NULL' : (string) $row['DeletedAtUtc'],
    );
}

echo "\nTarget-only / smoke assessment:\n";
foreach ($smoke as $row) {
    printf(
        "  %s parent=%s assignments=%s children=%s specs=%s media=%s\n",
        (string) ($row['StyleCode'] ?? ''),
        (string) ($row['ParentStyleCode'] ?? 'NULL'),
        (string) ($row['Assignments'] ?? '0'),
        (string) ($row['Children'] ?? '0'),
        (string) ($row['SpecRows'] ?? '0'),
        (string) ($row['MediaRows'] ?? '0'),
    );
}

/** @var DiscoverLegacyStyleHierarchyService $discovery */
$discovery = $container->get(DiscoverLegacyStyleHierarchyService::class);
$preview = $discovery->preview();
$h = is_array($preview['counters'] ?? null) ? $preview['counters'] : [];
printf(
    "\nHierarchy preview HIGH=%d MEDIUM=%d LOW=%d REVIEW=%d standalone=%d ambiguous=%d cycles=%d applied=%d\n",
    (int) ($h['highConfidence'] ?? 0),
    (int) ($h['mediumConfidence'] ?? 0),
    (int) ($h['lowConfidence'] ?? 0),
    (int) ($h['review'] ?? 0),
    (int) ($h['standaloneStyles'] ?? 0),
    (int) ($h['ambiguousParentCases'] ?? 0),
    (int) ($h['cycleConflicts'] ?? 0),
    (int) ($h['relationshipsApplied'] ?? 0),
);

$high = [];
$medium = [];
$reviewProposals = [];
$numericProposals = [];
$zeroVsZero = 0;
foreach ($preview['proposals'] as $proposal) {
    if (!$proposal instanceof StyleDiscoveryProposal) {
        continue;
    }
    $row = [
        'parent' => $proposal->parentStyleCode,
        'child' => $proposal->childStyleCode,
        'confidence' => $proposal->confidence,
        'applicationState' => $proposal->applicationState,
        'specEvidence' => $proposal->sharedSpecificationEvidence,
        'mediaEvidence' => $proposal->sharedMediaEvidence,
        'persistedParent' => $proposal->persistedParentStyleCode,
        'ambiguous' => $proposal->ambiguousParent,
    ];
    if ($proposal->sharedSpecificationEvidence === null && $proposal->sharedMediaEvidence === null) {
        ++$zeroVsZero;
    }
    if (preg_match('/^\d+$/', $proposal->parentStyleCode) === 1 || preg_match('/^\d+$/', $proposal->childStyleCode) === 1) {
        $numericProposals[] = $row;
    }
    match ($proposal->confidence) {
        'HIGH' => $high[] = $row,
        'MEDIUM' => $medium[] = $row,
        default => $reviewProposals[] = $row,
    };
}

echo "HIGH-confidence proposals (evidence only, NOT applied):\n";
foreach ($high as $row) {
    printf("  %s → %s state=%s spec=%s media=%s\n", $row['parent'], $row['child'], $row['applicationState'], $row['specEvidence'] === null ? 'NO_EVIDENCE' : (string) $row['specEvidence'], $row['mediaEvidence'] === null ? 'NO_EVIDENCE' : (string) $row['mediaEvidence']);
}

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$path = $outDir . '/style-inventory-apply-development.json';
file_put_contents($path, json_encode([
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'SQL_SUSER' => $loginName,
        'isProduction' => false,
        'blobWrites' => 0,
        'legacyWordpressWrites' => 0,
        'legacyTcMysqlWrites' => 0,
        'legacyFilesystemWrites' => 0,
        'productionWrites' => 0,
        'forbiddenWriteAttempts' => 0,
    ],
    'before' => $before,
    'preApplyCounters' => $counters,
    'approvedCandidates' => $approved,
    'reviewCodes' => $reviewCodes,
    'appliedCounters' => $applied['counters'],
    'appliedResults' => $applied['results'],
    'approvedCreated' => $approvedCreated,
    'approvedAlready' => $approvedAlready,
    'approvedFailed' => $approvedFailed,
    'approvedConflicts' => $approvedConflicts,
    'after' => $after,
    'approvedPresent' => $presentAfter,
    'approvedMissing' => $missingAfter,
    'importedWithParent' => $importedWithParent,
    'importedNonLocal' => $importedNonLocal,
    'importedInactive' => $importedInactive,
    'displayNameFallback' => $displayFallback,
    'displayNameSourceDerived' => $displayDerived,
    'numericSampleCreated' => $numericCreated,
    'samples' => $samples,
    'smoke' => $smoke,
    'auditSample' => $auditSample,
    'hierarchyCounters' => $h,
    'hierarchyWriteGuard' => $preview['writeGuard'] ?? [],
    'highProposals' => $high,
    'mediumProposals' => $medium,
    'reviewProposalCount' => count($reviewProposals),
    'reviewProposals' => $reviewProposals,
    'numericProposals' => $numericProposals,
    'zeroVsZeroEvidenceCount' => $zeroVsZero,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\nWrote %s\n", $path);

$invariantOk = (count($approved) === ($approvedCreated + $approvedAlready + $approvedFailed + $approvedConflicts));
$classificationChanged = ($after['families'] >= 0 && $before['families'] >= 0 && $after['families'] !== $before['families'])
    || ($after['categories'] >= 0 && $before['categories'] >= 0 && $after['categories'] !== $before['categories'])
    || ($after['series'] >= 0 && $before['series'] >= 0 && $after['series'] !== $before['series'])
    || ($after['catalogueNodes'] >= 0 && $before['catalogueNodes'] >= 0 && $after['catalogueNodes'] !== $before['catalogueNodes']);
if (
    $missingAfter !== []
    || $applied['counters']['failed'] > 0
    || $applied['counters']['conflicts'] > 0
    || $importedWithParent > 0
    || $importedNonLocal > 0
    || $numericCreated > 0
    || ($after['parents'] - $before['parents']) !== 0
    || ($after['assignments'] - $before['assignments']) !== 0
    || ($after['specs'] - $before['specs']) !== 0
    || ($after['media'] - $before['media']) !== 0
    || $classificationChanged
    || !$invariantOk
) {
    fwrite(STDERR, "Reconciliation incomplete or safety invariant failed. See JSON report.\n");
    exit(3);
}

echo "STYLE_INVENTORY_RECONCILED: YES\n";
