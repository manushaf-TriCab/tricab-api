<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only hierarchy evidence review.
 * Does not write ParentStyleId, assignments, specs, media, WP, tc, or blob.
 *
 * Usage (from tricab-api):
 *   php bin/style-hierarchy-evidence-review.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\StyleHierarchyEvidenceRow;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Application\Service\ReviewLegacyStyleHierarchyEvidenceService;

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
$beforeParents = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL');
$beforeAssignments = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]');
$beforeSpecs = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]');
$beforeMedia = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]');

/** @var ReviewLegacyStyleHierarchyEvidenceService $service */
$service = $container->get(ReviewLegacyStyleHierarchyEvidenceService::class);
$review = $service->review();

$afterParents = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL');
$afterAssignments = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]');
$afterSpecs = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]');
$afterMedia = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]');

$c = $review['counters'];
printf("\nOriginal HIGH=%d MEDIUM=%d REVIEW=%d investigated=%d\n", $c['originalHigh'], $c['originalMedium'], $c['originalReview'], $c['relationshipsInvestigated']);
printf("APPROVE_CANDIDATE=%d REVIEW=%d REJECT=%d EXCLUDED=%d ALTERNATIVE=%d ALREADY_PERSISTED=%d selected=%d applied=%d\n",
    $c['approveCandidate'], $c['review'], $c['reject'], $c['excludedReview'], $c['alternativeParent'], $c['alreadyPersisted'], $c['selectedRelationships'], $c['relationshipsApplied']);
printf("Reads WP=%d tc=%d fs=%d\n", $review['sourceReads']['wordpressReads'], $review['sourceReads']['tcReads'], $review['sourceReads']['filesystemReads']);
printf("Delta parents=%d assignments=%d specs=%d media=%d\n",
    $afterParents - $beforeParents, $afterAssignments - $beforeAssignments, $afterSpecs - $beforeSpecs, $afterMedia - $beforeMedia);

echo "\nSELECTED HIERARCHY APPROVAL SET\n";
$byParent = [];
foreach ($review['selected'] as $row) {
    if ($row instanceof StyleHierarchyEvidenceRow) {
        $byParent[$row->parentStyleCode][] = $row;
    }
}
foreach ($byParent as $parent => $children) {
    echo $parent . "\n";
    foreach ($children as $row) {
        printf("├── %s       %s\n", $row->childStyleCode, $row->reviewedConfidence);
    }
}
printf("Total selected relationships: %d\n", count($review['selected']));

echo "\nNEEDS OPERATOR REVIEW\n";
foreach ($review['rows'] as $row) {
    if (!$row instanceof StyleHierarchyEvidenceRow) {
        continue;
    }
    if (!in_array($row->decision, ['REVIEW', 'ALTERNATIVE_PARENT'], true)) {
        continue;
    }
    printf("%s → %s  %s  %s\n", $row->parentStyleCode, $row->childStyleCode, $row->reviewedConfidence, $row->reason);
}

echo "\nREJECTED\n";
foreach ($review['rows'] as $row) {
    if ($row instanceof StyleHierarchyEvidenceRow && $row->decision === 'REJECT') {
        printf("%s → %s  %s\n", $row->parentStyleCode, $row->childStyleCode, $row->reason);
    }
}

echo "\nEXCLUDED\n";
foreach ($review['rows'] as $row) {
    if ($row instanceof StyleHierarchyEvidenceRow && $row->decision === 'EXCLUDED_REVIEW') {
        printf("%s → %s  %s\n", $row->parentStyleCode, $row->childStyleCode, $row->reason);
    }
}

echo "\nEVIDENCE TABLE (HIGH/MEDIUM originals)\n";
foreach ($review['rows'] as $row) {
    if (!$row instanceof StyleHierarchyEvidenceRow) {
        continue;
    }
    if (!in_array($row->originalConfidence, ['HIGH', 'MEDIUM'], true)) {
        continue;
    }
    printf("%s → %s orig=%s rev=%s parentN=%d childN=%d class=%s desc=%s spec=%s media=%s conflict=%s decision=%s\n",
        $row->parentStyleCode,
        $row->childStyleCode,
        $row->originalConfidence,
        $row->reviewedConfidence,
        $row->parentProductCount,
        $row->childProductCount,
        $row->classificationEvidence,
        $row->descriptionEvidence,
        $row->specificationEvidence,
        $row->mediaEvidence,
        $row->conflict ?? 'none',
        $row->decision,
    );
}

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$encoded = json_encode([
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'isProduction' => false,
    ],
    'before' => [
        'parents' => $beforeParents,
        'assignments' => $beforeAssignments,
        'specs' => $beforeSpecs,
        'media' => $beforeMedia,
    ],
    'after' => [
        'parents' => $afterParents,
        'assignments' => $afterAssignments,
        'specs' => $afterSpecs,
        'media' => $afterMedia,
    ],
    'counters' => $c,
    'sourceReads' => $review['sourceReads'],
    'writeGuard' => $review['writeGuard'],
    'rows' => array_map(static fn ($r) => $r instanceof StyleHierarchyEvidenceRow ? $r->toArray() : $r, $review['rows']),
    'selected' => array_map(static fn ($r) => $r instanceof StyleHierarchyEvidenceRow ? $r->toArray() : $r, $review['selected']),
    'groups' => $review['groups'],
    'membership' => array_map(
        static fn ($m) => $m instanceof StyleMembershipEvidence ? $m->toArray() : $m,
        $review['membership'] ?? [],
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($outDir . '/style-hierarchy-evidence-review.json', $encoded);
printf("\nWrote %s/style-hierarchy-evidence-review.json\n", $outDir);

if ($afterParents !== $beforeParents || $afterAssignments !== $beforeAssignments) {
    fwrite(STDERR, "Unexpected writes detected.\n");
    exit(3);
}
echo "STYLE_HIERARCHY_APPLIED: NO\n";
