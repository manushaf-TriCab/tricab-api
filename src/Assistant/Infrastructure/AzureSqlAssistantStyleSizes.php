<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantStyleSizes;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * Sizes are TriCab's nominal metric equivalent where the index declares one,
 * the parsed area otherwise — the value a customer asks for, not the derating
 * input.
 *
 * Current codes only, when the style has any: a discontinued size offered in
 * the picker is a size the agent then has to talk the customer out of.
 */
final readonly class AzureSqlAssistantStyleSizes implements AssistantStyleSizes
{
    public function __construct(private SqlConnection $connection)
    {
    }

    public function forStyle(string $styleCode): array
    {
        $style = strtoupper(trim($styleCode));
        if ($style === '') {
            return [];
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    i.[CoreCount],
    COALESCE(i.[CrossSectionNominalMm2], i.[CrossSectionMm2]) AS [SizeMm2],
    MAX(CAST(i.[IsCurrent] AS INT)) AS [AnyCurrent]
FROM [product].[ProductCodeIndex] i
WHERE i.[StyleCode] = :style
  AND i.[CoreTypeCode] = N'C'
  AND i.[HasParseAnomaly] = 0
  AND i.[CoreCount] IS NOT NULL
  AND COALESCE(i.[CrossSectionNominalMm2], i.[CrossSectionMm2]) IS NOT NULL
GROUP BY i.[CoreCount], COALESCE(i.[CrossSectionNominalMm2], i.[CrossSectionMm2])
ORDER BY i.[CoreCount], [SizeMm2]
SQL,
            ['style' => $style],
        );

        $anyCurrent = false;
        foreach ($rows as $row) {
            if (AzureSqlValueMapper::intValue($row['AnyCurrent'] ?? 0) === 1) {
                $anyCurrent = true;
                break;
            }
        }

        $byCores = [];
        foreach ($rows as $row) {
            if ($anyCurrent && AzureSqlValueMapper::intValue($row['AnyCurrent'] ?? 0) !== 1) {
                continue;
            }
            $cores = AzureSqlValueMapper::intValue($row['CoreCount'] ?? 0);
            // DECIMAL arrives from the driver as a string.
            $rawSize = $row['SizeMm2'] ?? null;
            $size = is_numeric($rawSize) ? (float) $rawSize : 0.0;
            if ($cores < 1 || $size <= 0) {
                continue;
            }
            $byCores[$cores][] = $size;
        }

        $options = [];
        foreach ($byCores as $cores => $sizes) {
            $options[] = ['coreCount' => $cores, 'sizesMm2' => $sizes];
        }

        return $options;
    }
}
