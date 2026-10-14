<?php

declare(strict_types=1);

namespace TriCab\Api\Commerce\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Core\Commerce\Application\DTO\CartLineMedia;
use TriCab\Core\Commerce\Application\Service\CartLineMediaPicker;
use TriCab\Core\Commerce\Contract\CartLineMediaResolver;

/**
 * One batched ProductImages lookup for all cart-line variants. No per-line media query.
 */
final class AzureSqlCartLineMediaResolver implements CartLineMediaResolver
{
    public function __construct(
        private readonly SqlConnection $connection,
        private readonly ?ProductDevMediaUrlResolver $devUrls = null,
    ) {
    }

    public function resolveForVariantPublicIds(array $variantPublicIds): array
    {
        $requested = [];
        foreach ($variantPublicIds as $id) {
            $trimmed = trim($id);
            if ($trimmed !== '') {
                $requested[strtoupper($trimmed)] = $trimmed;
            }
        }
        if ($requested === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        $index = 0;
        foreach ($requested as $original) {
            $key = ':variant' . $index;
            $placeholders[] = $key;
            $params[$key] = $original;
            $index++;
        }
        $inList = implode(', ', $placeholders);

        $rows = $this->connection->fetchAll(
            <<<SQL
/*q:cart.line_media*/
SELECT
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    CONVERT(NVARCHAR(36), img.[PublicId]) AS [MediaPublicId],
    img.[PublicUrl],
    img.[ThumbnailPublicUrl],
    img.[StorageContainer],
    img.[StoragePath],
    img.[IsPrimary],
    img.[ImageRoleCode],
    img.[DisplayOrder],
    CASE WHEN EXISTS (
        SELECT 1
        FROM [product].[ProductImageRoleAssignments] roleAssign
        WHERE roleAssign.[ProductImagesId] = img.[ProductImagesId]
          AND roleAssign.[RoleCode] = N'product-thumbnail'
    ) THEN 1 ELSE 0 END AS [HasProductThumbnailRole],
    CASE WHEN img.[ProductVariantId] = pv.[ProductVariantId] THEN 1 ELSE 0 END AS [IsVariantSpecific]
FROM [product].[ProductVariants] pv
INNER JOIN [product].[Products] p ON p.[ProductId] = pv.[ProductId]
LEFT JOIN [product].[ProductImages] img
    ON img.[ProductId] = p.[ProductId]
   AND img.[DeletedAtUtc] IS NULL
   AND img.[IsActive] = 1
   AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] IN (N'active', N'uploaded'))
   AND (
        img.[ScanStatusCode] IS NULL
        OR img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
   )
   AND (img.[ProductVariantId] IS NULL OR img.[ProductVariantId] = pv.[ProductVariantId])
WHERE pv.[PublicId] IN ({$inList})
SQL,
            $params,
        );

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'variantPublicId' => AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? ''),
                'productPublicId' => AzureSqlValueMapper::nullableString($row['ProductPublicId'] ?? null),
                'mediaPublicId' => AzureSqlValueMapper::nullableString($row['MediaPublicId'] ?? null),
                'publicUrl' => AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
                'thumbnailPublicUrl' => AzureSqlValueMapper::nullableString($row['ThumbnailPublicUrl'] ?? null),
                'storageContainer' => AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
                'storagePath' => AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
                'isPrimary' => AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
                'imageRoleCode' => AzureSqlValueMapper::stringValue($row['ImageRoleCode'] ?? ''),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                'hasProductThumbnailRole' => AzureSqlValueMapper::boolValue($row['HasProductThumbnailRole'] ?? false),
                'isVariantSpecific' => AzureSqlValueMapper::boolValue($row['IsVariantSpecific'] ?? false),
            ];
        }

        $picked = CartLineMediaPicker::pick($candidates);
        $out = [];
        foreach ($requested as $normalized => $original) {
            $media = $picked[$original] ?? null;
            if ($media === null) {
                foreach ($picked as $key => $candidate) {
                    if (strtoupper($key) === $normalized) {
                        $media = $candidate;
                        break;
                    }
                }
            }
            if ($media === null) {
                continue;
            }
            $out[$original] = $this->withBrowserUrl($media);
        }

        return $out;
    }

    private function withBrowserUrl(CartLineMedia $media): CartLineMedia
    {
        $imageUrl = $media->imageUrl;
        if ($this->devUrls !== null && $imageUrl !== null && $media->mediaPublicId !== null) {
            $resolved = $this->devUrls->resolvePublicUrl(
                $imageUrl,
                $media->mediaPublicId,
                $media->storageContainer,
                $media->storagePath,
            );
            if ($resolved !== null && $resolved !== '') {
                $imageUrl = $resolved;
            }
        }

        return new CartLineMedia(
            productPublicId: $media->productPublicId,
            imageUrl: $imageUrl,
            mediaPublicId: $media->mediaPublicId,
            storageContainer: $media->storageContainer,
            storagePath: $media->storagePath,
        );
    }
}
