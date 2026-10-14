<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use Throwable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductPageContentRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Reads fall back to the defaults when [product].[ProductPageContentSettings] has not been installed yet (products render
 * exactly as before). Saves require the table from database/scripts/development/alter-product-page-content.sql.
 *
 * PDO_SQLSRV rule: a named parameter may appear only ONCE per statement - every occurrence below has its own name.
 */
final class AzureSqlProductPageContentRepository implements ProductPageContentRepository
{
    private ?bool $tableAvailable = null;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function find(ProductPublicId $productPublicId): ProductPageContent
    {
        if ($this->tableAvailable === false) {
            return ProductPageContent::defaults();
        }

        try {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    s.[ShowProductMarking],
    s.[CoreColoursNote],
    s.[ShowCoreColoursNote],
    CONVERT(VARCHAR(33), s.[UpdatedAtUtc], 127) AS [Version]
FROM [product].[ProductPageContentSettings] s
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

                return ProductPageContent::defaults();
            }

            throw $exception;
        }

        if ($rows === []) {
            return ProductPageContent::defaults();
        }

        return ProductPageContent::resolve(
            AzureSqlValueMapper::boolValue($rows[0]['ShowProductMarking'] ?? false),
            AzureSqlValueMapper::nullableString($rows[0]['CoreColoursNote'] ?? null),
            AzureSqlValueMapper::boolValue($rows[0]['ShowCoreColoursNote'] ?? true),
            AzureSqlValueMapper::nullableString($rows[0]['Version'] ?? null),
        );
    }

    public function save(ProductPublicId $productPublicId, ProductPageContent $content, UserId $actorUserId, ?string $expectedVersion): void
    {
        try {
            // optimistic concurrency inside the caller's transaction: the row is locked while its version is compared
            $current = $this->connection->fetchAll(
                <<<'SQL'
SELECT CONVERT(VARCHAR(33), s.[UpdatedAtUtc], 127) AS [Version]
FROM [product].[ProductPageContentSettings] s WITH (UPDLOCK, HOLDLOCK)
INNER JOIN [product].[Products] p ON p.[ProductId] = s.[ProductId]
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
                [':publicId' => $productPublicId->toString()],
            );
            $currentVersion = $current === [] ? null : AzureSqlValueMapper::nullableString($current[0]['Version'] ?? null);
            if ($currentVersion !== $expectedVersion) {
                throw ProductConcurrencyConflict::forPublicId($productPublicId->toString());
            }

            $rows = $this->connection->fetchAll(
                <<<'SQL'
MERGE [product].[ProductPageContentSettings] AS target
USING (
    SELECT p.[ProductId]
    FROM [product].[Products] p
    WHERE p.[PublicId] = :publicId
      AND p.[DeletedAtUtc] IS NULL
) AS source
ON target.[ProductId] = source.[ProductId]
WHEN MATCHED THEN
    UPDATE SET
        target.[ShowProductMarking] = :showMarking,
        target.[CoreColoursNote] = :note,
        target.[ShowCoreColoursNote] = :showNote,
        target.[UpdatedAtUtc] = SYSUTCDATETIME(),
        target.[UpdatedByUserId] = :updatedByUserId
WHEN NOT MATCHED THEN
    INSERT (
        [ProductId],
        [ShowProductMarking],
        [CoreColoursNote],
        [ShowCoreColoursNote],
        [CreatedByUserId],
        [UpdatedByUserId]
    )
    VALUES (
        source.[ProductId],
        :showMarkingInsert,
        :noteInsert,
        :showNoteInsert,
        :createdByUserId,
        :updatedByUserIdInsert
    )
OUTPUT INSERTED.[ProductId] AS [ProductId];
SQL,
                [
                    ':publicId' => $productPublicId->toString(),
                    ':showMarking' => $content->showProductMarking() ? 1 : 0,
                    ':note' => $content->coreColoursNote(),
                    ':showNote' => $content->showCoreColoursNote() ? 1 : 0,
                    ':updatedByUserId' => $actorUserId->toInt(),
                    ':showMarkingInsert' => $content->showProductMarking() ? 1 : 0,
                    ':noteInsert' => $content->coreColoursNote(),
                    ':showNoteInsert' => $content->showCoreColoursNote() ? 1 : 0,
                    ':createdByUserId' => $actorUserId->toInt(),
                    ':updatedByUserIdInsert' => $actorUserId->toInt(),
                ],
            );
        } catch (DatabaseUnavailable $exception) {
            if ($this->missingSettingsTable($exception)) {
                throw InvalidProductValue::for(
                    'ProductPageContentSettings',
                    'Run database/scripts/development/alter-product-page-content.sql before saving page content settings.',
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
            if (str_contains($current->getMessage(), 'ProductPageContentSettings')) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }
}
