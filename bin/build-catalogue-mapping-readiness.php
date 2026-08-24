<?php

declare(strict_types=1);

/**
 * Phase 12.6 — catalogue mapping & migration readiness (read-only).
 * Never writes to WordPress/tc. Does not migrate Products, Variants, media, or prices.
 *
 * php bin/build-catalogue-mapping-readiness.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\BuildCatalogueMappingReadinessService;

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
        'Products',
        'Special Applications',
        'Plugs & Sockets',
        'Industrial',
    ],
];

try {
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $query): array {
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

/** @var BuildCatalogueMappingReadinessService $service */
$service = $container->get(BuildCatalogueMappingReadinessService::class);
$report = $service->execute($azure);
$payload = $report->toArray();

$outDir = dirname(__DIR__) . '/core/docs/phase12.6-mappings';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Unable to create {$outDir}\n");
    exit(1);
}

file_put_contents($outDir . '/readiness-report.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$sections = [
    'product-identity' => $payload['payload']['productIdentity'] ?? [],
    'family-mappings' => $payload['payload']['classification']['families'] ?? [],
    'category-mappings' => $payload['payload']['classification']['categories'] ?? [],
    'series-mappings' => $payload['payload']['classification']['series'] ?? [],
    'dv-field-mappings' => $payload['payload']['dvFieldMappings'] ?? [],
    'units' => $payload['payload']['units'] ?? [],
    'options' => $payload['payload']['options'] ?? [],
    'standards' => $payload['payload']['standards'] ?? [],
    'approvals' => $payload['payload']['approvals'] ?? [],
    'core-colours' => $payload['payload']['coreColours'] ?? [],
    'certificates' => $payload['payload']['certificates'] ?? [],
    'catalogue-navigation' => $payload['payload']['catalogueNavigation'] ?? [],
    'existing-azure-products' => $payload['payload']['existingAzureProducts'] ?? [],
    'tpro-exclusions' => $payload['payload']['tproExclusions'] ?? [],
    'dimension-scores' => $payload['payload']['dimensionScores'] ?? [],
    'blockers' => $payload['payload']['blockers'] ?? [],
    'proposed-master-data' => $payload['payload']['proposedMasterDataChanges'] ?? [],
];

foreach ($sections as $name => $data) {
    file_put_contents(
        $outDir . '/' . $name . '.proposed.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

$counts = $payload['payload']['productReadiness']['counts'] ?? [];
$vCounts = $payload['payload']['variantReadiness']['counts'] ?? [];
echo "PHASE 12.6 readiness written to {$outDir}\n";
echo 'queries=' . ($payload['queriesExecuted'] ?? 0)
    . ' seconds=' . ($payload['executionSeconds'] ?? 0)
    . ' legacyWrites=0'
    . ' fullCatalogueDryRun=' . ($payload['fullCatalogueDryRun'] ?? 'NOT READY')
    . PHP_EOL;
echo 'PRODUCT READY=' . ($counts['READY'] ?? 0)
    . ' REVIEW=' . ($counts['REVIEW'] ?? 0)
    . ' BLOCKED=' . ($counts['BLOCKED'] ?? 0)
    . ' SOURCE_ONLY=' . ($counts['SOURCE_ONLY'] ?? 0)
    . ' INVALID=' . ($counts['INVALID'] ?? 0)
    . ' EXCLUDED=' . ($counts['EXCLUDED'] ?? 0)
    . PHP_EOL;
echo 'VARIANT READY=' . ($vCounts['READY'] ?? 0)
    . ' REVIEW=' . ($vCounts['REVIEW'] ?? 0)
    . ' BLOCKED=' . ($vCounts['BLOCKED'] ?? 0)
    . ' EXCLUDED=' . ($vCounts['EXCLUDED'] ?? 0)
    . PHP_EOL;
