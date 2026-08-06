<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\DuplicateProductSlug;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

/**
 * Azure SQL repository for product SEO metadata and slug assignments.
 * Tables: product.ProductSlugs, product.ProductSEO, product.Regions.
 */
final class AzureSqlProductSeoRepository implements ProductSeoRepository
{
    use AzureSqlTransaction;
    use UniqueConstraintTranslator;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findPrimarySlug(ProductPublicId $productPublicId): ?ProductSlug
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT ps.[Slug]
FROM [product].[ProductSlugs] ps
INNER JOIN [product].[Products] p ON p.[ProductId] = ps.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND ps.[IsPrimary] = 1
  AND ps.[DeletedAtUtc] IS NULL
  AND ps.[RegionId] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ProductSlug::fromString(AzureSqlValueMapper::stringValue($rows[0]['Slug'] ?? ''));
    }

    public function findSeo(ProductPublicId $productPublicId, ?string $regionPublicId = null): ?array
    {
        $sql = <<<'SQL'
SELECT
    seo.[PageTitle],
    seo.[MetaDescription],
    seo.[CanonicalUrl],
    seo.[OpenGraphTitle],
    seo.[OpenGraphDescription],
    seo.[OpenGraphImageUrl],
    seo.[SearchKeywords],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [RegionPublicId]
FROM [product].[ProductSEO] seo
INNER JOIN [product].[Products] p ON p.[ProductId] = seo.[ProductId]
LEFT JOIN [product].[Regions] r ON r.[RegionId] = seo.[RegionId]
WHERE p.[PublicId] = :productPublicId
  AND seo.[DeletedAtUtc] IS NULL
SQL;
        $params = [':productPublicId' => $productPublicId->toString()];
        if ($regionPublicId === null) {
            $sql .= ' AND seo.[RegionId] IS NULL';
        } else {
            $sql .= ' AND r.[PublicId] = :regionPublicId';
            $params[':regionPublicId'] = $regionPublicId;
        }

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'pageTitle' => AzureSqlValueMapper::nullableString($row['PageTitle'] ?? null),
            'metaDescription' => AzureSqlValueMapper::nullableString($row['MetaDescription'] ?? null),
            'canonicalUrl' => AzureSqlValueMapper::nullableString($row['CanonicalUrl'] ?? null),
            'openGraphTitle' => AzureSqlValueMapper::nullableString($row['OpenGraphTitle'] ?? null),
            'openGraphDescription' => AzureSqlValueMapper::nullableString($row['OpenGraphDescription'] ?? null),
            'openGraphImageUrl' => AzureSqlValueMapper::nullableString($row['OpenGraphImageUrl'] ?? null),
            'searchKeywords' => AzureSqlValueMapper::nullableString($row['SearchKeywords'] ?? null),
            'regionPublicId' => AzureSqlValueMapper::nullableString($row['RegionPublicId'] ?? null),
        ];
    }

    public function upsertSlug(
        ProductPublicId $productPublicId,
        ProductSlug $slug,
        bool $isPrimary,
        ?string $regionPublicId,
        UserId $actorUserId,
    ): void {
        $this->translatingUniqueConstraintViolation(
            fn () => $this->doUpsertSlug($productPublicId, $slug, $isPrimary, $regionPublicId, $actorUserId),
            fn () => DuplicateProductSlug::forSlug($slug->toString()),
        );
    }

    private function doUpsertSlug(
        ProductPublicId $productPublicId,
        ProductSlug $slug,
        bool $isPrimary,
        ?string $regionPublicId,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $slug, $isPrimary, $regionPublicId, $actorUserId): void {
                $productId = $this->requireProductId($productPublicId);
                $regionId = $regionPublicId !== null ? $this->requireRegionId($regionPublicId) : null;

                // sqlsrv PDO rejects duplicate named parameters; branch region filters in PHP.
                if ($isPrimary) {
                    if ($regionId === null) {
                        $this->connection->execute(
                            <<<'SQL'
UPDATE ps
SET
    ps.[IsPrimary] = 0,
    ps.[UpdatedAtUtc] = SYSUTCDATETIME(),
    ps.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductSlugs] ps
WHERE ps.[ProductId] = :productId
  AND ps.[DeletedAtUtc] IS NULL
  AND ps.[RegionId] IS NULL
SQL,
                            [
                                ':productId' => $productId,
                                ':updatedByUserId' => $actorUserId->toInt(),
                            ],
                        );
                    } else {
                        $this->connection->execute(
                            <<<'SQL'
UPDATE ps
SET
    ps.[IsPrimary] = 0,
    ps.[UpdatedAtUtc] = SYSUTCDATETIME(),
    ps.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductSlugs] ps
WHERE ps.[ProductId] = :productId
  AND ps.[DeletedAtUtc] IS NULL
  AND ps.[RegionId] = :regionId
SQL,
                            [
                                ':productId' => $productId,
                                ':regionId' => $regionId,
                                ':updatedByUserId' => $actorUserId->toInt(),
                            ],
                        );
                    }
                }

                if ($regionId === null) {
                    $existing = $this->connection->fetchAll(
                        <<<'SQL'
SELECT [ProductSlugId]
FROM [product].[ProductSlugs]
WHERE [ProductId] = :productId
  AND [Slug] = :slug
  AND [DeletedAtUtc] IS NULL
  AND [RegionId] IS NULL
SQL,
                        [
                            ':productId' => $productId,
                            ':slug' => $slug->toString(),
                        ],
                    );
                } else {
                    $existing = $this->connection->fetchAll(
                        <<<'SQL'
SELECT [ProductSlugId]
FROM [product].[ProductSlugs]
WHERE [ProductId] = :productId
  AND [Slug] = :slug
  AND [DeletedAtUtc] IS NULL
  AND [RegionId] = :regionId
SQL,
                        [
                            ':productId' => $productId,
                            ':slug' => $slug->toString(),
                            ':regionId' => $regionId,
                        ],
                    );
                }

                if ($existing === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [product].[ProductSlugs] (
    [ProductId],
    [RegionId],
    [Slug],
    [IsPrimary],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :regionId,
    :slug,
    :isPrimary,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                        [
                            ':productId' => $productId,
                            ':regionId' => $regionId,
                            ':slug' => $slug->toString(),
                            ':isPrimary' => $isPrimary ? 1 : 0,
                            ':createdByUserId' => $actorUserId->toInt(),
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                } else {
                    $this->connection->execute(
                        <<<'SQL'
UPDATE [product].[ProductSlugs]
SET
    [IsPrimary] = :isPrimary,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductSlugId] = :productSlugId
SQL,
                        [
                            ':productSlugId' => AzureSqlValueMapper::intValue($existing[0]['ProductSlugId'] ?? 0),
                            ':isPrimary' => $isPrimary ? 1 : 0,
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                }
            },
        );
    }

    public function upsertSeo(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): void {
                $productId = $this->requireProductId($productPublicId);
                $regionPublicId = $data['regionPublicId'] ?? null;
                $regionId = $regionPublicId !== null ? $this->requireRegionId($regionPublicId) : null;

                if ($regionId === null) {
                    $existing = $this->connection->fetchAll(
                        <<<'SQL'
SELECT [ProductSEOId]
FROM [product].[ProductSEO]
WHERE [ProductId] = :productId
  AND [DeletedAtUtc] IS NULL
  AND [RegionId] IS NULL
SQL,
                        [':productId' => $productId],
                    );
                } else {
                    $existing = $this->connection->fetchAll(
                        <<<'SQL'
SELECT [ProductSEOId]
FROM [product].[ProductSEO]
WHERE [ProductId] = :productId
  AND [DeletedAtUtc] IS NULL
  AND [RegionId] = :regionId
SQL,
                        [
                            ':productId' => $productId,
                            ':regionId' => $regionId,
                        ],
                    );
                }

                $params = [
                    ':pageTitle' => $data['pageTitle'] ?? null,
                    ':metaDescription' => $data['metaDescription'] ?? null,
                    ':canonicalUrl' => $data['canonicalUrl'] ?? null,
                    ':openGraphTitle' => $data['openGraphTitle'] ?? null,
                    ':openGraphDescription' => $data['openGraphDescription'] ?? null,
                    ':openGraphImageUrl' => $data['openGraphImageUrl'] ?? null,
                    ':searchKeywords' => $data['searchKeywords'] ?? null,
                    ':updatedByUserId' => $actorUserId->toInt(),
                ];

                if ($existing === []) {
                    $this->connection->execute(
                        <<<'SQL'
INSERT INTO [product].[ProductSEO] (
    [ProductId],
    [RegionId],
    [PageTitle],
    [MetaDescription],
    [CanonicalUrl],
    [OpenGraphTitle],
    [OpenGraphDescription],
    [OpenGraphImageUrl],
    [SearchKeywords],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :regionId,
    :pageTitle,
    :metaDescription,
    :canonicalUrl,
    :openGraphTitle,
    :openGraphDescription,
    :openGraphImageUrl,
    :searchKeywords,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                        [
                            ':productId' => $productId,
                            ':regionId' => $regionId,
                            ...$params,
                            ':createdByUserId' => $actorUserId->toInt(),
                        ],
                    );
                } else {
                    $this->connection->execute(
                        <<<'SQL'
UPDATE [product].[ProductSEO]
SET
    [PageTitle] = :pageTitle,
    [MetaDescription] = :metaDescription,
    [CanonicalUrl] = :canonicalUrl,
    [OpenGraphTitle] = :openGraphTitle,
    [OpenGraphDescription] = :openGraphDescription,
    [OpenGraphImageUrl] = :openGraphImageUrl,
    [SearchKeywords] = :searchKeywords,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductSEOId] = :productSeoId
SQL,
                        [
                            ':productSeoId' => AzureSqlValueMapper::intValue($existing[0]['ProductSEOId'] ?? 0),
                            ...$params,
                        ],
                    );
                }
            },
        );
    }

    public function createRedirect(
        ProductPublicId $productPublicId,
        string $fromSlug,
        string $toSlug,
        ?string $regionPublicId,
        int $httpStatusCode,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $fromSlug, $toSlug, $regionPublicId, $httpStatusCode, $actorUserId): void {
                $productId = $this->requireProductId($productPublicId);
                $regionId = $regionPublicId !== null ? $this->requireRegionId($regionPublicId) : null;

                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [product].[ProductRedirects] (
    [ProductId],
    [FromSlug],
    [ToSlug],
    [RegionId],
    [HttpStatusCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :fromSlug,
    :toSlug,
    :regionId,
    :httpStatusCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':productId' => $productId,
                        ':fromSlug' => $fromSlug,
                        ':toSlug' => $toSlug,
                        ':regionId' => $regionId,
                        ':httpStatusCode' => $httpStatusCode,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );
            },
        );
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function requireRegionId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [RegionId] FROM [product].[Regions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Region', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['RegionId'] ?? 0);
    }
}
