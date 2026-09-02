<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchResult;
use TriCab\Core\Product\Contract\ProductCodeSearchRepository;

/**
 * Structured search over [product].[ProductCodeIndex].
 *
 * Two things this can answer that nothing else in the catalogue can:
 *
 *  1. Numeric size ranges. CSA lives as `text` in the EAV specification tables;
 *     [CrossSectionMm2] is the decimal projection, so BETWEEN works.
 *  2. Codes with no loaded ProductVariant — roughly 27,000 of the ~32,000 indexed.
 *     They are sellable and must be findable, so the catalogue join is an
 *     OUTER APPLY that decorates rows rather than an INNER JOIN that drops them.
 *
 * Style-level facets (LSZH, fire resistant, armoured, screened, cable type,
 * voltage class) are not in the code grammar at all. They hang off
 * [product].[ProductStyles] via [ProductStyleSpecificationValues], matched here by
 * StyleCode — the index's segment 1.
 *
 * Those booleans are three-valued on purpose. 32 to 55 styles per facet have no
 * construction text to read, so absence means unknown, not false — the shipboard
 * BV/DV/PJ range has no construction description at all, and treating that as
 * "not LSZH" would exclude the entire marine range from an LSZH search. So
 * isLszh=false matches ValueBoolean = 0, never the missing row.
 */
final class AzureSqlProductCodeSearchRepository implements ProductCodeSearchRepository
{
    /**
     * Every character LIKE treats as a wildcard, escaped for ESCAPE N'~'.
     *
     * A tilde rather than the conventional backslash because PDO's own placeholder
     * scanner reads N'\' as an unterminated string and then drops every named
     * parameter after it: the statement prepares, then fails at execute with
     * "parameter was not defined".
     */
    private const LIKE_ESCAPE = ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~['];

    private const BOOLEAN_FACETS = [
        'isLszh' => 'IS-LSZH',
        'isFireResistant' => 'IS-FIRE-RESISTANT',
        'isArmoured' => 'IS-ARMOURED',
        'hasScreen' => 'HAS-SCREEN',
    ];

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function search(ProductCodeSearchCriteria $criteria): ProductCodeSearchListResult
    {
        [$where, $params] = $this->buildWhere($criteria);

        $countRows = $this->connection->fetchAll(
            <<<SQL
SELECT COUNT(*) AS [Total]
FROM [product].[ProductCodeIndex] i
LEFT JOIN [product].[ProductStyles] st
    ON st.[StyleCode] = i.[StyleCode]
   AND st.[DeletedAtUtc] IS NULL
WHERE 1 = 1
{$where}
SQL,
            $params,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        if ($total === 0) {
            return new ProductCodeSearchListResult(
                items: [],
                pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, 0),
            );
        }

        [$matchTier, $tierParams] = $this->buildMatchTier($criteria);
        $catalogueFilter = $criteria->publishedOnly ? $this->publishedFilter() : '';
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    i.[CodeValue],
    i.[StyleCode],
    st.[DisplayName] AS [StyleName],
    i.[StyleSuffix],
    i.[JacketTypeCode],
    i.[CoreColourConfig],
    i.[BraidArmourScreen],
    i.[ConductorCode],
    i.[CoreCount],
    i.[CoreTypeCode],
    i.[CrossSectionRaw],
    i.[CrossSectionUnit],
    i.[CrossSectionMm2],
    i.[CrossSectionNominalMm2],
    i.[SetCores],
    i.[JacketColour],
    i.[StockStatus],
    i.[IsCurrent],
    i.[WeightKgPerKm],
    i.[Desc2] AS [Description],
    cat.[ProductName],
    cat.[Slug],
    cat.[VariantPublicId],
    cat.[VariantCode],
    {$matchTier} AS [MatchTier]
FROM [product].[ProductCodeIndex] i
LEFT JOIN [product].[ProductStyles] st
    ON st.[StyleCode] = i.[StyleCode]
   AND st.[DeletedAtUtc] IS NULL
OUTER APPLY (
    SELECT TOP 1
        p.[ProductName],
        CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
        v.[VariantCode],
        (
            SELECT TOP 1 slug.[Slug]
            FROM [product].[ProductSlugs] slug
            WHERE slug.[ProductId] = p.[ProductId]
              AND slug.[DeletedAtUtc] IS NULL
              AND slug.[IsPrimary] = 1
            ORDER BY slug.[ProductSlugId]
        ) AS [Slug]
    FROM [product].[ProductVariants] v
    INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
    INNER JOIN [product].[ProductStatuses] pst ON pst.[ProductStatusId] = p.[ProductStatusId]
    WHERE v.[ProductVariantId] = i.[ProductVariantId]
      AND v.[DeletedAtUtc] IS NULL
      AND p.[DeletedAtUtc] IS NULL
      {$catalogueFilter}
) cat
WHERE 1 = 1
{$where}
ORDER BY
    [MatchTier] ASC,
    i.[IsCurrent] DESC,
    CASE i.[StockStatus]
        WHEN N'STOCK' THEN 0
        WHEN N'MAKE' THEN 1
        ELSE 2
    END ASC,
    i.[CrossSectionMm2] ASC,
    i.[CodeValue] ASC
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [
                ...$params,
                ...$tierParams,
                ':offset' => $offset,
                ':pageSize' => $criteria->pageSize,
            ],
        );

        return new ProductCodeSearchListResult(
            items: array_map(
                static fn (array $row): ProductCodeSearchResult => self::toResult($row),
                $rows,
            ),
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || $limit < 1) {
            return [];
        }

        $prefix = self::escapeLike($trimmed) . '%';
        // TOP takes an integer literal here rather than a bound parameter: $limit is
        // already validated above, and sqlsrv will not bind inside TOP on every driver.
        $top = min($limit, 50);
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT TOP {$top} i.[CodeValue]
FROM [product].[ProductCodeIndex] i
WHERE i.[CodeValue] LIKE :prefix ESCAPE N'~'
ORDER BY
    CASE WHEN i.[CodeValue] = :exact THEN 0 ELSE 1 END ASC,
    i.[IsCurrent] DESC,
    LEN(i.[CodeValue]) ASC,
    i.[CodeValue] ASC
SQL,
            [
                ':prefix' => $prefix,
                ':exact' => $trimmed,
            ],
        );

