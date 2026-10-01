<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductPageMediaLayoutRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Reads stay on the historical layout when the settings table has not been
 * installed yet. Saves require the table from alter-product-page-media-layout.sql.
 */
final class AzureSqlProductPageMediaLayoutRepository implements ProductPageMediaLayoutRepository
{
    private ?bool $tableAvailable = null;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function find(ProductPublicId $productPublicId): DescriptionImagePresentation
    {
        if ($this->tableAvailable === false) {
            return DescriptionImagePresentation::defaults();
        }

        try {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    s.[DescriptionImageWidth],
    s.[DescriptionImageAlignment]
FROM [product].[ProductPageMediaSettings] s
INNER JOIN [product].[Products] p ON p.[ProductId] = s.[ProductId]
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
                [':publicId' => $productPublicId->toString()],
            );
            $this->tableAvailable = true;
        } catch (DatabaseUnavailable $exception) {
            if ($this->missingSettingsTable($exception)) {
                $this->tableAvailable = false;

                return DescriptionImagePresentation::defaults();
            }

            throw $exception;
        }

        if ($rows === []) {
            return DescriptionImagePresentation::defaults();
        }

        return DescriptionImagePresentation::resolve(
            AzureSqlValueMapper::nullableString($rows[0]['DescriptionImageWidth'] ?? null),
            AzureSqlValueMapper::nullableString($rows[0]['DescriptionImageAlignment'] ?? null),
        );
    }

    public function save(
        ProductPublicId $productPublicId,
        DescriptionImagePresentation $presentation,
        UserId $actorUserId,
    ): void {
        try {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
MERGE [product].[ProductPageMediaSettings] AS target
USING (
    SELECT p.[ProductId]
    FROM [product].[Products] p
    WHERE p.[PublicId] = :publicId
      AND p.[DeletedAtUtc] IS NULL
) AS source
ON target.[ProductId] = source.[ProductId]
WHEN MATCHED THEN
    UPDATE SET
        target.[DescriptionImageWidth] = :width,
        target.[DescriptionImageAlignment] = :alignment,
        target.[UpdatedAtUtc] = SYSUTCDATETIME(),
        target.[UpdatedByUserId] = :updatedByUserId
WHEN NOT MATCHED THEN
    INSERT (
        [ProductId],
        [DescriptionImageWidth],
        [DescriptionImageAlignment],
        [CreatedByUserId],
        [UpdatedByUserId]
    )
    VALUES (
        source.[ProductId],
        :widthInsert,
        :alignmentInsert,
        :createdByUserId,
        :updatedByUserIdInsert
    )
OUTPUT INSERTED.[ProductId] AS [ProductId];
SQL,
                [
                    ':publicId' => $productPublicId->toString(),
                    ':width' => $presentation->width(),
                    ':alignment' => $presentation->alignment(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                    ':widthInsert' => $presentation->width(),
                    ':alignmentInsert' => $presentation->alignment(),
                    ':createdByUserId' => $actorUserId->toInt(),
                    ':updatedByUserIdInsert' => $actorUserId->toInt(),
                ],
            );
        } catch (DatabaseUnavailable $exception) {
            if ($this->missingSettingsTable($exception)) {
                throw InvalidProductValue::for(
                    'ProductPageMediaSettings',
                    'Run database/scripts/development/alter-product-page-media-layout.sql before saving description image layout.',
                );
            }

            throw $exception;
        }

        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($productPublicId->toString());
        }

        $this->tableAvailable = true;
    }

    private function missingSettingsTable(Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            if (str_contains($current->getMessage(), 'ProductPageMediaSettings')) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }
}
