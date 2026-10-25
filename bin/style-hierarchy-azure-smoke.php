<?php

declare(strict_types=1);

/**
 * DEVELOPMENT Azure smoke: BV hierarchy + spec inheritance + style media upload.
 * Does not write WordPress or legacy tc MySQL. Does not touch production.
 *
 * Usage (from tricab-api):
 *   php bin/style-hierarchy-azure-smoke.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductStyleCommand;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\CompleteStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\InitiateStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\MakeLocalCopyStyleResourceCommand;
use TriCab\Core\Product\Application\Command\ProductStyleStatusCommand;
use TriCab\Core\Product\Application\Command\ReplaceStyleSpecificationValuesCommand;
use TriCab\Core\Product\Application\Command\ReorderProductStylesCommand;
use TriCab\Core\Product\Application\Command\UpdateStyleInheritanceCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductUploadSessionResult;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductStyleConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductStyleInUse;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\CompleteStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\InitiateStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\MakeLocalCopyStyleResourceService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ResolveEffectiveStyleResourcesService;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$settings = Environment::settings(dirname(__DIR__));
$report = [
    'productionWrites' => 0,
    'legacyWordpressWrites' => 0,
    'legacyTcMysqlWrites' => 0,
    'developmentAzureSqlWrites' => 0,
    'developmentBlobWrites' => 0,
    'forbiddenWriteAttempts' => 0,
];

printf("APP_ENV=%s isProduction=%s USER_REPOSITORY=%s DB_HOST=%s DB_DATABASE=%s\n",
    $settings->appEnv,
    $settings->isProduction() ? 'YES' : 'NO',
    $settings->userRepository,
    $settings->dbHost,
    $settings->dbDatabase,
);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var ManageProductStyleService $manage */
$manage = $container->get(ManageProductStyleService::class);
/** @var ResolveEffectiveStyleResourcesService $effective */
$effective = $container->get(ResolveEffectiveStyleResourcesService::class);
/** @var AssignProductStyleService $assign */
$assign = $container->get(AssignProductStyleService::class);
/** @var InitiateStyleMediaUploadService $initiate */
$initiate = $container->get(InitiateStyleMediaUploadService::class);
/** @var CompleteStyleMediaUploadService $complete */
$complete = $container->get(CompleteStyleMediaUploadService::class);
/** @var ProductStyleRepository $styles */
$styles = $container->get(ProductStyleRepository::class);
/** @var MakeLocalCopyStyleResourceService $localCopy */
$localCopy = $container->get(MakeLocalCopyStyleResourceService::class);
/** @var ProductBlobStore $blobs */
$blobs = $container->get(ProductBlobStore::class);

$schema = $db->fetchAll(
    <<<'SQL'
SELECT t.name AS TableName, c.name AS ColumnName
FROM sys.tables t
INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
INNER JOIN sys.columns c ON c.object_id = t.object_id
WHERE s.name = N'product'
  AND t.name IN (N'ProductStyles', N'ProductStyleAssignments', N'ProductStyleSpecificationValues', N'ProductStyleMediaAssets')
ORDER BY t.name, c.column_id
SQL
);
echo "schema columns=" . count($schema) . "\n";

$userRows = $db->fetchAll(
    'SELECT TOP (1) [UserId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [EmailAddress] AS [Email] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
    [':email' => 'thilanh@tricab.com'],
);
if ($userRows === []) {
    fwrite(STDERR, "Could not resolve thilanh@tricab.com in identity.Users.\n");
    exit(1);
}
$actor = new ActorContext(
    userId: (int) $userRows[0]['UserId'],
    publicId: (string) $userRows[0]['PublicId'],
    emailAddress: (string) $userRows[0]['Email'],
);

$ensure = static function (string $code, string $name, ?string $parentPublicId) use ($manage, $actor, $styles, &$report) {
    $existing = $styles->findByStyleCode($code, false);
    if ($existing !== null) {
        echo "reuse {$code} {$existing->publicId}\n";
        return $existing;
    }
    $created = $manage->create($actor, new UpsertProductStyleCommand($code, $name, $parentPublicId));
    $report['developmentAzureSqlWrites']++;
    echo "created {$code} {$created->publicId}\n";
    return $created;
};

$bv = $ensure('BV', 'BV Base', null);
$bva = $ensure('BVA', 'BV Armoured', $bv->publicId);
$bvc = $ensure('BVC', 'BV Control', $bv->publicId);
$bvm = $ensure('BVM', 'BV Mining', $bv->publicId);
$bvp = $ensure('BVP', 'BV Power', $bv->publicId);

