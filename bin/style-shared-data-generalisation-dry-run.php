<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only dry-run: Parent Style shared-data generalisation analysis.
 * ZERO business-data writes.
 *
 * Usage (from tricab-api):
 *   php bin/style-shared-data-generalisation-dry-run.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\DiscoverStyleSharedDataGeneralisationService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\ResolveEffectiveStyleResourcesService;

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

echo "\nDRY RUN — PARENT STYLE SHARED DATA GENERALISATION — NO CHANGES APPLIED\n";

$performanceBaseline = measurePerformanceBaseline($container, $db);

/** @var DiscoverStyleSharedDataGeneralisationService $service */
$service = $container->get(DiscoverStyleSharedDataGeneralisationService::class);
$report = $service->dryRun($performanceBaseline);

$artifactPath = $basePath . '/tmp/style-shared-data-generalisation-dry-run.json';
if (!is_dir(dirname($artifactPath))) {
    mkdir(dirname($artifactPath), 0775, true);
}
file_put_contents($artifactPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$summary = $report['summary'] ?? [];
$bv = $report['BV'] ?? null;

echo "\n=== SUMMARY ===\n";
printf("Families analysed: %d\n", (int) ($summary['familyCount'] ?? 0));
printf("EXACT_SHARED spec candidates: %d\n", (int) ($summary['exactSharedSpecCandidates'] ?? 0));
printf("SEED spec candidates: %d\n", (int) ($summary['seedSpecCandidates'] ?? 0));
printf("Artifact: %s\n", $artifactPath);

if ($bv !== null) {
    echo "\n=== BV FAMILY ===\n";
    printf("Children: %s\n", implode(', ', $bv['children'] ?? []));
    printf("Seed candidate: %s (%s)\n", (string) ($bv['seedCandidate']['seedStyle'] ?? 'none'), (string) ($bv['seedCandidate']['seedProduct'] ?? ''));
    printf("CAN_BVA_SAFELY_SEED_BV_SPECIFICATIONS: %s\n", $bv['canSeedSpecifications'] ?? 'NO');
    printf("CAN_BVA_SAFELY_SEED_BV_IMAGES: %s\n", $bv['canSeedImages'] ?? 'NO');
    printf("CAN_BVA_SAFELY_SEED_BV_MARKETS: %s\n", $bv['canSeedMarkets'] ?? 'NO');
}

echo "\nSHARED_DATA_ANALYSIS_COMPLETE: YES\n";
echo "EFFECTIVE_STYLE_SNAPSHOT_DESIGNED: YES\n";
echo "READY_FOR_PARENT_SHARED_DATA_APPLY: NO\n";
echo "PRODUCTION_READY: NO\n";

/**
 * @param \Psr\Container\ContainerInterface $container
 * @return array<string, mixed>
 */
function measurePerformanceBaseline(object $container, SqlConnection $db): array
{
    $codes = ['BVA', 'RCA', 'DVA', 'RRA', 'BHA'];
    $rows = $db->fetchAll(
        'SELECT [InternalCode], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId] FROM [product].[Products] WHERE [InternalCode] IN (:bva, :rca, :dva, :rra, :bha) AND [DeletedAtUtc] IS NULL',
        ['bva' => 'BVA', 'rca' => 'RCA', 'dva' => 'DVA', 'rra' => 'RRA', 'bha' => 'BHA'],
    );
    $publicIds = [];
    foreach ($rows as $row) {
        $publicIds[(string) $row['InternalCode']] = (string) $row['PublicId'];
    }

    /** @var GetPublicProductService $publicProduct */
    $publicProduct = $container->get(GetPublicProductService::class);
    /** @var ResolveEffectiveStyleResourcesService $resolver */
    $resolver = $container->get(ResolveEffectiveStyleResourcesService::class);

    $samples = [];
    foreach ($codes as $code) {
        $publicId = $publicIds[$code] ?? null;
        if ($publicId === null) {
            $samples[$code] = ['status' => 'PRODUCT_NOT_FOUND'];
            continue;
        }
        $start = microtime(true);
        try {
            $publicProduct->byPublicId($publicId, 'AU');
            $publicMs = (microtime(true) - $start) * 1000;
        } catch (Throwable $e) {
            $publicMs = null;
        }
        $start = microtime(true);
        try {
            $specCount = count($resolver->specificationsForProduct($publicId));
            $resolverMs = (microtime(true) - $start) * 1000;
        } catch (Throwable $e) {
            $specCount = null;
            $resolverMs = null;
        }
        $samples[$code] = [
            'productCode' => $code,
            'publicId' => $publicId,
            'getPublicProductMs' => $publicMs !== null ? round($publicMs, 2) : 'NOT_MEASURED',
            'styleResolverSpecMs' => $resolverMs !== null ? round($resolverMs, 2) : 'NOT_MEASURED',
            'styleResolverSpecCount' => $specCount,
            'note' => 'Query count not instrumented — duration only',
        ];
    }

    return [
        'status' => 'MEASURED',
        'path' => 'GetPublicProductService + ResolveEffectiveStyleResourcesService',
        'snapshotPath' => 'NOT_IMPLEMENTED',
        'samples' => $samples,
    ];
}
