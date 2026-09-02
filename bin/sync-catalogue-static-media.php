<?php

declare(strict_types=1);

/**
 * DEVELOPMENT: copy website catalogue/certificate static files into API public/media.
 *
 * Does not UPDATE CatalogueNodes.ImageUrl or ImageMediaPublicId.
 * Does not write WordPress, legacy MySQL, blob, or production.
 *
 * Usage (from tricab-api):
 *   php bin/sync-catalogue-static-media.php
 *   php bin/sync-catalogue-static-media.php --apply
 *
 * Optional:
 *   TRICAB_WEB_PUBLIC=/path/to/tricab-web/public
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\CatalogueWebsiteAssetPath;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$apply = in_array('--apply', $argv, true);
$settings = Environment::settings($basePath);
$expectedHost = 'tricab-mel.database.windows.net';
$expectedDatabase = 'tc';
$expectedServer = 'tricab-mel';

printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');
printf("mode=%s\n", $apply ? 'APPLY' : 'DRY-RUN');

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
if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Server/database looks like production. Stopping.\n");
    exit(2);
}

$webPublic = getenv('TRICAB_WEB_PUBLIC');
if (!is_string($webPublic) || trim($webPublic) === '') {
    $webPublic = dirname($basePath, 2) . '/tricab-web/public';
}
$webPublic = rtrim($webPublic, '/');
$apiPublic = $basePath . '/public';

printf("webPublic=%s\n", $webPublic);
printf("apiPublic=%s\n", $apiPublic);

if (!is_dir($webPublic)) {
    fwrite(STDERR, "Web public directory not found. Stopping.\n");
    exit(1);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{mapped: list<array<string, mixed>>, missing: list<array<string, mixed>>, empty: int}
 */
$inventory = static function (array $rows, string $sourceKey) use ($webPublic, $apiPublic): array {
    $mapped = [];
    $missing = [];
    $empty = 0;
    foreach ($rows as $row) {
        $stored = isset($row[$sourceKey]) && is_string($row[$sourceKey]) ? trim($row[$sourceKey]) : '';
        if ($stored === '') {
            ++$empty;

            continue;
        }
        $target = CatalogueWebsiteAssetPath::websiteStaticTarget($stored);
        if ($target === null) {
            $missing[] = [
                'stored' => $stored,
                'reason' => 'not-website-static',
                'label' => $row['Label'] ?? $row['NodeName'] ?? $row['OptionCode'] ?? '',
            ];

            continue;
        }
        $sourceRelative = $target['sourceRelative'];
        $sourcePath = $webPublic . $sourceRelative;
        if (!is_file($sourcePath)) {
            $alt = findWebSourceCaseInsensitive($webPublic, $sourceRelative);
            if ($alt !== null) {
                $sourcePath = $alt;
            }
        }
        $destDir = $target['kind'] === 'certificate' ? $apiPublic . '/media/certificates' : $apiPublic . '/media/catalogue';
        $destPath = $destDir . '/' . $target['basename'];
        $item = [
            'stored' => $stored,
            'normalized' => $sourceRelative,
            'basename' => $target['basename'],
            'kind' => $target['kind'],
            'sourcePath' => $sourcePath,
            'destPath' => $destPath,
            'targetUrl' => $target['target'],
            'sourceExists' => is_file($sourcePath),
            'destExists' => is_file($destPath),
            'label' => (string) ($row['Label'] ?? $row['NodeName'] ?? $row['OptionCode'] ?? ''),
        ];
        if (!$item['sourceExists']) {
            $missing[] = $item;

            continue;
        }
        $mapped[] = $item;
    }

    return ['mapped' => $mapped, 'missing' => $missing, 'empty' => $empty];
};

function findWebSourceCaseInsensitive(string $webPublic, string $sourceRelative): ?string
{
    $dir = dirname($webPublic . $sourceRelative);
    $want = strtolower(basename($sourceRelative));
    if (!is_dir($dir)) {
        return null;
    }
    $files = scandir($dir);
    if ($files === false) {
        return null;
    }
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        if (strtolower($file) === $want && is_file($dir . '/' . $file)) {
            return $dir . '/' . $file;
        }
    }

    return null;
}

$nodeRows = $db->fetchAll(
    <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    n.[NodeCode],
    n.[NodeName],
    n.[ImageUrl],
    n.[NodeName] AS [Label]
FROM [product].[CatalogueNodes] n
WHERE n.[DeletedAtUtc] IS NULL
ORDER BY n.[NodeCode]
SQL
);

$certRows = $db->fetchAll(
    <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), o.[PublicId]) AS [PublicId],
    o.[OptionCode],
    o.[OptionLabel],
    o.[ImageUrl],
    o.[OptionCode] AS [Label]
FROM [product].[SpecificationOptions] o
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = o.[SpecificationDefinitionId]
WHERE o.[DeletedAtUtc] IS NULL
  AND o.[ImageUrl] IS NOT NULL
  AND LTRIM(RTRIM(o.[ImageUrl])) <> N''
  AND (
        LOWER(d.[DefinitionCode]) LIKE N'%APPROVAL%'
        OR o.[ImageUrl] LIKE N'/certificates/%'
  )