$tree = $manage->tree();
$bvTree = null;
foreach ($tree as $node) {
    if ($node->styleCode === 'BV') {
        $bvTree = $node;
        break;
    }
}
if ($bvTree === null || count($bvTree->children) < 4) {
    fwrite(STDERR, "BV tree children unexpected.\n");
    exit(1);
}
echo "tree OK children=" . count($bvTree->children) . "\n";

$bvaFresh = $manage->get($bva->publicId);
selfAssert(count($bvaFresh->ancestors) >= 1, 'BVA ancestors');
selfAssert($bvaFresh->parentStyleCode === 'BV', 'BVA parent');

try {
    $manage->changeParent($actor, new ChangeProductStyleParentCommand($bv->publicId, $bv->publicId, $manage->get($bv->publicId)->rowVersion));
    fwrite(STDERR, "self-parent should have been rejected\n");
    exit(1);
} catch (InvalidProductStyleHierarchy $e) {
    echo "self-parent rejected OK http_maps_to=INVALID_PRODUCT_STYLE_HIERARCHY\n";
    echo "self-parent message=" . $e->getMessage() . "\n";
}

try {
    $manage->changeParent($actor, new ChangeProductStyleParentCommand($bv->publicId, $bva->publicId, $manage->get($bv->publicId)->rowVersion));
    fwrite(STDERR, "BV→BVA→BV cycle should have been rejected\n");
    exit(1);
} catch (InvalidProductStyleHierarchy $e) {
    echo "direct cycle rejected OK http_maps_to=INVALID_PRODUCT_STYLE_HIERARCHY\n";
    echo "direct cycle message=" . $e->getMessage() . "\n";
}

$nested = $ensure('BVA-X', 'BVA nested smoke', $bva->publicId);
try {
    $manage->changeParent($actor, new ChangeProductStyleParentCommand($bv->publicId, $nested->publicId, $manage->get($bv->publicId)->rowVersion));
    fwrite(STDERR, "deep cycle should have been rejected\n");
    exit(1);
} catch (InvalidProductStyleHierarchy $e) {
    echo "deep cycle rejected OK http_maps_to=INVALID_PRODUCT_STYLE_HIERARCHY\n";
    echo "deep cycle message=" . $e->getMessage() . "\n";
}

try {
    $manage->archive($actor, new ProductStyleStatusCommand($bv->publicId, $manage->get($bv->publicId)->rowVersion));
    fwrite(STDERR, "archive BV with children should have been rejected\n");
    exit(1);
} catch (ProductStyleInUse) {
    echo "archive BV blocked OK\n";
}

$nestedFresh = $manage->get($nested->publicId);
$deactivated = $manage->deactivate($actor, new ProductStyleStatusCommand($nested->publicId, $nestedFresh->rowVersion));
$report['developmentAzureSqlWrites']++;
selfAssert($deactivated->isActive === false, 'BVA-X deactivated');
echo "deactivate BVA-X OK\n";
$activated = $manage->activate($actor, new ProductStyleStatusCommand($nested->publicId, $deactivated->rowVersion));
$report['developmentAzureSqlWrites']++;
selfAssert($activated->isActive === true, 'BVA-X activated');
echo "activate BVA-X OK\n";

try {
    $manage->update($actor, new UpsertProductStyleCommand(
        styleCode: 'BV',
        displayName: 'stale',
        expectedRowVersion: 'AAAAAAAAAAA=',
        stylePublicId: $bv->publicId,
    ));
    fwrite(STDERR, "stale rowversion should have been rejected\n");
    exit(1);
} catch (ProductStyleConcurrencyConflict) {
    echo "stale RowVersion rejected OK\n";
}

$manage->reorder($actor, new ReorderProductStylesCommand(
    [$bva->publicId, $bvc->publicId, $bvm->publicId, $bvp->publicId],
    $bv->publicId,
));
$report['developmentAzureSqlWrites']++;
echo "reorder OK\n";

