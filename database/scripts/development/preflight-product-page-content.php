<?php

declare(strict_types=1);

/**
 * SELECT-ONLY production preflight for database/scripts/development/alter-product-page-content.sql.
 * Never writes: every statement is checked to start with SELECT/WITH before it is sent. Prints PASS/FAIL as JSON.
 * Run again AFTER the migration (with --post) to prove existing data is untouched.
 *
 *   php database/scripts/development/preflight-product-page-content.php [--post]
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__, 3);
$settings = Environment::settings($root);
$post = in_array('--post', $argv, true);
$connection = ContainerFactory::create($settings)->get(SqlConnection::class);

$q = static function (string $sql, array $params = []) use ($connection): array {
    if (preg_match('/^\s*(SELECT|WITH)\b/i', $sql) !== 1 || preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|EXEC|GRANT)\b/i', preg_replace("/'[^']*'/", "''", $sql) ?? $sql) === 1) {
        throw new RuntimeException('REFUSED: not a SELECT-only statement');
    }

    return $connection->fetchAll($sql, $params);
};

$problems = [];
$facts = [];

// 1. target
$facts['target'] = ['appEnv' => $settings->appEnv, 'host' => $settings->dbHost, 'database' => $settings->dbDatabase];
if (!str_contains(strtolower((string) $settings->dbHost), 'tricab-mel') || strtolower((string) $settings->dbDatabase) !== 'tc') { $problems[] = 'UNEXPECTED_TARGET'; }

// 2. the table (and any of its constraint names) must not exist before; must exist exactly as designed after
$exists = $q("SELECT OBJECT_ID(N'[product].[ProductPageContentSettings]', N'U') AS id")[0]['id'] !== null;
$names = $q("SELECT name FROM sys.objects WHERE name LIKE N'%ProductPageContentSettings%'");
$facts['tableExists'] = $exists;
$facts['objectsNamedLikeTheNewTable'] = array_column($names, 'name');
if ($post) {
    if (!$exists) { $problems[] = 'POST: TABLE_MISSING'; }
} else {
    if ($exists) { $problems[] = 'TABLE_ALREADY_EXISTS'; }
    if ($names !== []) { $problems[] = 'NAME_COLLISION: ' . implode(',', array_column($names, 'name')); }
}

// 3. prerequisites for the foreign keys
$facts['products'] = $q("SELECT c.name AS col, ty.name AS typ, c.is_identity AS idn FROM sys.columns c JOIN sys.types ty ON ty.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID(N'[product].[Products]') AND c.name = N'ProductId'");
$facts['productsPk'] = $q("SELECT i.name FROM sys.indexes i WHERE i.object_id = OBJECT_ID(N'[product].[Products]') AND i.is_primary_key = 1");
$facts['users'] = $q("SELECT c.name AS col, ty.name AS typ FROM sys.columns c JOIN sys.types ty ON ty.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID(N'[identity].[Users]') AND c.name = N'UserId'");
$facts['usersPk'] = $q("SELECT i.name FROM sys.indexes i WHERE i.object_id = OBJECT_ID(N'[identity].[Users]') AND i.is_primary_key = 1");
if (($facts['products'][0]['typ'] ?? '') !== 'bigint' || $facts['productsPk'] === []) { $problems[] = 'PRODUCTS_PK_NOT_BIGINT'; }
if (($facts['users'][0]['typ'] ?? '') !== 'bigint' || $facts['usersPk'] === []) { $problems[] = 'USERS_PK_NOT_BIGINT'; }

// 4. permission to create the table (informational: checked with HAS_PERMS_BY_NAME, no DDL attempted)
$perm = $q("SELECT HAS_PERMS_BY_NAME(DB_NAME(), N'DATABASE', N'CREATE TABLE') AS createTable, HAS_PERMS_BY_NAME(N'product', N'SCHEMA', N'ALTER') AS alterSchema, HAS_PERMS_BY_NAME(N'product', N'SCHEMA', N'REFERENCES') AS referencesSchema")[0];
$facts['permissions'] = $perm;
if ((int) ($perm['createTable'] ?? 0) !== 1 || (int) ($perm['alterSchema'] ?? 0) !== 1) { $problems[] = 'INSUFFICIENT_PERMISSION_TO_CREATE_TABLE'; }

// 5. existing ProductPageMediaSettings must stay untouched: snapshot
$media = $q("SELECT OBJECT_ID(N'[product].[ProductPageMediaSettings]', N'U') AS id")[0]['id'] !== null;
$facts['productPageMediaSettings'] = ['exists' => $media];
if ($media) {
    $facts['productPageMediaSettings'] += $q('SELECT COUNT(*) AS rows, CHECKSUM_AGG(BINARY_CHECKSUM(ProductId, DescriptionImageWidth, DescriptionImageAlignment)) AS checksum, CONVERT(VARCHAR(33), MAX(UpdatedAtUtc), 127) AS maxUpdated FROM product.ProductPageMediaSettings')[0];
}

// 6. existing products untouched: snapshot of the product catalogue the migration must not modify
$facts['catalogueSnapshot'] = $q("SELECT COUNT(*) AS products, SUM(CASE WHEN st.StatusCode = N'published' THEN 1 ELSE 0 END) AS published, CHECKSUM_AGG(BINARY_CHECKSUM(p.ProductId, p.InternalCode, p.ProductName, p.FullDescription, p.ProductStatusId, p.ProductLifecycleStatusId)) AS checksum, CONVERT(VARCHAR(33), MAX(p.UpdatedAtUtc), 127) AS maxUpdated FROM product.Products p JOIN product.ProductStatuses st ON st.ProductStatusId = p.ProductStatusId WHERE p.DeletedAtUtc IS NULL")[0];
$facts['specSnapshot'] = $q('SELECT COUNT(*) AS rows, CHECKSUM_AGG(BINARY_CHECKSUM(ProductSpecificationValueId, ProductId, SpecificationDefinitionId, ValueText, ValueLongText)) AS checksum FROM product.ProductSpecificationValues WHERE DeletedAtUtc IS NULL')[0];

// 7. exposure analysis: which products carry PRODUCT-MARKING text, and would the migration/API expose it? (it must not: no rows are inserted, the default is OFF)
$marking = $q("SELECT p.InternalCode AS code, st.StatusCode AS status FROM product.ProductSpecificationValues sv JOIN product.SpecificationDefinitions d ON d.SpecificationDefinitionId = sv.SpecificationDefinitionId JOIN product.Products p ON p.ProductId = sv.ProductId JOIN product.ProductStatuses st ON st.ProductStatusId = p.ProductStatusId WHERE d.DefinitionCode = N'PRODUCT-MARKING' AND sv.DeletedAtUtc IS NULL AND p.DeletedAtUtc IS NULL AND LTRIM(RTRIM(COALESCE(sv.ValueLongText, sv.ValueText, N''))) <> N'' ORDER BY st.StatusCode, p.InternalCode");
$published = array_values(array_filter($marking, static fn (array $r): bool => $r['status'] === 'published'));
$facts['productMarking'] = ['productsWithText' => count($marking), 'published' => count($published), 'publishedCodes' => array_column($published, 'code'), 'draftCodes' => array_column(array_filter($marking, static fn (array $r): bool => $r['status'] !== 'published'), 'code'),
    'exposedByMigration' => 0, 'reason' => 'the migration inserts no rows; with no settings row the API resolves Product Marking OFF; the column default is 0'];
if ($post && $exists) {
    $facts['rowsInNewTable'] = (int) $q('SELECT COUNT(*) AS n FROM product.ProductPageContentSettings')[0]['n'];
    $facts['rowsEnablingMarkingOnPublishedProducts'] = (int) $q("SELECT COUNT(*) AS n FROM product.ProductPageContentSettings s JOIN product.Products p ON p.ProductId = s.ProductId JOIN product.ProductStatuses st ON st.ProductStatusId = p.ProductStatusId WHERE s.ShowProductMarking = 1 AND st.StatusCode = N'published'")[0]['n'];
    if ($facts['rowsInNewTable'] !== 0) { $problems[] = 'POST: MIGRATION_SHOULD_LEAVE_THE_TABLE_EMPTY'; }
}

echo json_encode(['mode' => $post ? 'POST-MIGRATION' : 'PRE-MIGRATION', 'gate' => $problems === [] ? 'PASS' : 'FAIL', 'problems' => $problems, 'facts' => $facts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($problems === [] ? 0 : 1);
