<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: apply operator-approved CatalogueNode → ProductStyle links.
 *
 * Applies additive schema (if needed) and exactly 17 approved links from
 * tmp/catalogue-product-style-mapping-dry-run.json using PublicIds only.
 *
 * Usage (from tricab-api):
 *   php bin/catalogue-product-style-apply-development.php
 *   php bin/catalogue-product-style-apply-development.php --dry-revalidate
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Service\ApplyApprovedCatalogueNodeProductStylesService;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

set_time_limit(0);

$dryRevalidate = in_array('--dry-revalidate', $argv ?? [], true);
$packagePath = $basePath . '/tmp/catalogue-product-style-mapping-dry-run.json';
$schemaPath = $basePath . '/database/scripts/development/create-catalogue-node-product-styles.sql';
$expectedHost = 'tricab-mel.database.windows.net';
$expectedDatabase = 'tc';
$expectedServer = 'tricab-mel';
$expectedLinkCount = 17;

$settings = Environment::settings($basePath);
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
if (!is_file($packagePath)) {
    fwrite(STDERR, "Approved package missing: {$packagePath}\n");
    exit(2);
}
if (!is_file($schemaPath)) {
    fwrite(STDERR, "Schema script missing: {$schemaPath}\n");
    exit(2);
}

/** @var array<string, mixed> $package */
$package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
$approvedLinks = [];
foreach ($package['proposals'] ?? [] as $proposal) {
    if (($proposal['decision'] ?? '') !== 'EXACT_FAMILY_EVIDENCE') {
        continue;
    }
    $nodePublicId = (string) ($proposal['catalogueNodePublicId'] ?? '');
    $stylePublicId = (string) ($proposal['proposedParentStylePublicId'] ?? '');
    if ($nodePublicId === '' || $stylePublicId === '') {
        continue;
    }
    $approvedLinks[] = [
        'catalogueNodePublicId' => $nodePublicId,
        'productStylePublicId' => $stylePublicId,
        'styleCode' => (string) ($proposal['proposedParentStyleCode'] ?? ''),
        'cataloguePath' => (string) ($proposal['cataloguePath'] ?? ''),
        'nodeName' => (string) ($proposal['nodeName'] ?? ''),
    ];
}