ORDER BY o.[OptionCode]
SQL
);

$nodes = $inventory($nodeRows, 'ImageUrl');
$certs = $inventory($certRows, 'ImageUrl');

$uniqueByDest = [];
$conflicts = [];
foreach (array_merge($nodes['mapped'], $certs['mapped']) as $item) {
    $dest = $item['destPath'];
    if (!isset($uniqueByDest[$dest])) {
        $uniqueByDest[$dest] = $item;

        continue;
    }
    $existing = $uniqueByDest[$dest];
    if (realpath($existing['sourcePath']) !== realpath($item['sourcePath'])) {
        $conflicts[] = [
            'dest' => $dest,
            'a' => $existing['sourcePath'],
            'b' => $item['sourcePath'],
        ];
    }
}

$typoNodes = [];
foreach ($nodeRows as $row) {
    $stored = isset($row['ImageUrl']) && is_string($row['ImageUrl']) ? $row['ImageUrl'] : '';
    if (str_contains($stored, '/assests/')) {
        $typoNodes[] = [
            'node' => $row['NodeName'] ?? $row['NodeCode'] ?? '',
            'stored' => $stored,
            'normalized' => CatalogueWebsiteAssetPath::normalize($stored),
        ];
    }
}

printf("\n=== Catalogue nodes ===\n");
printf("totalNodes=%d\n", count($nodeRows));
printf("emptyImageUrl=%d\n", $nodes['empty']);
printf("mappedWebsiteAssets=%d\n", count($nodes['mapped']));
printf("unmappedOrMissing=%d\n", count($nodes['missing']));
printf("typoAssests=%d\n", count($typoNodes));
foreach ($typoNodes as $typo) {
    printf("  typo %s %s -> %s\n", $typo['node'], $typo['stored'], $typo['normalized']);
}

$uniqueSources = [];
foreach ($nodes['mapped'] as $item) {
    $uniqueSources[$item['basename']] = $item['sourcePath'];
}
printf("uniqueCatalogueFiles=%d\n", count($uniqueSources));

printf("\n=== Certificates ===\n");
printf("optionsWithImageUrl=%d\n", count($certRows));
printf("mappedCertificateAssets=%d\n", count($certs['mapped']));
printf("unmappedOrMissing=%d\n", count($certs['missing']));

if ($nodes['missing'] !== []) {
    printf("\n=== Missing / unmapped catalogue ===\n");
    foreach (array_slice($nodes['missing'], 0, 40) as $item) {
        printf("  %s stored=%s source=%s\n", $item['label'] ?? '', $item['stored'] ?? '', $item['sourcePath'] ?? ($item['reason'] ?? ''));
    }
}
if ($certs['missing'] !== []) {
    printf("\n=== Missing / unmapped certificates ===\n");
    foreach ($certs['missing'] as $item) {
        printf("  %s stored=%s source=%s\n", $item['label'] ?? '', $item['stored'] ?? '', $item['sourcePath'] ?? ($item['reason'] ?? ''));
    }
}
if ($conflicts !== []) {
    printf("\n=== Conflicts ===\n");
    foreach ($conflicts as $conflict) {
        printf("  dest=%s\n    a=%s\n    b=%s\n", $conflict['dest'], $conflict['a'], $conflict['b']);
    }
}

$copied = 0;
$skipped = 0;
if ($apply) {
    if ($conflicts !== []) {
        fwrite(STDERR, "Conflicts present. Stopping without copies.\n");
        exit(1);
    }
    if ($nodes['missing'] !== [] || $certs['missing'] !== []) {
        fwrite(STDERR, "Missing source files. Stopping without copies.\n");
        exit(1);
    }
    foreach ($uniqueByDest as $item) {
        $destDir = dirname($item['destPath']);
        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            fwrite(STDERR, "Cannot create {$destDir}\n");
            exit(1);
        }
        if (is_file($item['destPath']) && hash_file('sha256', $item['destPath']) === hash_file('sha256', $item['sourcePath'])) {
            ++$skipped;

            continue;
        }
        if (!copy($item['sourcePath'], $item['destPath'])) {
            fwrite(STDERR, "Copy failed: {$item['sourcePath']} -> {$item['destPath']}\n");
            exit(1);
        }
        ++$copied;
    }
}

printf("\n=== Result ===\n");
printf("sqlImageUrlUpdates=0\n");
printf("imageMediaPublicIdWrites=0\n");
printf("uniqueFilesToCopy=%d\n", count($uniqueByDest));
printf("copied=%d\n", $copied);
printf("unchanged=%d\n", $skipped);
printf("productionWrites=0\n");
printf("legacyWordpressWrites=0\n");
printf("legacyFilesystemWrites=0\n");

if (!$apply) {
    printf("DRY-RUN complete. Re-run with --apply to copy files into public/media.\n");
}

exit(($nodes['missing'] !== [] || $certs['missing'] !== [] || $conflicts !== []) ? 1 : 0);
