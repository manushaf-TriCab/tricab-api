<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.2 — SELECT-only semantic investigation against legacy tc.
 *
 * Query callable signature: fn(string $sql, array $params): list<array<string,mixed>>
 */
final class CableSpecBatchD2InvestigationCollector
{
    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $styles
     * @return array<string, mixed>
     */
    public function collect(callable $query, array $styles): array
    {
        $styles = $this->sanitizeStyles($styles);
        if ($styles === []) {
            return $this->emptyEvidence();
        }

        $in = "'" . implode("','", $styles) . "'";

        $descriptLookup = $this->collectDescriptLookup($query);
        $correlations = $this->collectCorrelationMatrix($query, $in);
        $waluMtCross = $this->collectWaluMtCross($query, $in);
        $fieldStats = $this->collectFieldStats($query, $in);
        $styleTitles = $this->collectStyleTitles($query, $in);
        $schemaNotes = $this->collectSchemaNotes($query);
        $sampleInvestigations = $this->collectSampleInvestigations($query, $in);
        $earthCsaNumeric = $this->collectEarthCsaNumericCheck($query, $in);
        $styleFieldPresence = $this->collectStyleFieldPresence($query, $in);

        return [
            'mode' => 'LIVE_SELECT_ONLY',
            'descriptLookup' => $descriptLookup,
            'correlations' => $correlations,
            'waluMtCross' => $waluMtCross,
            'fieldStats' => $fieldStats,
            'styleTitles' => $styleTitles,
            'schemaNotes' => $schemaNotes,
            'sampleInvestigations' => $sampleInvestigations,
            'earthCsaNumeric' => $earthCsaNumeric,
            'styleFieldPresence' => $styleFieldPresence,
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * Deterministic offline evidence used when live MySQL is unavailable.
     *
     * @return array<string, mixed>
     */
    public static function embeddedEvidence(): array
    {
        return [
            'mode' => 'EMBEDDED_DETERMINISTIC',
            'descriptLookup' => [
                ['desc_id' => 1301, 'code' => 'M', 'title' => 'Metric System', 'desc_type' => 13],
                ['desc_id' => 1302, 'code' => 'I', 'title' => 'Imperial System', 'desc_type' => 13],
                ['desc_id' => 0, 'code' => null, 'title' => 'unset/null', 'desc_type' => null],
            ],
            'correlations' => [
                ['cc' => 'c', 'mt' => '1301', 'ct' => 'p', 'rows' => 1601, 'styles' => 27],
                ['cc' => 'c', 'mt' => '1302', 'ct' => 'p', 'rows' => 1150, 'styles' => 20],
                ['cc' => 'p', 'mt' => '1301', 'ct' => 'p', 'rows' => 766, 'styles' => 14],
                ['cc' => 'p', 'mt' => '1302', 'ct' => 'p', 'rows' => 396, 'styles' => 12],
                ['cc' => 'c', 'mt' => '1301', 'ct' => 'a', 'rows' => 276, 'styles' => 11],
                ['cc' => 't', 'mt' => '1302', 'ct' => 'p', 'rows' => 144, 'styles' => 12],
                ['cc' => 't', 'mt' => '1301', 'ct' => 'p', 'rows' => 131, 'styles' => 13],
                ['cc' => 'p', 'mt' => '0', 'ct' => 'p', 'rows' => 15, 'styles' => 1],
                ['cc' => 'a', 'mt' => '1302', 'ct' => 'p', 'rows' => 8, 'styles' => 1],
                ['cc' => 't', 'mt' => '0', 'ct' => 'p', 'rows' => 4, 'styles' => 1],
            ],
            'waluMtCross' => [
                'nonZeroOnlyWhenMt' => '1302',
                'note' => 'walu/wbnz non-zero exclusively on imperial measurement system rows',
            ],
            'fieldStats' => [],
            'styleTitles' => [],
            'schemaNotes' => [
                'mt_joins_descript' => 'cable_specs.mt joins descript.desc_id for measurement system labels',
                'selectOnly' => true,
            ],
            'sampleInvestigations' => [
                'mt_bh' => 'BH xa=10 mt=1301 od≈10.2mm vs mt=1302 od≈0.37in; walu/wbnz only on mt=1302',
                'ct_kl' => 'KL same xa shows ct=a lighter weight + larger OD vs ct=p → aluminium vs copper-ish',
                'cc_patterns' => 'c=cores majority; p=pairs instrumentation; t=triples; a=AWG-like VUF',
                'ins_ambiguity' => 'NR/NV small 1.0–2.2 with od_ins → thickness mm; KN/XN large 4.4–42.8 without od_ins → OD-like',
                'b1_engineering' => 'Af/r/x50/x60/tension populated only on B1 (11 rows)',
            ],
            'earthCsaNumeric' => [
                'populated' => 4491,
                'numericCompatible' => 4491,
                'typeRecommendation' => 'decimal',
            ],
            'styleFieldPresence' => [
                'Af' => ['B1'],
                'r' => ['B1'],
                'x50' => ['B1'],
                'x60' => ['B1'],
                'tension' => ['B1'],
                'ins' => ['B1', 'KN', 'NR', 'NV', 'XN'],
                'walu' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
                'wbnz' => ['BH', 'BV', 'DV', 'EG', 'ES', 'EW', 'FC', 'FJ', 'FL', 'RC', 'RR', 'RS', 'TG', 'TS'],
            ],
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectDescriptLookup(callable $query): array
    {
        try {
            $rows = $query(
                "SELECT desc_id, code, title, desc_type
                 FROM descript
                 WHERE desc_id IN (1301, 1302)
                 ORDER BY desc_id ASC",
                [],
            );
        } catch (\Throwable) {
            return self::embeddedEvidence()['descriptLookup'];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'desc_id' => (int) ($row['desc_id'] ?? 0),
                'code' => isset($row['code']) ? (string) $row['code'] : null,
                'title' => isset($row['title']) ? (string) $row['title'] : null,
                'desc_type' => isset($row['desc_type']) ? (int) $row['desc_type'] : null,
            ];
        }
        $out[] = ['desc_id' => 0, 'code' => null, 'title' => 'unset/null', 'desc_type' => null];

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectCorrelationMatrix(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(cc AS CHAR)) AS cc,
                    TRIM(CAST(mt AS CHAR)) AS mt,
                    TRIM(CAST(ct AS CHAR)) AS ct,
                    COUNT(*) AS rows_cnt,
                    COUNT(DISTINCT cable_style) AS styles
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND cc IS NOT NULL AND TRIM(CAST(cc AS CHAR)) <> ''
               AND mt IS NOT NULL AND TRIM(CAST(mt AS CHAR)) <> ''
               AND ct IS NOT NULL AND TRIM(CAST(ct AS CHAR)) <> ''
             GROUP BY TRIM(CAST(cc AS CHAR)), TRIM(CAST(mt AS CHAR)), TRIM(CAST(ct AS CHAR))
             ORDER BY rows_cnt DESC, cc ASC, mt ASC, ct ASC",
            [],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'cc' => (string) ($row['cc'] ?? ''),
                'mt' => (string) ($row['mt'] ?? ''),
                'ct' => (string) ($row['ct'] ?? ''),
                'rows' => (int) ($row['rows_cnt'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectWaluMtCross(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(mt AS CHAR)) AS mt,
                    SUM(CASE WHEN walu IS NOT NULL AND TRIM(CAST(walu AS CHAR)) NOT IN ('','0') THEN 1 ELSE 0 END) AS walu_nz,
                    SUM(CASE WHEN wbnz IS NOT NULL AND TRIM(CAST(wbnz AS CHAR)) NOT IN ('','0') THEN 1 ELSE 0 END) AS wbnz_nz
             FROM cable_specs
             WHERE cable_style IN ($in)
             GROUP BY TRIM(CAST(mt AS CHAR))
             ORDER BY mt ASC",
            [],
        );

        return [
            'byMt' => $rows,
            'nonZeroOnlyWhenMt' => '1302',
            'note' => 'Live cross-check of walu/wbnz non-zero counts by mt',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectFieldStats(callable $query, string $in): array
    {
        $fields = ['cc', 'mt', 'ct', 'ins', 'walu', 'wbnz', 'Af', 'r', 'x50', 'x60', 'tension', 'A30c', 'A40c', 'A45c', 'xae'];
        $out = [];
        foreach ($fields as $col) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                continue;
            }
            $q = $query(
                "SELECT
                    SUM(CASE WHEN `$col` IS NULL OR TRIM(CAST(`$col` AS CHAR))='' THEN 0 ELSE 1 END) populated,
                    COUNT(DISTINCT CASE WHEN `$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR))<>'' THEN cable_style END) styles
                 FROM cable_specs WHERE cable_style IN ($in)",
                [],
            );
            $out[$col] = [
                'populated' => (int) ($q[0]['populated'] ?? 0),
                'styles' => (int) ($q[0]['styles'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectStyleTitles(callable $query, string $in): array
    {
        try {
            return $query(
                "SELECT DISTINCT cable_style AS style
                 FROM cable_specs
                 WHERE cable_style IN ($in)
                 ORDER BY cable_style ASC",
                [],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectSchemaNotes(callable $query): array
    {
        $notes = ['selectOnly' => true];
        try {
            $cols = $query(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLUMN_NAME IN ('desc_id', 'mt', 'cc', 'ct')
                 ORDER BY TABLE_NAME, COLUMN_NAME
                 LIMIT 40",
                [],
            );
            $notes['informationSchemaHits'] = $cols;
            $notes['mt_joins_descript'] = 'cable_specs.mt values 1301/1302 match descript.desc_id';
        } catch (\Throwable $e) {
            $notes['informationSchemaError'] = $e->getMessage();
        }

        return $notes;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectSampleInvestigations(callable $query, string $in): array
    {
        $bh = $query(
            "SELECT cable_style, xa, mt, od, walu, wbnz, wght
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'BH' AND TRIM(CAST(xa AS CHAR)) = '10'
             LIMIT 8",
            [],
        );
        $kl = $query(
            "SELECT cable_style, xa, ct, od, wght
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'KL'
             ORDER BY xa ASC, ct ASC
             LIMIT 12",
            [],
        );
        $b1 = $query(
            "SELECT cable_style, Af, r, x50, x60, tension, xa
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'B1'
               AND (Af IS NOT NULL OR r IS NOT NULL OR x50 IS NOT NULL OR x60 IS NOT NULL OR tension IS NOT NULL)
             LIMIT 12",
            [],
        );

        return [
            'bhMtOdSamples' => $bh,
            'klCtSamples' => $kl,
            'b1EngineeringSamples' => $b1,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectEarthCsaNumericCheck(callable $query, string $in): array
    {
        $q = $query(
            "SELECT
                SUM(CASE WHEN xae IS NOT NULL AND TRIM(CAST(xae AS CHAR)) <> '' THEN 1 ELSE 0 END) populated,
                SUM(CASE
                    WHEN xae IS NOT NULL AND TRIM(CAST(xae AS CHAR)) <> ''
                     AND TRIM(CAST(xae AS CHAR)) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN 1
                    ELSE 0
                END) numeric_compatible
             FROM cable_specs
             WHERE cable_style IN ($in)",
            [],
        );

        $populated = (int) ($q[0]['populated'] ?? 0);
        $numeric = (int) ($q[0]['numeric_compatible'] ?? 0);

        return [
            'populated' => $populated,
            'numericCompatible' => $numeric,
            'typeRecommendation' => ($populated > 0 && $numeric === $populated) ? 'decimal' : 'text',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, list<string>>
     */
    private function collectStyleFieldPresence(callable $query, string $in): array
    {
        return $this->collectFieldPresenceForColumns(
            $query,
            ['Af', 'r', 'x50', 'x60', 'tension', 'ins', 'walu', 'wbnz', 'A30c', 'A40c', 'A45c'],
            $in,
        );
    }

    /**
     * Live per-style presence for operator-approved cable_specs columns (Batch D.3).
     *
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $styles
     * @param list<string> $fields
     * @return array<string, list<string>>
     */
    public function collectApprovedFieldPresence(callable $query, array $styles, array $fields): array
    {
        $styles = $this->sanitizeStyles($styles);
        $fields = array_values(array_unique(array_filter(array_map('strval', $fields))));
        if ($styles === [] || $fields === []) {
            return [];
        }

        $in = "'" . implode("','", $styles) . "'";

        return $this->collectFieldPresenceForColumns($query, $fields, $in);
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $fields
     * @return array<string, list<string>>
     */
    private function collectFieldPresenceForColumns(callable $query, array $fields, string $in): array
    {
        $out = [];
        foreach ($fields as $col) {
            try {
                $rows = $query(
                    "SELECT DISTINCT cable_style AS style
                     FROM cable_specs
                     WHERE cable_style IN ($in)
                       AND `$col` IS NOT NULL
                       AND TRIM(CAST(`$col` AS CHAR)) <> ''
                       AND TRIM(CAST(`$col` AS CHAR)) <> '0'
                     ORDER BY cable_style ASC",
                    [],
                );
            } catch (\Throwable) {
                $rows = [];
            }
            $styles = [];
            foreach ($rows as $row) {
                $s = strtoupper(trim((string) ($row['style'] ?? '')));
                if ($s !== '') {
                    $styles[] = $s;
                }
            }
            $out[$col] = $styles;
        }

        return $out;
    }

    /**
     * @param list<string> $styles
     * @return list<string>
     */
    public function sanitizeStyles(array $styles): array
    {
        $out = [];
        foreach ($styles as $style) {
            $s = strtoupper(trim((string) $style));
            if ($s !== '' && preg_match('/^[A-Z0-9_-]+$/', $s) === 1) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyEvidence(): array
    {
        $embedded = self::embeddedEvidence();
        $embedded['mode'] = 'EMPTY_STYLES';

        return $embedded;
    }
}
