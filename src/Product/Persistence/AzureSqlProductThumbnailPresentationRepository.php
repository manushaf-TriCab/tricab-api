<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductThumbnailPresentationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

final class AzureSqlProductThumbnailPresentationRepository implements ProductThumbnailPresentationRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findThumbnailBackgroundColor(ProductPublicId $productPublicId): ?string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT p.[ThumbnailBackgroundColor]
FROM [product].[Products] p
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $productPublicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ThumbnailBackgroundColor::tryFromString(
            AzureSqlValueMapper::nullableString($rows[0]['ThumbnailBackgroundColor'] ?? null),
        )?->toString();
    }

    public function updateThumbnailBackgroundColor(
        ProductPublicId $productPublicId,
        ?ThumbnailBackgroundColor $color,
        UserId $actorUserId,
    ): void {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[Products]
SET
    [ThumbnailBackgroundColor] = :color,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':color' => $color?->toString(),
                ':updatedByUserId' => $actorUserId->toInt(),
                ':publicId' => $productPublicId->toString(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($productPublicId->toString());
        }
    }
}
