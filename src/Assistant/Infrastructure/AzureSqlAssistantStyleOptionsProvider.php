<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * Reads the nine Phase 1 style facets without exposing the generic PIM tables.
 *
 * `STYLE-SYNONYMS` is the searchable one. It is not a list of alternative names
 * despite the definition code: the loader writes a pipe-joined line carrying the
 * style's code, display name, application prose, standards, voltage class,
 * temperature range and an LSZH marker —
 *
 *   LP | Flexible Rubber Building Wire | Flexible fine stranded building wire
 *   used for earthing of mains and sub mains and for single insulated
 *   applications in pipe and conduit. | AS/NZS 1125, IEC 60228, ... |
 *   600/1000 V | -15 to 105 deg C
 *
 * — which is already the right shape for a keyword search and needs no
 * reshaping. `AzureSqlProductCodeSearchRepository` searches the same column for
 * exactly this reason; the gap was that nothing let a caller search it at style
 * granularity, so the text was returned but never queried.
 */
final readonly class AzureSqlAssistantStyleOptionsProvider implements AssistantStyleOptionsProvider
{
    /** 145 styles carry at least one facet; without paging most were unreachable. */
    private const MAX_PAGE_SIZE = 50;

    /**
     * Every character LIKE treats as a wildcard, escaped for ESCAPE N'~'.
     *
     * A tilde rather than the conventional backslash for the reason recorded on
     * `AzureSqlProductCodeSearchRepository::LIKE_ESCAPE`: PDO's own placeholder
     * scanner reads N'\' as an unterminated string and silently drops every
     * named parameter after it.
     */
    private const LIKE_ESCAPE = ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~['];

    private const FACETS = [
        'CABLE-TYPE',
        'VOLTAGE-CLASS',
        'IS-LSZH',
        'IS-FIRE-RESISTANT',
        'IS-ARMOURED',
        'HAS-SCREEN',
        'IS-TERMITE-RESISTANT',
        'MAX-TEMP-C',
        'STYLE-SYNONYMS',
    ];

    public function __construct(private SqlConnection $connection)
    {
    }

    public function search(
        array $styleCodes,
        ?string $text,
        ?string $cableType,
        int $page,
        int $pageSize,
    ): array {
        $safePageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $safePage = max(1, $page);
        [$where, $params] = $this->buildWhere($styleCodes, $text, $cableType);
        $facetLiterals = $this->facetLiterals();

        $countRows = $this->connection->fetchAll(
            <<<SQL
SELECT COUNT(*) AS [Total]
FROM [product].[ProductStyles] st
WHERE st.[DeletedAtUtc] IS NULL
  AND st.[IsActive] = 1
  {$where}
  AND EXISTS (
      SELECT 1
      FROM [product].[ProductStyleSpecificationValues] ev
      INNER JOIN [product].[SpecificationDefinitions] ed
          ON ed.[SpecificationDefinitionId] = ev.[SpecificationDefinitionId]
         AND ed.[DefinitionCode] IN ({$facetLiterals})
         AND ed.[DeletedAtUtc] IS NULL
      WHERE ev.[ProductStyleId] = st.[ProductStyleId]
        AND ev.[DeletedAtUtc] IS NULL
  )
SQL,
            $params,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        if ($total === 0) {
            return ['items' => [], 'totalItems' => 0, 'page' => $safePage, 'pageSize' => $safePageSize];
        }

        $params[':offset'] = ($safePage - 1) * $safePageSize;
        $params[':pageSize'] = $safePageSize;

        // OFFSET/FETCH rather than TOP: the whole point of this change is that a
        // caller can walk past the first page.
        $sql = <<<SQL
WITH matched_styles AS (
    SELECT
        st.[ProductStyleId], st.[StyleCode], st.[DisplayName]
    FROM [product].[ProductStyles] st
    WHERE st.[DeletedAtUtc] IS NULL
      AND st.[IsActive] = 1
      {$where}
      AND EXISTS (
          SELECT 1
          FROM [product].[ProductStyleSpecificationValues] ev
          INNER JOIN [product].[SpecificationDefinitions] ed
              ON ed.[SpecificationDefinitionId] = ev.[SpecificationDefinitionId]
             AND ed.[DefinitionCode] IN ({$facetLiterals})
             AND ed.[DeletedAtUtc] IS NULL
          WHERE ev.[ProductStyleId] = st.[ProductStyleId]
            AND ev.[DeletedAtUtc] IS NULL
      )
    ORDER BY st.[StyleCode]
    OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
)
SELECT
    ms.[StyleCode], ms.[DisplayName], d.[DefinitionCode], d.[DefinitionName],
    d.[DataTypeCode], o.[OptionCode], o.[OptionLabel],
    v.[ValueText], v.[ValueLongText], v.[ValueInteger], v.[ValueBoolean]
FROM matched_styles ms
INNER JOIN [product].[ProductStyleSpecificationValues] v
    ON v.[ProductStyleId] = ms.[ProductStyleId]
   AND v.[DeletedAtUtc] IS NULL
INNER JOIN [product].[SpecificationDefinitions] d
    ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
   AND d.[DefinitionCode] IN ({$facetLiterals})
   AND d.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[SpecificationOptions] o
    ON o.[SpecificationOptionId] = v.[SpecificationOptionId]
   AND o.[DeletedAtUtc] IS NULL
ORDER BY ms.[StyleCode], d.[DisplayOrder], d.[DefinitionCode]
SQL;

        $styles = [];
        foreach ($this->connection->fetchAll($sql, $params) as $row) {
            $styleCode = AzureSqlValueMapper::stringValue($row['StyleCode'] ?? null);
            $definitionCode = AzureSqlValueMapper::stringValue($row['DefinitionCode'] ?? null);
            if ($styleCode === '' || $definitionCode === '') {
                continue;
            }
            if (!isset($styles[$styleCode])) {
                $styles[$styleCode] = [
                    'styleCode' => $styleCode,
                    'displayName' => AzureSqlValueMapper::stringValue($row['DisplayName'] ?? $styleCode),
                    'facets' => [],
                ];
            }
            $styles[$styleCode]['facets'][$definitionCode] = [
                'name' => AzureSqlValueMapper::stringValue($row['DefinitionName'] ?? $definitionCode),
                'value' => $this->value($row),
                'label' => AzureSqlValueMapper::nullableString($row['OptionLabel'] ?? null),
            ];
        }

        return [
            'items' => array_values($styles),
            'totalItems' => $total,
            'page' => $safePage,
            'pageSize' => $safePageSize,
        ];
    }

    /**
     * The whole WHERE, shared verbatim between the count and the page so the two
     * cannot drift apart.
     *
     * @param list<string> $styleCodes
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function buildWhere(array $styleCodes, ?string $text, ?string $cableType): array
    {
        $where = '';
        /** @var array<string, scalar> $params */
        $params = [];

        $normalizedStyles = array_values(array_unique(array_map(
            static fn (string $style): string => strtoupper(trim($style)),
            array_filter($styleCodes, static fn (string $style): bool => trim($style) !== ''),
        )));
        if ($normalizedStyles !== []) {
            $placeholders = [];
            foreach ($normalizedStyles as $index => $styleCode) {
                $key = ':styleCode' . $index;
                $placeholders[] = $key;
                $params[$key] = $styleCode;
            }
            $where .= ' AND st.[StyleCode] IN (' . implode(', ', $placeholders) . ')';
        }

        // Every token must match somewhere (AND across tokens), code / display
        // name / synonym line will do (OR across columns) — the same shape as
        // the code search, so "lszh earthing" narrows rather than widens.
        foreach ($this->textTokens($text) as $index => $token) {
            $like = '%' . strtr($token, self::LIKE_ESCAPE) . '%';
            $codeParam = ':text' . $index . 'Code';
            $nameParam = ':text' . $index . 'Name';
            $synonymParam = ':text' . $index . 'Syn';
            $params[$codeParam] = $like;
            $params[$nameParam] = $like;
            $params[$synonymParam] = $like;

            $where .= <<<SQL

 AND (
    st.[StyleCode] LIKE {$codeParam} ESCAPE N'~'
    OR st.[DisplayName] LIKE {$nameParam} ESCAPE N'~'
    OR EXISTS (
        SELECT 1
        FROM [product].[ProductStyleSpecificationValues] sv
        INNER JOIN [product].[SpecificationDefinitions] sd
            ON sd.[SpecificationDefinitionId] = sv.[SpecificationDefinitionId]
           AND sd.[DefinitionCode] = N'STYLE-SYNONYMS'
           AND sd.[DeletedAtUtc] IS NULL
        WHERE sv.[ProductStyleId] = st.[ProductStyleId]
          AND sv.[DeletedAtUtc] IS NULL
          AND sv.[ValueLongText] LIKE {$synonymParam} ESCAPE N'~'
    )
 )
SQL;
        }

        $normalizedCableType = $cableType === null ? '' : strtoupper(trim($cableType));
        if ($normalizedCableType !== '') {
            $where .= <<<'SQL'

 AND EXISTS (
    SELECT 1
    FROM [product].[ProductStyleSpecificationValues] cv
    INNER JOIN [product].[SpecificationDefinitions] cd
        ON cd.[SpecificationDefinitionId] = cv.[SpecificationDefinitionId]
       AND cd.[DefinitionCode] = N'CABLE-TYPE'
       AND cd.[DeletedAtUtc] IS NULL
    LEFT JOIN [product].[SpecificationOptions] co
        ON co.[SpecificationOptionId] = cv.[SpecificationOptionId]
       AND co.[DeletedAtUtc] IS NULL
    WHERE cv.[ProductStyleId] = st.[ProductStyleId]
      AND cv.[DeletedAtUtc] IS NULL
      AND co.[OptionCode] = :cableType
 )
SQL;
            $params[':cableType'] = $normalizedCableType;
        }

        return [$where, $params];
    }

    /** @return list<string> */
    private function textTokens(?string $text): array
    {
        $trimmed = trim((string) $text);
        if ($trimmed === '') {
            return [];
        }
        $tokens = preg_split('/\s+/', $trimmed);
        if ($tokens === false) {
            return [$trimmed];
        }

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    private function facetLiterals(): string
    {
        return implode(', ', array_map(
            static fn (string $facet): string => "N'" . str_replace("'", "''", $facet) . "'",
            self::FACETS,
        ));
    }

    /** @param array<string, mixed> $row */
    private function value(array $row): string|int|bool|null
    {
        $optionCode = AzureSqlValueMapper::nullableString($row['OptionCode'] ?? null);
        if ($optionCode !== null) {
            return $optionCode;
        }
        if (($row['ValueBoolean'] ?? null) !== null) {
            return AzureSqlValueMapper::boolValue($row['ValueBoolean']);
        }
        if (($row['ValueInteger'] ?? null) !== null) {
            return AzureSqlValueMapper::intValue($row['ValueInteger']);
        }
        $text = AzureSqlValueMapper::nullableString($row['ValueText'] ?? null);
        if ($text !== null) {
            return $text;
        }
        $longText = AzureSqlValueMapper::nullableString($row['ValueLongText'] ?? null);
        if ($longText !== null) {
            return $longText;
        }

        return null;
    }
}
