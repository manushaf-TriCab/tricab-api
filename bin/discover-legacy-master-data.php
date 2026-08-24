<?php

declare(strict_types=1);

/**
 * Read-only pre-product-migration master data discovery.
 * Never writes to WordPress or tc. Does not create Azure master data.
 *
 * php bin/discover-legacy-master-data.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\DiscoverLegacyMasterDataService;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

$azure = [
    'families' => [],
    'categories' => [],
    'series' => [],
    'specificationDefinitions' => [],
    'specificationGroups' => [],
    'units' => [],
    'catalogueTabs' => [],
    'catalogueNodes' => [],
    'products' => [],
    'frontendCategories' => [
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
    ],
];

try {
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $query) use (&$azure): array {
        try {
            return $sql->fetchAll($query);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Azure query skipped: ' . $e->getMessage() . ($e->getPrevious() ? ' | ' . $e->getPrevious()->getMessage() : '') . PHP_EOL);

            return [];
        }
    };
    $azure['families'] = $safe($sql, 'SELECT FamilyCode AS code, FamilyName AS name FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $azure['categories'] = $safe($sql, 'SELECT CategoryCode AS code, CategoryName AS name FROM [product].[ProductCategories] WHERE DeletedAtUtc IS NULL');
    $azure['series'] = $safe($sql, 'SELECT SeriesCode AS code, SeriesName AS name FROM [product].[ProductSeries] WHERE DeletedAtUtc IS NULL');
    $azure['specificationDefinitions'] = $safe($sql, 'SELECT DefinitionCode AS code, DefinitionName AS name, DataTypeCode AS type, ScopeCode AS scope FROM [product].[SpecificationDefinitions] WHERE DeletedAtUtc IS NULL');
    $azure['specificationGroups'] = $safe($sql, 'SELECT GroupCode AS code, GroupName AS name FROM [product].[SpecificationGroups] WHERE DeletedAtUtc IS NULL');
    $azure['units'] = $safe($sql, 'SELECT UnitCode AS code, Symbol AS symbol, UnitName AS name FROM [product].[SpecificationUnits] WHERE DeletedAtUtc IS NULL');
    $azure['catalogueTabs'] = $safe($sql, 'SELECT TabCode AS code, TabName AS name FROM [product].[CatalogueTabs] WHERE DeletedAtUtc IS NULL');
    $azure['catalogueNodes'] = $safe($sql, 'SELECT NodeCode AS code, NodeName AS name, ImageUrl AS imageUrl FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL');
    $azure['products'] = $safe(
        $sql,
        <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.PublicId) AS publicId,
    p.ProductName AS name,
    p.InternalCode AS internalCode,
    p.LegacyProductId AS legacyProductId,
    (SELECT COUNT(*) FROM [migration].[LegacyProductMappings] m WHERE m.ProductId = p.ProductId) AS mappingCount
FROM [product].[Products] p
WHERE p.DeletedAtUtc IS NULL
SQL
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Azure SQL snapshot skipped: " . $e->getMessage() . PHP_EOL);
}

/** @var DiscoverLegacyMasterDataService $discovery */
$discovery = $container->get(DiscoverLegacyMasterDataService::class);
$report = $discovery->execute($azure);
$payload = $report->toArray();

$outDir = dirname(__DIR__) . '/core/docs/pre-migration-mappings';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Unable to create {$outDir}\n");
    exit(1);
}

file_put_contents($outDir . '/discovery-report.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/family-mappings.proposed.json', json_encode($payload['payload']['classification']['families'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/category-mappings.proposed.json', json_encode($payload['payload']['classification']['categories'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/series-mappings.proposed.json', json_encode($payload['payload']['classification']['series'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/specification-definitions.proposed.json', json_encode($payload['payload']['specifications']['definitions'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/tpro-vs-tpro-ext.proposed.json', json_encode($payload['payload']['tc']['tproVsTproExt'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/option-audits.proposed.json', json_encode($payload['payload']['tc']['optionAudits'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'wordpressConnected' => $report->wordpressConnected,
    'tcConnected' => $report->tcConnected,
    'queriesExecuted' => $report->queriesExecuted,
    'executionSeconds' => $report->executionSeconds,
    'join' => $payload['payload']['join'] ?? null,
    'variantStatistics' => $payload['payload']['tc']['variantStatistics'] ?? null,
    'coverage' => $payload['payload']['coverage'] ?? null,
    'migrationReadiness' => $payload['payload']['migrationReadiness'] ?? null,
    'outputDir' => $outDir,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
