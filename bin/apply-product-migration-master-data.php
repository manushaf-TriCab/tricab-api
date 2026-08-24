<?php

declare(strict_types=1);

/**
 * Phase 12.7 — scoped non-production master-data dry-run / apply.
 *
 * Approved scopes only:
 *   specification-units
 *   accessories-family
 *   migration-spec-field-schema
 *
 * Never applies d/v mapping rows, Categories, Series, Definitions, Products, prices, or media.
 *
 *   php bin/apply-product-migration-master-data.php --dry-run --scope=specification-units,accessories-family,migration-spec-field-schema
 *   php bin/apply-product-migration-master-data.php --apply --scope=... --confirm-non-production
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Migration\Application\Service\ScopedMasterDataApplyPlanner;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isApply;
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);

$scopeArg = null;
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--scope=')) {
        $scopeArg = substr($arg, 8);
    }
}
if ($scopeArg === null || trim($scopeArg) === '') {
    fwrite(STDERR, "ERROR: --scope is required. Approved scopes: specification-units,accessories-family,migration-spec-field-schema\n");
    fwrite(STDERR, "Broad apply without --scope is refused.\n");
    exit(1);
}

$planner = new ScopedMasterDataApplyPlanner();
try {
    $scopes = $planner->normalizeScopes(explode(',', $scopeArg));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);

$safe = static function (SqlConnection $sql, string $query): array {
    try {
        return $sql->fetchAll($query);
    } catch (Throwable $e) {
        return [['__error' => $e->getMessage() . ($e->getPrevious() ? ' | ' . $e->getPrevious()->getMessage() : '')]];
    }
};

$envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
$server = (string) ($envRows[0]['serverName'] ?? '');
$database = (string) ($envRows[0]['dbName'] ?? '');
if ($server === '' || isset($envRows[0]['__error'])) {
    fwrite(STDERR, "STOP — unable to resolve target server/database.\n");
    exit(2);
}
if (strcasecmp($server, 'tricab-mel') !== 0 || strcasecmp($database, 'tc') !== 0) {
    fwrite(STDERR, "STOP — target mismatch. Expected tricab-mel / tc, got {$server} / {$database}\n");
    exit(2);
}

$objectId = $safe($sql, "SELECT OBJECT_ID(N'[product].[SpecificationUnits]', N'U') AS objectId");
$unitsPresent = isset($objectId[0]['objectId']) && $objectId[0]['objectId'] !== null && (int) $objectId[0]['objectId'] > 0;

$units = [];
if ($unitsPresent) {
    $units = $safe($sql, 'SELECT UnitCode AS code, UnitName AS name, Symbol AS symbol FROM [product].[SpecificationUnits] WHERE DeletedAtUtc IS NULL');
    if (isset($units[0]['__error'])) {
        $units = [];
    }
}

$families = $safe($sql, 'SELECT FamilyCode AS code, FamilyName AS name, DisplayOrder AS displayOrder, IsActive AS isActive FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
if (isset($families[0]['__error'])) {
    $families = [];
}

$mappingPresent = false;
$colPresent = false;
$oldUq = false;
$newUq = false;
$mapObj = $safe($sql, "SELECT OBJECT_ID(N'[migration].[SpecificationFieldMappings]', N'U') AS objectId");
$mappingPresent = isset($mapObj[0]['objectId']) && $mapObj[0]['objectId'] !== null && (int) $mapObj[0]['objectId'] > 0;
if ($mappingPresent) {
    $col = $safe($sql, "SELECT COL_LENGTH(N'migration.SpecificationFieldMappings', N'LegacyStyleCode') AS colLen");
    $colPresent = isset($col[0]['colLen']) && $col[0]['colLen'] !== null;
    $old = $safe($sql, "SELECT 1 AS ok FROM sys.key_constraints WHERE name = N'UQ_migration_SpecificationFieldMappings_Source' AND parent_object_id = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')");
    $oldUq = isset($old[0]['ok']);
    $new = $safe($sql, "SELECT 1 AS ok FROM sys.indexes WHERE name = N'UQ_migration_SpecificationFieldMappings_SourceStyle' AND object_id = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')");
    $newUq = isset($new[0]['ok']);
}

$productsBefore = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$variantsBefore = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$nodesBefore = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$pricesBefore = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductPrices]')[0]['cnt'] ?? 0));
$vPricesBefore = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariantPrices]')[0]['cnt'] ?? 0));

$snapshot = [
    'specificationUnitsPresent' => $unitsPresent,
    'units' => $units,
    'families' => $families,
    'specificationFieldMappingsPresent' => $mappingPresent,
    'legacyStyleCodePresent' => $colPresent,
    'oldSourceUniquePresent' => $oldUq,
    'styleUniqueIndexPresent' => $newUq,
];

$plan = $planner->plan($snapshot, $scopes);

echo <<<TXT
PHASE 12.7 SCOPED APPLY

Target server:
{$server}

Target database:
{$database}

Environment:
NON-PRODUCTION

Approved scopes:

SpecificationUnits schema
YES

SpecificationUnits seed
YES

ACCESSORIES Family
YES

Style-specific migration mapping schema
YES


d/v mapping records
NO

Categories
NO

Series
NO

Specification Definitions
NO

Options/Aliases
NO

Catalogue placements
NO

Products
NO

Variants
NO

Media/Documents
NO

Prices
NO

TXT;

echo "\nRequested scopes: " . implode(',', $scopes) . "\n";
echo "Mode: " . ($isApply && !$isDryRun ? 'APPLY' : 'DRY-RUN') . "\n\n";

echo "Operation counts:\n";
foreach ($plan['counts'] as $k => $v) {
    echo "  {$k}: {$v}\n";
}
echo "\nOperations:\n";
foreach ($plan['operations'] as $op) {
    echo sprintf(
        "  [%s] %s → %s%s\n",
        $op['action'],
        $op['object'],
        $op['detail'] ?? '',
        isset($op['unit']) ? ' (' . $op['unit']['meaning'] . ')' : '',
    );
}

if (($plan['counts']['CONFLICT'] ?? 0) > 0) {
    fwrite(STDERR, "STOP — conflicts present. Resolve before apply.\n");
    exit(3);
}

$outDir = dirname(__DIR__) . '/core/docs/phase12.7';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Unable to create {$outDir}\n");
    exit(1);
}

$artifact = [
    'generatedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
    'mode' => $isApply && !$isDryRun ? 'apply' : 'dry-run',
    'server' => $server,
    'database' => $database,
    'environment' => 'NON-PRODUCTION',
    'scopes' => $scopes,
    'plan' => $plan,
    'countsBefore' => [
        'products' => $productsBefore,
        'variants' => $variantsBefore,
        'catalogueNodes' => $nodesBefore,
        'productPrices' => $pricesBefore,
        'productVariantPrices' => $vPricesBefore,
    ],
    'legacyWrites' => 0,
];
file_put_contents($outDir . '/scoped-apply-plan.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

if (!$isApply || $isDryRun) {
    echo "\nDRY-RUN complete. Azure mutations: 0. Legacy writes: 0.\n";
    echo "Artifact: {$outDir}/scoped-apply-plan.json\n";
    exit(0);
}

if (!$confirmNonProd) {
    fwrite(STDERR, "STOP — --confirm-non-production required for apply.\n");
    exit(2);
}

// ---- APPLY (scoped only) ----
$applied = [];
$failed = null;

try {
    if (in_array(ScopedMasterDataApplyPlanner::SCOPE_SPECIFICATION_UNITS, $scopes, true)) {
        if (!$unitsPresent) {
            $sql->execute(<<<'SQL'
CREATE TABLE [product].[SpecificationUnits] (
    [SpecificationUnitId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationUnits_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UnitCode] NVARCHAR(50) NOT NULL,
    [UnitName] NVARCHAR(200) NOT NULL,
    [Symbol] NVARCHAR(50) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationUnits_DisplayOrder DEFAULT (0),
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationUnits_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_SpecificationUnits PRIMARY KEY CLUSTERED ([SpecificationUnitId]),
    CONSTRAINT UQ_product_SpecificationUnits_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_SpecificationUnits_UnitCode UNIQUE ([UnitCode]),
    CONSTRAINT CK_product_SpecificationUnits_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([UnitName]))) > 0),
    CONSTRAINT CK_product_SpecificationUnits_Symbol_NotEmpty CHECK (LEN(LTRIM(RTRIM([Symbol]))) > 0),
    CONSTRAINT FK_product_SpecificationUnits_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationUnits_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationUnits_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
)
SQL);
            $applied[] = 'CREATE product.SpecificationUnits';
        } else {
            $applied[] = 'SKIP product.SpecificationUnits (already present)';
        }

        foreach ($planner->approvedUnits() as $unit) {
            $code = $unit['code'];
            if (!preg_match('/^[A-Z0-9_]+$/', $code)) {
                throw new RuntimeException('Invalid unit code');
            }
            $hit = $safe($sql, "SELECT SpecificationUnitId AS id FROM [product].[SpecificationUnits] WHERE UnitCode = N'{$code}' AND DeletedAtUtc IS NULL");
            if (isset($hit[0]['id'])) {
                $applied[] = "SKIP unit {$code}";
                continue;
            }
            $name = str_replace("'", "''", $unit['name']);
            $symbol = str_replace("'", "''", $unit['symbol']);
            $desc = str_replace("'", "''", $unit['meaning']);
            $order = (int) $unit['displayOrder'];
            $sql->execute(
                "INSERT INTO [product].[SpecificationUnits] (UnitCode, UnitName, Symbol, Description, DisplayOrder, IsActive)
                 VALUES (N'{$code}', N'{$name}', N'{$symbol}', N'{$desc}', {$order}, 1)"
            );
            $applied[] = "INSERT unit {$code}";
        }
    }

    if (in_array(ScopedMasterDataApplyPlanner::SCOPE_ACCESSORIES_FAMILY, $scopes, true)) {
        $hit = $safe($sql, "SELECT ProductFamilyId AS id FROM [product].[ProductFamilies] WHERE FamilyCode = N'ACCESSORIES' AND DeletedAtUtc IS NULL");
        if (isset($hit[0]['id'])) {
            $applied[] = 'SKIP ProductFamily ACCESSORIES (MAP_EXISTING)';
        } else {
            $sql->execute(
                "INSERT INTO [product].[ProductFamilies] (FamilyCode, FamilyName, Description, DisplayOrder, IsActive)
                 VALUES (N'ACCESSORIES', N'Accessories', N'WordPress accessory Products family (Phase 12.7 scoped apply).', 30, 1)"
            );
            $applied[] = 'INSERT ProductFamily ACCESSORIES';
        }
    }

    if (in_array(ScopedMasterDataApplyPlanner::SCOPE_MIGRATION_SPEC_FIELD_SCHEMA, $scopes, true)) {
        if (!$mappingPresent) {
            throw new RuntimeException('migration.SpecificationFieldMappings missing');
        }
        if (!$colPresent) {
            $sql->execute('ALTER TABLE [migration].[SpecificationFieldMappings] ADD [LegacyStyleCode] NVARCHAR(50) NULL');
            $applied[] = 'ALTER ADD LegacyStyleCode';
        } else {
            $applied[] = 'SKIP LegacyStyleCode column';
        }
        $old = $safe($sql, "SELECT 1 AS ok FROM sys.key_constraints WHERE name = N'UQ_migration_SpecificationFieldMappings_Source' AND parent_object_id = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')");
        if (isset($old[0]['ok'])) {
            $sql->execute('ALTER TABLE [migration].[SpecificationFieldMappings] DROP CONSTRAINT [UQ_migration_SpecificationFieldMappings_Source]');
            $applied[] = 'DROP CONSTRAINT UQ_migration_SpecificationFieldMappings_Source';
        }
        $new = $safe($sql, "SELECT 1 AS ok FROM sys.indexes WHERE name = N'UQ_migration_SpecificationFieldMappings_SourceStyle' AND object_id = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')");
        if (!isset($new[0]['ok'])) {
            $sql->execute(
                'CREATE UNIQUE NONCLUSTERED INDEX [UQ_migration_SpecificationFieldMappings_SourceStyle]
                 ON [migration].[SpecificationFieldMappings] ([SourceSystem], [SourceTable], [SourceField], [LegacyStyleCode])'
            );
            $applied[] = 'CREATE INDEX UQ_migration_SpecificationFieldMappings_SourceStyle';
        } else {
            $applied[] = 'SKIP style unique index';
        }
    }
} catch (Throwable $e) {
    $failed = $e->getMessage();
    fwrite(STDERR, "APPLY FAILED: {$failed}\n");
}

$productsAfter = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$variantsAfter = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$nodesAfter = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0));
$pricesAfter = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductPrices]')[0]['cnt'] ?? 0));
$vPricesAfter = (int) (($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariantPrices]')[0]['cnt'] ?? 0));
$unitsAfter = $safe($sql, 'SELECT UnitCode AS code FROM [product].[SpecificationUnits] WHERE DeletedAtUtc IS NULL ORDER BY UnitCode');
$familyAfter = $safe($sql, "SELECT FamilyCode AS code, FamilyName AS name FROM [product].[ProductFamilies] WHERE FamilyCode = N'ACCESSORIES' AND DeletedAtUtc IS NULL");
$unitsTableAfter = $safe($sql, "SELECT OBJECT_ID(N'[product].[SpecificationUnits]', N'U') AS objectId");
$styleColAfter = $safe($sql, "SELECT COL_LENGTH(N'migration.SpecificationFieldMappings', N'LegacyStyleCode') AS colLen");

$result = [
    'appliedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
    'failed' => $failed,
    'applied' => $applied,
    'legacyWrites' => 0,
    'countsAfter' => [
        'products' => $productsAfter,
        'variants' => $variantsAfter,
        'catalogueNodes' => $nodesAfter,
        'productPrices' => $pricesAfter,
        'productVariantPrices' => $vPricesAfter,
    ],
    'countsBefore' => $artifact['countsBefore'],
    'units' => array_column(array_filter($unitsAfter, static fn ($r) => is_array($r) && isset($r['code'])), 'code'),
    'accessoriesFamily' => $familyAfter[0] ?? null,
    'specificationUnitsPresent' => isset($unitsTableAfter[0]['objectId']) && (int) $unitsTableAfter[0]['objectId'] > 0,
    'legacyStyleCodePresent' => isset($styleColAfter[0]['colLen']) && $styleColAfter[0]['colLen'] !== null,
];
file_put_contents($outDir . '/scoped-apply-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "\nAPPLY " . ($failed ? "FAILED\n" : "COMPLETED\n");
foreach ($applied as $line) {
    echo "  {$line}\n";
}
echo "Products {$productsBefore} → {$productsAfter}\n";
echo "Variants {$variantsBefore} → {$variantsAfter}\n";
echo "CatalogueNodes {$nodesBefore} → {$nodesAfter}\n";
echo "Legacy writes: 0\n";
echo "Artifact: {$outDir}/scoped-apply-result.json\n";

exit($failed ? 4 : 0);
