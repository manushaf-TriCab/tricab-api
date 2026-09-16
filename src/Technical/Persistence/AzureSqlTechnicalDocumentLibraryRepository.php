<?php

declare(strict_types=1);

namespace TriCab\Api\Technical\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Support\JsonValue;
use TriCab\Api\Technical\Document\TechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\TechnicalDocumentRecord;

/**
 * Azure SQL adapter for the Technical Document Library.
 * Tables: technical.Documents (+ category/product/style links).
 *
 * Read-only for Phase 4G — no migration writes.
 */
final class AzureSqlTechnicalDocumentLibraryRepository implements TechnicalDocumentLibraryRepository
{
    private const DOCUMENT_SELECT_SQL = <<<'SQL'
SELECT
    d.[DocumentId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    d.[Title],
    d.[Issuer],
    d.[OriginalFileName],
    dt.[DocumentTypeCode],
    d.[StoragePath],
    d.[PublicUrl],
    d.[ChecksumSha256],
    d.[SourceSystemCode],
    d.[SourceLegacyId],
    d.[DisplayOrder],
    d.[IsActive]
FROM [technical].[Documents] d
INNER JOIN [product].[DocumentTypes] dt
    ON dt.[DocumentTypeId] = d.[DocumentTypeId]
    AND dt.[DeletedAtUtc] IS NULL
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listActiveByCategory(?string $categoryCode = null): array
    {
        $normalized = $categoryCode !== null ? trim($categoryCode) : '';
        $sql = self::DOCUMENT_SELECT_SQL . <<<'SQL'

WHERE d.[DeletedAtUtc] IS NULL
  AND d.[IsActive] = 1
SQL;
        $params = [];
        if ($normalized !== '') {
            $sql .= <<<'SQL'

  AND EXISTS (
        SELECT 1
        FROM [technical].[DocumentCategoryLinks] cl
        INNER JOIN [technical].[DocumentCategories] c
            ON c.[DocumentCategoryId] = cl.[DocumentCategoryId]
           AND c.[DeletedAtUtc] IS NULL
           AND c.[IsActive] = 1
        WHERE cl.[DocumentId] = d.[DocumentId]
          AND cl.[DeletedAtUtc] IS NULL
          AND c.[CategoryCode] = :categoryCode
  )
SQL;
            $params[':categoryCode'] = $normalized;
        }
        $sql .= ' ORDER BY d.[DisplayOrder], d.[Title], d.[DocumentId]';

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            return [];
        }

        return $this->hydrateMany($rows);
    }

    public function findActiveByChecksum(string $checksumSha256): ?TechnicalDocumentRecord
    {
        $needle = strtolower(trim($checksumSha256));
        if ($needle === '') {
            return null;
        }

        $rows = $this->connection->fetchAll(
            self::DOCUMENT_SELECT_SQL . <<<'SQL'

WHERE d.[DeletedAtUtc] IS NULL
  AND d.[IsActive] = 1
  AND LOWER(d.[ChecksumSha256]) = :checksum
ORDER BY d.[DocumentId]
SQL,
            [':checksum' => $needle],
        );
        if ($rows === []) {
            return null;
        }

        return $this->hydrateMany($rows)[0] ?? null;
    }

