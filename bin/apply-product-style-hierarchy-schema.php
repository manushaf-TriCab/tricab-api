<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: apply Product Style Hierarchy schema + media-upload columns
 * to the configured Azure SQL database.
 *
 * Refuses production. Does not touch WordPress or legacy tc MySQL.
 *
 * Usage (from tricab-api):
 *   php bin/apply-product-style-hierarchy-schema.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

printf("APP_ENV=%s\n", $settings->appEnv);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("blobAuthMode=%s\n", $settings->blobAuthMode);
printf("blobContainerProducts=%s\n", $settings->blobContainerProducts);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION DEPLOYMENT = NO. Stopping before schema apply.\n");
    exit(2);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql — nothing to apply.\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$identity = $db->fetchAll(
    'SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]',
);
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL SUSER_SNAME()=%s\n", (string) ($identity[0]['LoginName'] ?? ''));

if (strcasecmp($databaseName, $settings->dbDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}

if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Server/database name looks like production. Stopping.\n");
    exit(2);
}

$inspect = static function (SqlConnection $db): array {
    $rows = $db->fetchAll(
        <<<'SQL'
SELECT
    CASE WHEN OBJECT_ID(N'[product].[ProductStyles]', N'U') IS NULL THEN 0 ELSE 1 END AS [ProductStyles],
    CASE WHEN OBJECT_ID(N'[product].[ProductStyleAssignments]', N'U') IS NULL THEN 0 ELSE 1 END AS [ProductStyleAssignments],
    CASE WHEN OBJECT_ID(N'[product].[ProductStyleSpecificationValues]', N'U') IS NULL THEN 0 ELSE 1 END AS [ProductStyleSpecificationValues],
    CASE WHEN OBJECT_ID(N'[product].[ProductStyleMediaAssets]', N'U') IS NULL THEN 0 ELSE 1 END AS [ProductStyleMediaAssets]
SQL
    );
    $row = $rows[0] ?? [];

    return [
        'ProductStyles' => ((int) ($row['ProductStyles'] ?? 0)) === 1,
        'ProductStyleAssignments' => ((int) ($row['ProductStyleAssignments'] ?? 0)) === 1,
        'ProductStyleSpecificationValues' => ((int) ($row['ProductStyleSpecificationValues'] ?? 0)) === 1,
        'ProductStyleMediaAssets' => ((int) ($row['ProductStyleMediaAssets'] ?? 0)) === 1,
    ];
};

$before = $inspect($db);
echo "Existing objects before apply:\n";
foreach ($before as $table => $exists) {
    printf("  %s: %s\n", $table, $exists ? 'present' : 'missing');
}

$runScript = static function (SqlConnection $db, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    $batches = [];
    $current = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*GO\s*(?:--.*)?$/i', $line) === 1) {
            $batches[] = implode("\n", $current);
            $current = [];
            continue;
        }
        $current[] = $line;
    }
    $batches[] = implode("\n", $current);
    $batches = array_values(array_filter($batches, static fn (string $batch): bool => trim($batch) !== ''));
    foreach ($batches as $index => $batch) {
        $db->execute($batch);
        printf("  ok %s batch %d/%d\n", basename($path), $index + 1, count($batches));
    }
};

$createPath = $basePath . '/database/scripts/development/create-product-style-hierarchy-schema.sql';
$alterPath = $basePath . '/database/scripts/development/alter-product-style-media-upload.sql';
$specUniquePath = $basePath . '/database/scripts/development/alter-product-style-spec-unique-filtered.sql';

echo "Applying create-product-style-hierarchy-schema.sql (idempotent IF NOT EXISTS)...\n";
$runScript($db, $createPath);
echo "Applying alter-product-style-media-upload.sql (idempotent IF COL_LENGTH IS NULL)...\n";
$runScript($db, $alterPath);
echo "Applying alter-product-style-spec-unique-filtered.sql (idempotent)...\n";
$runScript($db, $specUniquePath);

$after = $inspect($db);
echo "Existing objects after apply:\n";
foreach ($after as $table => $exists) {
    printf("  %s: %s\n", $table, $exists ? 'present' : 'missing');
    if (!$exists) {
        fwrite(STDERR, "Expected table missing after apply: {$table}\n");
        exit(1);
    }
}

$verify = $db->fetchAll(
    <<<'SQL'
SELECT
    t.name AS [TableName],
    c.name AS [ColumnName],
    ty.name AS [TypeName],
    c.is_nullable AS [IsNullable]
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
INNER JOIN sys.columns c ON c.object_id = t.object_id
INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE s.name = N'product'
  AND t.name IN (N'ProductStyles', N'ProductStyleAssignments', N'ProductStyleSpecificationValues', N'ProductStyleMediaAssets')
ORDER BY t.name, c.column_id
SQL
);
echo "Columns:\n";
foreach ($verify as $row) {
    printf(
        "  %s.%s %s %s\n",
        (string) $row['TableName'],
        (string) $row['ColumnName'],
        (string) $row['TypeName'],
        ((int) $row['IsNullable'] === 1) ? 'NULL' : 'NOT NULL',
    );
}

$constraints = $db->fetchAll(
    <<<'SQL'
SELECT
    OBJECT_NAME(parent_object_id) AS [TableName],
    name AS [ConstraintName],
    type_desc AS [ConstraintType]
FROM sys.objects
WHERE schema_id = SCHEMA_ID(N'product')
  AND parent_object_id IN (
      OBJECT_ID(N'[product].[ProductStyles]'),
      OBJECT_ID(N'[product].[ProductStyleAssignments]'),
      OBJECT_ID(N'[product].[ProductStyleSpecificationValues]'),
      OBJECT_ID(N'[product].[ProductStyleMediaAssets]')
  )
  AND type IN ('PK', 'UQ', 'F', 'C', 'TR')
ORDER BY TableName, ConstraintType, ConstraintName
SQL
);
echo "Constraints/triggers:\n";
foreach ($constraints as $row) {
    printf("  %s %s %s\n", (string) $row['TableName'], (string) $row['ConstraintType'], (string) $row['ConstraintName']);
}

$indexes = $db->fetchAll(
    <<<'SQL'
SELECT t.name AS [TableName], i.name AS [IndexName], i.is_unique AS [IsUnique]
FROM sys.indexes i
INNER JOIN sys.tables t ON t.object_id = i.object_id
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
WHERE s.name = N'product'
  AND t.name IN (N'ProductStyles', N'ProductStyleAssignments', N'ProductStyleSpecificationValues', N'ProductStyleMediaAssets')
  AND i.name IS NOT NULL
ORDER BY t.name, i.name
SQL
);
echo "Indexes:\n";
foreach ($indexes as $row) {
    printf(
        "  %s %s unique=%s\n",
        (string) $row['TableName'],
        (string) $row['IndexName'],
        ((int) $row['IsUnique'] === 1) ? 'yes' : 'no',
    );
}

$permissions = $db->fetchAll(
    <<<'SQL'
SELECT [PermissionCode], [IsActive]
FROM [identity].[Permissions]
WHERE [PermissionCode] LIKE N'PRODUCT.STYLES%'
ORDER BY [PermissionCode]
SQL
);
echo "Permissions:\n";
foreach ($permissions as $row) {
    printf("  %s active=%s\n", (string) $row['PermissionCode'], ((int) $row['IsActive'] === 1) ? 'yes' : 'no');
}

echo "PRODUCTION DATABASE MIGRATION = NO\n";
echo "LEGACY WORDPRESS WRITES = 0\n";
echo "LEGACY tc MYSQL WRITES = 0\n";
echo "Schema apply complete.\n";
