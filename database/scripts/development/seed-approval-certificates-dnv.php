<?php

declare(strict_types=1);

/**
 * Seed reusable DNV approval certificate master + assign Pending to ALI-FLEX
 * marine products BV / DV / PJ / UP.
 *
 * Does not modify descriptions, colours, media, pricing, or BVA approvals.
 * DEVELOPMENT / non-production only.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__, 3);
$settings = Environment::settings($root);

echo "APP_ENV={$settings->appEnv}\n";
echo "DB_HOST={$settings->dbHost}\n";
echo "DB_DATABASE={$settings->dbDatabase}\n";

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $c->get(SqlConnection::class);
$actorUserId = 1;
$dnvImageUrl = '/certificates/DNV_logo_RGB_tcm8-197625.webp';

$imageCol = $db->fetchAll(
    "SELECT COL_LENGTH(N'product.SpecificationOptions', N'ImageUrl') AS [Len]",
);
if ((int) ($imageCol[0]['Len'] ?? 0) === 0) {
    fwrite(STDERR, "STOP: SpecificationOptions.ImageUrl missing. Apply alter-approval-certificate-columns.sql first.\n");
    exit(1);
}
$statusCol = $db->fetchAll(
    "SELECT COL_LENGTH(N'product.ProductSpecificationOptionValues', N'StatusCode') AS [Len]",
);
if ((int) ($statusCol[0]['Len'] ?? 0) === 0) {
    fwrite(STDERR, "STOP: ProductSpecificationOptionValues.StatusCode missing. Apply alter-approval-certificate-columns.sql first.\n");
    exit(1);
}

$approvals = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationDefinitionId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[SpecificationDefinitions]
WHERE [DefinitionCode] = N'APPROVALS'
  AND [DeletedAtUtc] IS NULL
SQL,
);
if (count($approvals) !== 1) {
    fwrite(STDERR, "STOP: APPROVALS definition missing or duplicated\n");
    exit(1);
}
$approvalsDefinitionId = (int) $approvals[0]['SpecificationDefinitionId'];
$approvalsPublicId = (string) $approvals[0]['PublicId'];
echo "APPROVALS publicId={$approvalsPublicId}\n";

$note = $db->fetchAll(
    <<<'SQL'
SELECT [SpecificationDefinitionId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[SpecificationDefinitions]
WHERE [DefinitionCode] = N'APPROVAL-NOTE'
  AND [DeletedAtUtc] IS NULL
SQL,
);
$noteDefinitionId = $note === [] ? null : (int) $note[0]['SpecificationDefinitionId'];

$existingDnv = $db->fetchAll(
    <<<'SQL'
SELECT
    [SpecificationOptionId],
    CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId],
    [OptionLabel],
    [OptionValue],
    [ImageUrl],
    [IsActive]
FROM [product].[SpecificationOptions]
WHERE [SpecificationDefinitionId] = :definitionId
  AND [OptionCode] = N'DNV'
  AND [DeletedAtUtc] IS NULL
SQL,
    [':definitionId' => $approvalsDefinitionId],
);

$db->beginTransaction();
try {
    if ($existingDnv === []) {
        $inserted = $db->fetchAll(
            <<<'SQL'
INSERT INTO [product].[SpecificationOptions]
    ([SpecificationDefinitionId], [OptionCode], [OptionLabel], [OptionValue], [ImageUrl], [DisplayOrder], [IsActive], [CreatedByUserId], [UpdatedByUserId])
OUTPUT
    INSERTED.[SpecificationOptionId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES
    (:definitionId, N'DNV', N'DNV', N'DNV', :imageUrl, 5, 1, :createdBy, :updatedBy)
SQL,
            [
                ':definitionId' => $approvalsDefinitionId,
                ':imageUrl' => $dnvImageUrl,
                ':createdBy' => $actorUserId,
                ':updatedBy' => $actorUserId,
            ],
        );
        $dnvOptionId = (int) $inserted[0]['SpecificationOptionId'];
        $dnvPublicId = (string) $inserted[0]['PublicId'];
        echo "Created DNV option publicId={$dnvPublicId}\n";
    } else {
        $dnvOptionId = (int) $existingDnv[0]['SpecificationOptionId'];
        $dnvPublicId = (string) $existingDnv[0]['PublicId'];
        $db->execute(
            <<<'SQL'
UPDATE [product].[SpecificationOptions]
SET [OptionLabel] = N'DNV',
    [OptionValue] = N'DNV',
    [ImageUrl] = :imageUrl,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actor
WHERE [SpecificationOptionId] = :optionId
SQL,
            [
                ':imageUrl' => $dnvImageUrl,
                ':actor' => $actorUserId,
                ':optionId' => $dnvOptionId,
            ],
        );
        echo "Updated DNV option publicId={$dnvPublicId}\n";
    }

    $codes = ['BV', 'DV', 'PJ', 'UP'];
    foreach ($codes as $code) {
        $products = $db->fetchAll(
            <<<'SQL'
SELECT [ProductId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [ProductName]
FROM [product].[Products]
WHERE [InternalCode] = :code
  AND [DeletedAtUtc] IS NULL
SQL,
            [':code' => $code],
        );
        if (count($products) !== 1) {
            throw new RuntimeException("{$code} resolved to " . count($products) . ' products');
        }
        $productId = (int) $products[0]['ProductId'];
        echo "{$code} {$products[0]['ProductName']} {$products[0]['PublicId']}\n";

        $valueRows = $db->fetchAll(
            <<<'SQL'
SELECT [ProductSpecificationValueId]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':productId' => $productId,
                ':definitionId' => $approvalsDefinitionId,
            ],
        );
        if ($valueRows === []) {
            $created = $db->fetchAll(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationValues]
    ([ProductId], [SpecificationDefinitionId], [CreatedByUserId], [UpdatedByUserId])
OUTPUT INSERTED.[ProductSpecificationValueId]
VALUES (:productId, :definitionId, :createdBy, :updatedBy)
SQL,
                [
                    ':productId' => $productId,
                    ':definitionId' => $approvalsDefinitionId,
                    ':createdBy' => $actorUserId,
                    ':updatedBy' => $actorUserId,
                ],
            );
            $valueId = (int) $created[0]['ProductSpecificationValueId'];
        } else {
            $valueId = (int) $valueRows[0]['ProductSpecificationValueId'];
        }

        $junction = $db->fetchAll(
            <<<'SQL'
SELECT [ProductSpecificationOptionValueId], [StatusCode]
FROM [product].[ProductSpecificationOptionValues]
WHERE [ProductSpecificationValueId] = :valueId
  AND [SpecificationOptionId] = :optionId
SQL,
            [
                ':valueId' => $valueId,
                ':optionId' => $dnvOptionId,
            ],
        );
        if ($junction === []) {
            $db->execute(
                <<<'SQL'
INSERT INTO [product].[ProductSpecificationOptionValues]
    ([ProductSpecificationValueId], [SpecificationOptionId], [StatusCode])
VALUES (:valueId, :optionId, N'pending')
SQL,
                [
                    ':valueId' => $valueId,
                    ':optionId' => $dnvOptionId,
                ],
            );
            echo "  assigned DNV pending\n";
        } else {
            $db->execute(
                <<<'SQL'
UPDATE [product].[ProductSpecificationOptionValues]
SET [StatusCode] = N'pending'
WHERE [ProductSpecificationOptionValueId] = :id
SQL,
                [':id' => (int) $junction[0]['ProductSpecificationOptionValueId']],
            );
            echo "  updated DNV pending\n";
        }

        if ($noteDefinitionId !== null) {
            $notes = $db->fetchAll(
                <<<'SQL'
SELECT [ProductSpecificationValueId], [ValueLongText], [ValueText]
FROM [product].[ProductSpecificationValues]
WHERE [ProductId] = :productId
  AND [SpecificationDefinitionId] = :definitionId
  AND [DeletedAtUtc] IS NULL
SQL,
                [
                    ':productId' => $productId,
                    ':definitionId' => $noteDefinitionId,
                ],
            );
            foreach ($notes as $noteRow) {
                $text = trim((string) ($noteRow['ValueLongText'] ?? $noteRow['ValueText'] ?? ''));
                if (strcasecmp($text, 'DNV Certificate Pending') !== 0) {
                    echo "  kept APPROVAL-NOTE (not duplicate): {$text}\n";
                    continue;
                }
                $db->execute(
                    <<<'SQL'
UPDATE [product].[ProductSpecificationValues]
SET [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedBy,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedBy
WHERE [ProductSpecificationValueId] = :valueId
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':deletedBy' => $actorUserId,
                        ':updatedBy' => $actorUserId,
                        ':valueId' => (int) $noteRow['ProductSpecificationValueId'],
                    ],
                );
                echo "  cleared duplicate APPROVAL-NOTE\n";
            }
        }
    }

    $bva = $db->fetchAll(
        <<<'SQL'
SELECT
    o.[OptionCode],
    o.[OptionLabel],
    ISNULL(pov.[StatusCode], N'approved') AS [StatusCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductSpecificationValues] psv
    ON psv.[ProductId] = p.[ProductId] AND psv.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductSpecificationOptionValues] pov
    ON pov.[ProductSpecificationValueId] = psv.[ProductSpecificationValueId]
INNER JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = pov.[SpecificationOptionId]
WHERE p.[InternalCode] = N'BVA'
  AND p.[DeletedAtUtc] IS NULL
  AND psv.[SpecificationDefinitionId] = :definitionId
ORDER BY o.[DisplayOrder], o.[OptionLabel]
SQL,
        [':definitionId' => $approvalsDefinitionId],
    );
    echo "BVA approvals:\n";
    foreach ($bva as $row) {
        echo sprintf("  %s %s status=%s\n", $row['OptionCode'], $row['OptionLabel'], $row['StatusCode']);
    }

    $db->commit();
} catch (Throwable $exception) {
    $db->rollBack();
    fwrite(STDERR, 'FAILED: ' . $exception->getMessage() . "\n");
    $previous = $exception->getPrevious();
    while ($previous instanceof Throwable) {
        fwrite(STDERR, 'CAUSE: ' . $previous->getMessage() . "\n");
        $previous = $previous->getPrevious();
    }
    exit(1);
}

echo "DNV publicId={$dnvPublicId}\n";
echo "DNV image={$dnvImageUrl}\n";
echo "DONE\n";