$defs = $db->fetchAll(
    <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [DefinitionCode], [DefinitionName], [DataTypeCode], [ScopeCode]
FROM [product].[SpecificationDefinitions]
WHERE [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
  AND LOWER([DataTypeCode]) = N'text'
  AND LOWER([ScopeCode]) IN (N'product', N'both', N'all')
ORDER BY [DefinitionName]
SQL
);
$byName = [];
foreach ($defs as $row) {
    $byName[strtolower((string) $row['DefinitionName'])] = $row;
    $byName[strtolower((string) $row['DefinitionCode'])] = $row;
}
$picked = [];
foreach (['voltage', 'temperature', 'insulation'] as $want) {
    if (isset($byName[$want])) {
        $candidate = $byName[$want];
        $ids = array_column($picked, 'PublicId');
        if (!in_array($candidate['PublicId'], $ids, true)) {
            $picked[] = $candidate;
        }
    }
}
foreach ($defs as $row) {
    if (count($picked) >= 3) {
        break;
    }
    $ids = array_column($picked, 'PublicId');
    if (!in_array($row['PublicId'], $ids, true)) {
        $picked[] = $row;
    }
}
$voltage = $picked[0] ?? null;
$temp = $picked[1] ?? null;
$insulation = $picked[2] ?? null;
if (is_array($voltage) && is_array($temp) && is_array($insulation)) {
    $manage->replaceSpecifications($actor, new ReplaceStyleSpecificationValuesCommand(
        $bv->publicId,
        [
            ['definitionPublicId' => $voltage['PublicId'], 'valueText' => '0.6/1 kV'],
            ['definitionPublicId' => $temp['PublicId'], 'valueText' => '90°C'],
            ['definitionPublicId' => $insulation['PublicId'], 'valueText' => 'PVC'],
        ],
        $manage->get($bv->publicId)->rowVersion,
    ));
    $manage->updateInheritance($actor, new UpdateStyleInheritanceCommand(
        $bva->publicId,
        [StyleResourceType::SPECIFICATIONS => StyleInheritanceMode::INHERIT_AND_EXTEND],
        $manage->get($bva->publicId)->rowVersion,
    ));
    $manage->replaceSpecifications($actor, new ReplaceStyleSpecificationValuesCommand(
        $bva->publicId,
        [
            ['definitionPublicId' => $temp['PublicId'], 'valueText' => '105°C'],
        ],
        $manage->get($bva->publicId)->rowVersion,
    ));
    $report['developmentAzureSqlWrites'] += 3;
    $indexed = [];
    foreach ($effective->specificationsForStyle($bva->publicId) as $item) {
        $indexed[$item->definitionCode] = $item;
    }
    echo "spec voltage=" . ($indexed[$voltage['DefinitionCode']]->displayValue ?? '') . " inherited=" . (($indexed[$voltage['DefinitionCode']]->provenance->inherited ?? false) ? 'yes' : 'no') . "\n";
    echo "spec temp=" . ($indexed[$temp['DefinitionCode']]->displayValue ?? '') . "\n";
    $manage->replaceSpecifications($actor, new ReplaceStyleSpecificationValuesCommand(
        $bv->publicId,
        [
            ['definitionPublicId' => $voltage['PublicId'], 'valueText' => '1.8/3 kV'],
            ['definitionPublicId' => $temp['PublicId'], 'valueText' => '90°C'],
            ['definitionPublicId' => $insulation['PublicId'], 'valueText' => 'PVC'],
        ],
        $manage->get($bv->publicId)->rowVersion,
    ));
    $report['developmentAzureSqlWrites']++;
    $after = [];
    foreach ($effective->specificationsForStyle($bva->publicId) as $item) {
        $after[$item->definitionCode] = $item->displayValue;
    }
    echo "after parent voltage change BVA voltage=" . ($after[$voltage['DefinitionCode']] ?? '') . "\n";
} else {
    echo "spec definitions not found — spec inheritance NOT RUN\n";
}

$products = $db->fetchAll('SELECT TOP (1) CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [ProductName] FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL ORDER BY [UpdatedAtUtc] DESC');
if ($products !== []) {
    $assignment = $assign->execute($actor, new AssignProductStyleCommand((string) $products[0]['PublicId'], $bva->publicId));
    $report['developmentAzureSqlWrites']++;
    echo "assigned product {$products[0]['PublicId']} to {$assignment?->styleCode}\n";
} else {
    echo "no products — assignment NOT RUN\n";
}

$manage->updateInheritance($actor, new UpdateStyleInheritanceCommand(
    $bva->publicId,
    [StyleResourceType::IMAGES => StyleInheritanceMode::INHERIT],
    $manage->get($bva->publicId)->rowVersion,
));
$report['developmentAzureSqlWrites']++;
echo "BVA images INHERIT OK\n";

$bytes = str_repeat('J', 128);
$session = $initiate->execute($actor, new InitiateStyleMediaUploadCommand(
    stylePublicId: $bv->publicId,
    resourceType: StyleResourceType::IMAGES,
    originalFileName: 'hero-bv.jpg',
    contentType: 'image/jpeg',
    byteSize: strlen($bytes),
    title: 'hero-bv.jpg',
    roleCode: 'hero',
    isPrimary: true,
    storageContainer: $settings->blobContainerProducts,
    uploadExpiresInSeconds: $settings->blobUploadSasTtlSeconds,
));
$report['developmentAzureSqlWrites']++;
selfAssert($session->uploadUrl !== '', 'write SAS issued');
selfAssert(!str_contains(strtolower($session->uploadUrl), 'accountkey'), 'SAS URL must not contain AccountKey');
selfAssert($session->storagePath !== '', 'storage path persisted');
echo "write SAS issued method={$session->httpMethod} path={$session->storagePath}\n";
putViaSas($session, $bytes);
$report['developmentBlobWrites']++;
echo "SAS PUT OK\n";
$complete->execute($actor, new CompleteStyleMediaUploadCommand($bv->publicId, $session->entityPublicId));
$report['developmentAzureSqlWrites']++;
echo "HEAD complete OK\n";
$read = $blobs->issueReadDownload($session->storageContainer, $session->storagePath, 120);
selfAssert(!str_contains(strtolower($read->uploadUrl), 'accountkey'), 'read SAS must not contain AccountKey');
getViaSas($read);
echo "signed read GET OK\n";
$images = $effective->assetsForStyle($bv->publicId, StyleResourceType::images());
echo "BV images=" . count($images) . " signedRead=" . (($images[0]->publicUrl ?? '') !== '' ? 'yes' : 'no') . "\n";
$inherited = $effective->assetsForStyle($bva->publicId, StyleResourceType::images());
echo "BVA inherited images=" . count($inherited) . " source=" . ($inherited[0]->provenance->sourceStyleCode ?? 'none') . " inherited=" . (($inherited[0]->provenance->inherited ?? false) ? 'yes' : 'no') . "\n";

$copied = $localCopy->execute($actor, new MakeLocalCopyStyleResourceCommand(
    $bva->publicId,
    StyleResourceType::IMAGES,
    $manage->get($bva->publicId)->rowVersion,
    true,
));
$report['developmentAzureSqlWrites']++;
selfAssert($copied->inheritance->toMap()[StyleResourceType::IMAGES] === StyleInheritanceMode::LOCAL, 'BVA images LOCAL after copy');
$localAssets = $effective->assetsForStyle($bva->publicId, StyleResourceType::images());
selfAssert($localAssets !== [], 'BVA local copy has images');
selfAssert(($localAssets[0]->storagePath ?? null) === $session->storagePath, 'local copy reuses blob path');
$refs = $styles->countActiveStoragePathReferences($session->storagePath);
echo "make local copy OK refs={$refs} path={$session->storagePath}\n";

$manage->updateInheritance($actor, new UpdateStyleInheritanceCommand(
    $bva->publicId,
    [StyleResourceType::IMAGES => StyleInheritanceMode::INHERIT_AND_EXTEND],
    $manage->get($bva->publicId)->rowVersion,
));
$report['developmentAzureSqlWrites']++;
echo "BVA images INHERIT_AND_EXTEND OK\n";

echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
echo "STYLE HIERARCHY AZURE SMOKE COMPLETE\n";

function selfAssert(bool $ok, string $label): void
{
    if (!$ok) {
        fwrite(STDERR, "assertion failed: {$label}\n");
        exit(1);
    }
}

function putViaSas(ProductUploadSessionResult $session, string $bytes): void
{
    $handle = curl_init($session->uploadUrl);
    if ($handle === false) {
        fwrite(STDERR, "SAS PUT curl_init failed\n");
        exit(1);
    }
    $headers = [];
    foreach ($session->requiredHeaders as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $session->httpMethod !== '' ? $session->httpMethod : 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "SAS PUT failed HTTP {$status} {$error} " . (is_string($body) ? substr($body, 0, 300) : '') . "\n");
        exit(1);
    }
}

function getViaSas(\TriCab\Core\Product\Application\DTO\ProductBlobWriteTicket $ticket): void
{
    $handle = curl_init($ticket->uploadUrl);
    if ($handle === false) {
        fwrite(STDERR, "read SAS curl_init failed\n");
        exit(1);
    }
    $headers = [];
    foreach ($ticket->requiredHeaders as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    if ($status < 200 || $status >= 300 || !is_string($body) || $body === '') {
        fwrite(STDERR, "signed read GET failed HTTP {$status} {$error}\n");
        exit(1);
    }
}