        $suggestions = [];
        foreach ($rows as $row) {
            $value = AzureSqlValueMapper::stringValue($row['CodeValue'] ?? '');
            if ($value !== '' && !in_array($value, $suggestions, true)) {
                $suggestions[] = $value;
            }
        }

        return $suggestions;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toResult(array $row): ProductCodeSearchResult
    {
        return new ProductCodeSearchResult(
            codeValue: AzureSqlValueMapper::stringValue($row['CodeValue'] ?? ''),
            styleCode: AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''),
            styleName: AzureSqlValueMapper::nullableString($row['StyleName'] ?? null),
            styleSuffix: AzureSqlValueMapper::nullableString($row['StyleSuffix'] ?? null),
            jacketTypeCode: AzureSqlValueMapper::nullableString($row['JacketTypeCode'] ?? null),
            coreColourConfig: AzureSqlValueMapper::nullableString($row['CoreColourConfig'] ?? null),
            braidArmourScreen: AzureSqlValueMapper::nullableString($row['BraidArmourScreen'] ?? null),
            conductorCode: AzureSqlValueMapper::nullableString($row['ConductorCode'] ?? null),
            coreCount: AzureSqlValueMapper::nullableInt($row['CoreCount'] ?? null),
            coreTypeCode: AzureSqlValueMapper::nullableString($row['CoreTypeCode'] ?? null),
            crossSectionRaw: AzureSqlValueMapper::nullableString($row['CrossSectionRaw'] ?? null),
            crossSectionUnit: AzureSqlValueMapper::nullableString($row['CrossSectionUnit'] ?? null),
            crossSectionMm2: self::nullableFloat($row['CrossSectionMm2'] ?? null),
            crossSectionNominalMm2: self::nullableFloat($row['CrossSectionNominalMm2'] ?? null),
            setCores: AzureSqlValueMapper::nullableInt($row['SetCores'] ?? null),
            jacketColour: AzureSqlValueMapper::nullableString($row['JacketColour'] ?? null),
            stockStatus: AzureSqlValueMapper::nullableString($row['StockStatus'] ?? null),
            isCurrent: AzureSqlValueMapper::boolValue($row['IsCurrent'] ?? false),
            weightKgPerKm: self::nullableFloat($row['WeightKgPerKm'] ?? null),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            productName: AzureSqlValueMapper::nullableString($row['ProductName'] ?? null),
            productSlug: AzureSqlValueMapper::nullableString($row['Slug'] ?? null),
            variantPublicId: AzureSqlValueMapper::nullableString($row['VariantPublicId'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            matchTier: AzureSqlValueMapper::nullableInt($row['MatchTier'] ?? null),
        );
    }

    /**
     * DECIMAL comes back from the sqlsrv driver as a string, so it needs the same
     * care nullableInt gives integers: '' is not 0.0, and a non-numeric value is
     * not silently cast.
     */
    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * The whole WHERE, shared verbatim between the count and the page so the two
     * cannot drift apart.
     *
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function buildWhere(ProductCodeSearchCriteria $criteria): array
    {
        $where = '';
        /** @var array<string, scalar|SqlBinary|null> $params */
        $params = [];

        // Free text: every token must match somewhere (AND across tokens),
        // any of code / style code / description / style name / style synonyms
        // will do (OR across columns). One opaque '%whole query%' is why free-text
        // search returns nothing today.
        foreach ($criteria->textTokens() as $index => $token) {
            $like = '%' . self::escapeLike($token) . '%';
            $codeParam = ':text' . $index . 'Code';
            $styleParam = ':text' . $index . 'Style';
            $descParam = ':text' . $index . 'Desc';
            $nameParam = ':text' . $index . 'Name';
            $synonymParam = ':text' . $index . 'Syn';
            $params[$codeParam] = $like;
            $params[$styleParam] = $like;
            $params[$descParam] = $like;
            $params[$nameParam] = $like;
            $params[$synonymParam] = $like;

            $where .= <<<SQL
 AND (
    i.[CodeValue] LIKE {$codeParam} ESCAPE N'~'
    OR i.[StyleCode] LIKE {$styleParam} ESCAPE N'~'
    OR i.[Desc2] LIKE {$descParam} ESCAPE N'~'
    OR st.[DisplayName] LIKE {$nameParam} ESCAPE N'~'
    OR EXISTS (
        SELECT 1
        FROM [product].[ProductStyleSpecificationValues] sv
        INNER JOIN [product].[SpecificationDefinitions] sd
            ON sd.[SpecificationDefinitionId] = sv.[SpecificationDefinitionId]
           AND sd.[DefinitionCode] = N'STYLE-SYNONYMS'
        WHERE sv.[ProductStyleId] = st.[ProductStyleId]
          AND sv.[DeletedAtUtc] IS NULL
          AND sv.[ValueLongText] LIKE {$synonymParam} ESCAPE N'~'
    )
 )
SQL;
        }

        $styleCodes = $criteria->normalizedStyleCodes();
        if ($styleCodes !== []) {
            $placeholders = [];
            foreach ($styleCodes as $index => $styleCode) {
                $placeholder = ':styleCode' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $styleCode;
            }
            $where .= ' AND UPPER(i.[StyleCode]) IN (' . implode(', ', $placeholders) . ')';
        }

        $stockStatuses = $criteria->normalizedStockStatuses();
        if ($stockStatuses !== []) {
            $placeholders = [];
            foreach ($stockStatuses as $index => $stockStatus) {
                $placeholder = ':stockStatus' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $stockStatus;
            }
            $where .= ' AND i.[StockStatus] IN (' . implode(', ', $placeholders) . ')';
        }

        if ($criteria->coreCount !== null) {
            $where .= ' AND i.[CoreCount] = :coreCount';
            $params[':coreCount'] = $criteria->coreCount;
        }
        if ($criteria->coreCountMin !== null) {
            $where .= ' AND i.[CoreCount] >= :coreCountMin';
            $params[':coreCountMin'] = $criteria->coreCountMin;
        }
        if ($criteria->coreCountMax !== null) {
            $where .= ' AND i.[CoreCount] <= :coreCountMax';
            $params[':coreCountMax'] = $criteria->coreCountMax;
        }

        // Prefer TriCab's declared nominal equivalent over the true area: AWG 16 is
        // sold as the 1.5 mm2 size even though it measures 1.309, so a "1.5 mm2"
        // search must find it. Derating maths uses CrossSectionMm2; search does not.
        $size = 'COALESCE(i.[CrossSectionNominalMm2], i.[CrossSectionMm2])';
        if ($criteria->crossSectionMm2Min !== null) {
            $where .= " AND {$size} >= :csaMin";
            $params[':csaMin'] = $criteria->crossSectionMm2Min;
        }
        if ($criteria->crossSectionMm2Max !== null) {
            $where .= " AND {$size} <= :csaMax";
            $params[':csaMax'] = $criteria->crossSectionMm2Max;
        }

        foreach ([
            'coreType' => ['column' => 'CoreTypeCode', 'value' => $criteria->coreType],
            'conductorCode' => ['column' => 'ConductorCode', 'value' => $criteria->conductorCode],
            'jacketColour' => ['column' => 'JacketColour', 'value' => $criteria->jacketColour],
            'braidArmourScreen' => ['column' => 'BraidArmourScreen', 'value' => $criteria->braidArmourScreen],
        ] as $name => $segment) {
            $value = $segment['value'];
            if ($value === null || trim($value) === '') {
                continue;
            }
            $placeholder = ':' . $name;
            $where .= ' AND UPPER(i.[' . $segment['column'] . ']) = ' . $placeholder;
            $params[$placeholder] = strtoupper(trim($value));
        }

        if ($criteria->isCurrent !== null) {
            $where .= ' AND i.[IsCurrent] = :isCurrent';
            $params[':isCurrent'] = $criteria->isCurrent ? 1 : 0;
        }

        if ($criteria->cableType !== null && trim($criteria->cableType) !== '') {
            [$sql, $facetParams] = $this->optionFacet('cableType', 'CABLE-TYPE', trim($criteria->cableType));
            $where .= $sql;
            $params = [...$params, ...$facetParams];
        }
        if ($criteria->voltageClass !== null && trim($criteria->voltageClass) !== '') {
            [$sql, $facetParams] = $this->optionFacet('voltageClass', 'VOLTAGE-CLASS', trim($criteria->voltageClass));
            $where .= $sql;
            $params = [...$params, ...$facetParams];
        }

        foreach (self::BOOLEAN_FACETS as $field => $definitionCode) {
            /** @var bool|null $value */
            $value = match ($field) {
                'isLszh' => $criteria->isLszh,
                'isFireResistant' => $criteria->isFireResistant,
                'isArmoured' => $criteria->isArmoured,
                'hasScreen' => $criteria->hasScreen,
            };
            if ($value === null) {
                continue;
            }

            [$sql, $facetParams] = $this->booleanFacet($field, $definitionCode, $value);
            $where .= $sql;
            $params = [...$params, ...$facetParams];
        }

        return [$where, $params];
    }

    /**
     * A single-select style facet — CABLE-TYPE, VOLTAGE-CLASS — matched by option code.
     *
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function optionFacet(string $alias, string $definitionCode, string $optionCode): array
    {
        $sql = <<<SQL
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductStyleSpecificationValues] fv_{$alias}
    INNER JOIN [product].[SpecificationDefinitions] fd_{$alias}
        ON fd_{$alias}.[SpecificationDefinitionId] = fv_{$alias}.[SpecificationDefinitionId]
       AND fd_{$alias}.[DefinitionCode] = :def_{$alias}
    INNER JOIN [product].[SpecificationOptions] fo_{$alias}
        ON fo_{$alias}.[SpecificationOptionId] = fv_{$alias}.[SpecificationOptionId]
    WHERE fv_{$alias}.[ProductStyleId] = st.[ProductStyleId]
      AND fv_{$alias}.[DeletedAtUtc] IS NULL
      AND UPPER(fo_{$alias}.[OptionCode]) = :opt_{$alias}
 )
SQL;

        return [$sql, [
            ':def_' . $alias => $definitionCode,
            ':opt_' . $alias => strtoupper($optionCode),
        ]];
    }

    /**
     * A three-valued boolean facet. false means a style whose construction text says
     * so — ValueBoolean = 0 — never a style with no row, which means unknown.
     *
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function booleanFacet(string $alias, string $definitionCode, bool $value): array
    {
        $sql = <<<SQL
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductStyleSpecificationValues] bv_{$alias}
    INNER JOIN [product].[SpecificationDefinitions] bd_{$alias}
        ON bd_{$alias}.[SpecificationDefinitionId] = bv_{$alias}.[SpecificationDefinitionId]
       AND bd_{$alias}.[DefinitionCode] = :def_{$alias}
    WHERE bv_{$alias}.[ProductStyleId] = st.[ProductStyleId]
      AND bv_{$alias}.[DeletedAtUtc] IS NULL
      AND bv_{$alias}.[ValueBoolean] = :val_{$alias}
 )
SQL;

        return [$sql, [
            ':def_' . $alias => $definitionCode,
            ':val_' . $alias => $value ? 1 : 0,
        ]];
    }

    /**
     * Ranks an exact code above a prefix above a substring above a facet-only hit,
     * so "BHA-PATN" puts the code itself first. NULL when the search names no text,
     * which leaves the ordering to currency, stock and size.
     *
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function buildMatchTier(ProductCodeSearchCriteria $criteria): array
    {
        $text = $criteria->normalizedText();
        if ($text === null) {
            return ['CAST(NULL AS INT)', []];
        }

        $escaped = self::escapeLike($text);

        return [
            <<<'SQL'
CASE
    WHEN i.[CodeValue] = :tierExact THEN 0
    WHEN i.[CodeValue] LIKE :tierPrefix ESCAPE N'~' THEN 1
    WHEN i.[CodeValue] LIKE :tierContains ESCAPE N'~' THEN 2
    ELSE 3
END
SQL,
            [
                ':tierExact' => $text,
                ':tierPrefix' => $escaped . '%',
                ':tierContains' => '%' . $escaped . '%',
            ],
        ];
    }

    private function publishedFilter(): string
    {
        return <<<'SQL'
AND pst.[StatusCode] = N'published'
      AND p.[IsActive] = 1
      AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
      AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
SQL;
    }

    /**
     * Product codes contain no LIKE metacharacters, but customer-typed text can.
     * Without this, a stray '%' or '[' turns a narrow search into a wide one.
     */
    private static function escapeLike(string $value): string
    {
        return strtr($value, self::LIKE_ESCAPE);
    }
}
