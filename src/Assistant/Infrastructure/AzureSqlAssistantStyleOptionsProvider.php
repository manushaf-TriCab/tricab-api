<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/** Reads the nine Phase 1 style facets without exposing the generic PIM tables. */
final readonly class AzureSqlAssistantStyleOptionsProvider implements AssistantStyleOptionsProvider
{
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

    public function list(array $styleCodes, ?string $cableType, int $limit): array
    {
        $safeLimit = max(1, min(30, $limit));
        $params = [];
        $where = '';
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

        $facetLiterals = implode(', ', array_map(
            static fn (string $facet): string => "N'" . str_replace("'", "''", $facet) . "'",
            self::FACETS,
        ));
        $sql = <<<SQL
WITH matched_styles AS (
    SELECT TOP ({$safeLimit})
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

        return array_values($styles);
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