if (count($approvedLinks) !== $expectedLinkCount) {
    fwrite(STDERR, sprintf(
        "Expected %d approved links in package, found %d. Stopping.\n",
        $expectedLinkCount,
        count($approvedLinks),
    ));
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

if (strcasecmp($databaseName, $expectedDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}
if (strcasecmp($serverName, $expectedServer) !== 0) {
    fwrite(STDERR, "SQL @@SERVERNAME is not tricab-mel. Stopping.\n");
    exit(2);
}

$tableExistsBefore = (int) ($db->fetchAll(
    "SELECT COUNT(*) AS cnt FROM sys.tables WHERE name = 'CatalogueNodeProductStyles' AND schema_id = SCHEMA_ID('product')",
)[0]['cnt'] ?? 0);

$schemaDdlWrites = 0;
if (!$dryRevalidate && $tableExistsBefore === 0) {
    $schemaSql = (string) file_get_contents($schemaPath);
    $db->execute($schemaSql);
    $schemaDdlWrites = 1;
}

$tableExistsAfter = (int) ($db->fetchAll(
    "SELECT COUNT(*) AS cnt FROM sys.tables WHERE name = 'CatalogueNodeProductStyles' AND schema_id = SCHEMA_ID('product')",
)[0]['cnt'] ?? 0);

/** @var ApplyApprovedCatalogueNodeProductStylesService $apply */
$apply = $container->get(ApplyApprovedCatalogueNodeProductStylesService::class);
/** @var CatalogueNavigationRepository $catalogue */
$catalogue = $container->get(CatalogueNavigationRepository::class);

$styleLinksBefore = (int) ($db->fetchAll(
    'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodeProductStyles] WHERE [IsActive] = 1',
)[0]['cnt'] ?? 0);

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
    correlationId: 'catalogue-product-style-apply-development',
);

$result = $dryRevalidate
    ? [
        'batchSource' => ApplyApprovedCatalogueNodeProductStylesService::BATCH_SOURCE,
        'links' => [],
        'reconciliation' => ['missingAfterApply' => 0],
        'counters' => ['requested' => $expectedLinkCount, 'created' => 0, 'alreadyExisted' => 0, 'failed' => 0, 'conflicts' => 0],
    ]
    : $apply->apply($actor, true, true, $approvedLinks);

if ($dryRevalidate) {
    $reconcile = [];
    foreach ($approvedLinks as $link) {
        $found = false;
        foreach ($catalogue->listNodeProductStyles($link['catalogueNodePublicId'], true) as $row) {
            if (strcasecmp($row->productStylePublicId, $link['productStylePublicId']) === 0) {
                $found = true;
                break;
            }
        }
        $reconcile[] = ['link' => $link, 'present' => $found];
    }
    $missing = array_values(array_filter($reconcile, static fn (array $r): bool => !$r['present']));
    $result['reconciliation'] = [
        'approvedLinks' => $expectedLinkCount,
        'verifiedPresent' => $expectedLinkCount - count($missing),
        'missingAfterApply' => count($missing),
        'missing' => array_map(static fn (array $r): array => $r['link'], $missing),
    ];
    $result['counters']['alreadyExisted'] = $expectedLinkCount - count($missing);
    $result['counters']['created'] = 0;
}

$styleLinksAfter = (int) ($db->fetchAll(
    'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodeProductStyles] WHERE [IsActive] = 1',
)[0]['cnt'] ?? 0);

$report = [
    'environment' => [
        'appEnv' => $settings->appEnv,
        'dbHost' => $settings->dbHost,
        'dbDatabase' => $settings->dbDatabase,
        'serverName' => $serverName,
        'loginName' => $loginName,
        'isProduction' => $settings->isProduction(),
    ],
    'schema' => [
        'tableExistsBefore' => $tableExistsBefore === 1,
        'tableExistsAfter' => $tableExistsAfter === 1,
        'schemaDdlWrites' => $schemaDdlWrites,
    ],
    'approvedLinks' => $approvedLinks,
    'apply' => $result,
    'counters' => [
        'schemaDdlWrites' => $schemaDdlWrites,
        'catalogueNodeProductStyleInserts' => $dryRevalidate ? 0 : ($result['counters']['created'] ?? 0),
        'catalogueNodeProductStyleUpdates' => 0,
        'catalogueNodeProductStyleDeletes' => 0,
        'styleLinksBefore' => $styleLinksBefore,
        'styleLinksAfter' => $styleLinksAfter,
    ],
    'dryRevalidate' => $dryRevalidate,
];

$reportPath = $basePath . '/tmp/catalogue-product-style-apply-report.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("\nAPPROVED_CATALOGUE_STYLE_LINKS: %d\n", $expectedLinkCount);
printf("CATALOGUE_STYLE_LINKS_CREATED: %d\n", (int) ($result['counters']['created'] ?? 0));
printf("CATALOGUE_STYLE_LINKS_ALREADY_EXISTED: %d\n", (int) ($result['counters']['alreadyExisted'] ?? 0));
printf("CATALOGUE_STYLE_LINKS_FAILED: %d\n", (int) ($result['counters']['failed'] ?? 0));
printf("MISSING_AFTER_APPLY: %d\n", (int) ($result['reconciliation']['missingAfterApply'] ?? 0));
printf("SCHEMA_DDL_WRITES: %d\n", $schemaDdlWrites);
printf("Report: %s\n", $reportPath);

if (($result['reconciliation']['missingAfterApply'] ?? 0) > 0 && !$dryRevalidate) {
    exit(1);
}

exit(0);