    public function findActiveByLegacyId(string $sourceSystemCode, string $sourceLegacyId): ?TechnicalDocumentRecord
    {
        $system = trim($sourceSystemCode);
        $legacyId = trim($sourceLegacyId);
        if ($system === '' || $legacyId === '') {
            return null;
        }

        $rows = $this->connection->fetchAll(
            self::DOCUMENT_SELECT_SQL . <<<'SQL'

WHERE d.[DeletedAtUtc] IS NULL
  AND d.[IsActive] = 1
  AND d.[SourceSystemCode] = :sourceSystem
  AND d.[SourceLegacyId] = :sourceLegacyId
ORDER BY d.[DocumentId]
SQL,
            [
                ':sourceSystem' => $system,
                ':sourceLegacyId' => $legacyId,
            ],
        );
        if ($rows === []) {
            return null;
        }

        return $this->hydrateMany($rows)[0] ?? null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<TechnicalDocumentRecord>
     */
    private function hydrateMany(array $rows): array
    {
        /** @var list<int> $documentIds */
        $documentIds = [];
        foreach ($rows as $row) {
            $documentIds[] = JsonValue::int($row, 'DocumentId');
        }
        $documentIds = array_values(array_unique($documentIds));

        $categoriesById = $this->loadCategoryCodes($documentIds);
        $productsById = $this->loadProductPublicIds($documentIds);
        $stylesById = $this->loadStylePublicIds($documentIds);

        $out = [];
        foreach ($rows as $row) {
            $id = JsonValue::int($row, 'DocumentId');
            $out[] = new TechnicalDocumentRecord(
                publicId: JsonValue::string($row, 'PublicId'),
                title: JsonValue::string($row, 'Title'),
                originalFileName: JsonValue::string($row, 'OriginalFileName'),
                documentTypeCode: JsonValue::string($row, 'DocumentTypeCode'),
                categoryCodes: $categoriesById[$id] ?? [],
                issuer: $this->nullableString($row['Issuer'] ?? null),
                storagePath: $this->nullableString($row['StoragePath'] ?? null),
                publicUrl: $this->nullableString($row['PublicUrl'] ?? null),
                checksumSha256: $this->nullableString($row['ChecksumSha256'] ?? null),
                sourceLegacyId: $this->nullableString($row['SourceLegacyId'] ?? null),
                productPublicIds: $productsById[$id] ?? [],
                stylePublicIds: $stylesById[$id] ?? [],
                isActive: (JsonValue::int($row, 'IsActive', 1)) === 1,
            );
        }

        return $out;
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, list<string>>
     */
    private function loadCategoryCodes(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        [$inSql, $params] = $this->inClause($documentIds, 'doc');
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    cl.[DocumentId],
    c.[CategoryCode]
FROM [technical].[DocumentCategoryLinks] cl
INNER JOIN [technical].[DocumentCategories] c
    ON c.[DocumentCategoryId] = cl.[DocumentCategoryId]
   AND c.[DeletedAtUtc] IS NULL
   AND c.[IsActive] = 1
WHERE cl.[DeletedAtUtc] IS NULL
  AND cl.[DocumentId] IN ({$inSql})
ORDER BY c.[DisplayOrder], c.[CategoryCode]
SQL,
            $params,
        );

        return $this->groupStrings($rows, 'DocumentId', 'CategoryCode');
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, list<string>>
     */
    private function loadProductPublicIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        [$inSql, $params] = $this->inClause($documentIds, 'doc');
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    pl.[DocumentId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId]
FROM [technical].[DocumentProductLinks] pl
INNER JOIN [product].[Products] p
    ON p.[ProductId] = pl.[ProductId]
   AND p.[DeletedAtUtc] IS NULL
WHERE pl.[DeletedAtUtc] IS NULL
  AND pl.[DocumentId] IN ({$inSql})
ORDER BY p.[PublicId]
SQL,
            $params,
        );

        return $this->groupStrings($rows, 'DocumentId', 'ProductPublicId');
    }

    /**
     * @param list<int> $documentIds
     * @return array<int, list<string>>
     */
    private function loadStylePublicIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }
        [$inSql, $params] = $this->inClause($documentIds, 'doc');
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    sl.[DocumentId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [StylePublicId]
FROM [technical].[DocumentStyleLinks] sl
INNER JOIN [product].[ProductStyles] s
    ON s.[ProductStyleId] = sl.[ProductStyleId]
   AND s.[DeletedAtUtc] IS NULL
WHERE sl.[DeletedAtUtc] IS NULL
  AND sl.[DocumentId] IN ({$inSql})
ORDER BY s.[PublicId]
SQL,
            $params,
        );

        return $this->groupStrings($rows, 'DocumentId', 'StylePublicId');
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function inClause(array $ids, string $prefix): array
    {
        $params = [];
        $parts = [];
        foreach ($ids as $i => $id) {
            $key = ':' . $prefix . $i;
            $parts[] = $key;
            $params[$key] = $id;
        }

        return [implode(', ', $parts), $params];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<string>>
     */
    private function groupStrings(array $rows, string $idKey, string $valueKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = JsonValue::int($row, $idKey);
            $value = trim(JsonValue::string($row, $valueKey));
            if ($value === '') {
                continue;
            }
            $out[$id] ??= [];
            if (!in_array($value, $out[$id], true)) {
                $out[$id][] = $value;
            }
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
