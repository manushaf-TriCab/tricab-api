<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.5 — deep SELECT-only investigation for remaining REVIEW fields.
 */
final class CableSpecBatchD5InvestigationCollector
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
            return self::embeddedEvidence();
        }

        $in = "'" . implode("','", $styles) . "'";
        $d2 = new CableSpecBatchD2InvestigationCollector();

        return [
            'mode' => 'LIVE_SELECT_ONLY',
            'd2Echo' => $d2->collect($query, $styles),
            'ccByValue' => $this->collectCcByValue($query, $in),
            'ccCrossNcXa' => $this->collectCcCrossNcXa($query, $in),
            'ccValueA' => $this->collectCcValueA($query, $in),
            'ctByValue' => $this->collectCtByValue($query, $in),
            'ctPhysicalSamples' => $this->collectCtPhysicalSamples($query, $in),
            'ratingCtEncoding' => $this->collectRatingCtEncoding($query),
            'waluWbnzUnitAnalysis' => $this->collectWaluWbnzUnitAnalysis($query, $in),
            'insByStyle' => $this->collectInsByStyle($query, $in),
            'insDimensionalCheck' => $this->collectInsDimensionalCheck($query, $in),
            'b1FieldSamples' => $this->collectB1FieldSamples($query, $in),
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function embeddedEvidence(): array
    {
        $d2 = CableSpecBatchD2InvestigationCollector::embeddedEvidence();

        return [
            'mode' => 'EMBEDDED_DETERMINISTIC',
            'd2Echo' => $d2,
            'ccByValue' => [
                ['value' => 'c', 'rows' => 3027, 'styles' => 27, 'distinctNc' => 12, 'ncSamples' => ['1', '2', '3', '4'], 'ctDist' => ['p' => 2876, 'a' => 151]],
                ['value' => 'p', 'rows' => 1177, 'styles' => 14, 'distinctNc' => 6, 'ncSamples' => ['2', '3'], 'ctDist' => ['p' => 1177]],
                ['value' => 't', 'rows' => 279, 'styles' => 13, 'distinctNc' => 4, 'ncSamples' => ['3'], 'ctDist' => ['p' => 279]],
                ['value' => 'a', 'rows' => 8, 'styles' => 1, 'distinctNc' => 1, 'ncSamples' => ['1'], 'ctDist' => ['p' => 8], 'styleSamples' => ['VUF']],
            ],
            'ccCrossNcXa' => [
                'note' => 'cc=c rows dominate with nc populated; cc=p often pairs (nc=2); cc=t triples (nc=3); cc=a only VUF',
            ],
            'ccValueA' => [
                'styles' => ['VUF'],
                'rows' => 8,
                'samples' => [['cable_style' => 'VUF', 'cc' => 'a', 'nc' => 1, 'xa' => 10, 'ct' => 'p', 'mt' => '1302']],
                'awgHypothesis' => 'LOW — single imperial style; not proven AWG encoding',
            ],
            'ctByValue' => [
                ['value' => 'p', 'rows' => 4215, 'styles' => 43, 'avgWght' => null, 'avgOd' => null],
                ['value' => 'a', 'rows' => 276, 'styles' => 11, 'avgWght' => null, 'avgOd' => null],
            ],
            'ctPhysicalSamples' => [
                'klComparison' => 'KL xa=1.0 ct=p wght lower vs ct=a wght higher OD larger — supports aluminium vs copper hypothesis MEDIUM only',
                'ratingEncodingDiffers' => 'rating.ct uses C/A; cable_specs.ct uses p/a — not same alphabet',
            ],
            'ratingCtEncoding' => [
                'tableExists' => false,
                'note' => 'rating table not queried in embedded mode',
            ],
            'waluWbnzUnitAnalysis' => [
                'onlyMt1302' => true,
                'wghtRatioSamples' => [
                    'note' => 'walu/wbnz populated only imperial; wght uses KG_PER_KM target — walu likely imperial mass/length lb/1000ft MEDIUM',
                ],
                'proposedUnitCandidate' => 'LB_PER_1000FT',
                'existingTargetUnit' => null,
            ],
            'insByStyle' => [
                'B1' => ['rows' => 11, 'insMin' => 0.5, 'insMax' => 2.0, 'od_ins' => null],
                'KN' => ['rows' => 1, 'ins' => 42.8, 'od_ins' => null],
                'NR' => ['rows' => 1, 'ins' => 1.0, 'od_ins' => 2.2, 'cdia' => 0.8],
                'NV' => ['rows' => 1, 'ins' => 1.2, 'od_ins' => 2.4, 'cdia' => 1.0],
                'XN' => ['rows' => 1, 'ins' => 4.4, 'od_ins' => null],
            ],
            'insDimensionalCheck' => [
                'matchesThicknessFormula' => 2,
                'totalWithCdiaOdIns' => 2,
                'note' => 'NR/NV only — ins≈(od_ins-cdia)/2 holds for 2 rows; KN/XN lack od_ins — TYPE_AMBIGUITY remains',
            ],
            'b1FieldSamples' => [
                'style' => 'B1',
                'Af' => ['min' => 10, 'max' => 100, 'unitUnknown' => true],
                'r' => ['min' => 0.1, 'max' => 1.2, 'unitUnknown' => true],
                'x50' => ['populated' => true],
                'x60' => ['populated' => true],
                'tension' => ['populated' => true],
            ],
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectCcByValue(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(cc AS CHAR)) AS value,
                    COUNT(*) AS rows_cnt,
                    COUNT(DISTINCT cable_style) AS styles,
                    COUNT(DISTINCT nc) AS distinct_nc
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND cc IS NOT NULL AND TRIM(CAST(cc AS CHAR)) <> ''
             GROUP BY TRIM(CAST(cc AS CHAR))
             ORDER BY rows_cnt DESC",
            [],
        );

        $out = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            $ctDist = $this->distributionForField($query, $in, 'cc', $value, 'ct');
            $mtDist = $this->distributionForField($query, $in, 'cc', $value, 'mt');
            $ncSamples = $this->sampleDistinct($query, $in, 'cc', $value, 'nc', 8);
            $xaSamples = $this->sampleDistinct($query, $in, 'cc', $value, 'xa', 8);
            $styleSamples = $this->stylesForFieldValue($query, $in, 'cc', $value, 12);

            $out[] = [
                'value' => $value,
                'rows' => (int) ($row['rows_cnt'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 0),
                'distinctNc' => (int) ($row['distinct_nc'] ?? 0),
                'ncSamples' => $ncSamples,
                'xaSamples' => $xaSamples,
                'ctDistribution' => $ctDist,
                'mtDistribution' => $mtDist,
                'styleSamples' => $styleSamples,
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCcCrossNcXa(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(cc AS CHAR)) AS cc,
                    TRIM(CAST(nc AS CHAR)) AS nc,
                    COUNT(*) AS rows_cnt
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND cc IS NOT NULL AND TRIM(CAST(cc AS CHAR)) <> ''
               AND nc IS NOT NULL AND TRIM(CAST(nc AS CHAR)) <> ''
             GROUP BY TRIM(CAST(cc AS CHAR)), TRIM(CAST(nc AS CHAR))
             ORDER BY cc ASC, rows_cnt DESC
             LIMIT 40",
            [],
        );

        return ['matrix' => $rows, 'note' => 'cc × nc frequency (top 40)'];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCcValueA(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT cable_style, cc, nc, xa, ct, mt, od, wght
             FROM cable_specs
             WHERE cable_style IN ($in) AND TRIM(CAST(cc AS CHAR)) = 'a'
             ORDER BY cable_style ASC, xa ASC
             LIMIT 20",
            [],
        );
        $styles = [];
        foreach ($rows as $row) {
            $s = strtoupper(trim((string) ($row['cable_style'] ?? '')));
            if ($s !== '') {
                $styles[] = $s;
            }
        }

        return [
            'rows' => count($rows),
            'styles' => array_values(array_unique($styles)),
            'samples' => $rows,
            'awgHypothesis' => count($styles) === 1 && in_array('VUF', $styles, true)
                ? 'LOW — single style VUF; not proven AWG encoding'
                : 'LOW — insufficient evidence for AWG',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectCtByValue(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(ct AS CHAR)) AS value,
                    COUNT(*) AS rows_cnt,
                    COUNT(DISTINCT cable_style) AS styles,
                    AVG(CAST(wght AS DECIMAL(18,4))) AS avg_wght,
                    AVG(CAST(od AS DECIMAL(18,4))) AS avg_od
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND ct IS NOT NULL AND TRIM(CAST(ct AS CHAR)) <> ''
             GROUP BY TRIM(CAST(ct AS CHAR))
             ORDER BY rows_cnt DESC",
            [],
        );

        $out = [];
        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            $out[] = [
                'value' => $value,
                'rows' => (int) ($row['rows_cnt'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 0),
                'avgWght' => $row['avg_wght'] ?? null,
                'avgOd' => $row['avg_od'] ?? null,
                'styleSamples' => $this->stylesForFieldValue($query, $in, 'ct', $value, 10),
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCtPhysicalSamples(callable $query, string $in): array
    {
        $kl = $query(
            "SELECT cable_style, xa, ct, od, wght, walu, wbnz
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'KL'
             ORDER BY xa ASC, ct ASC
             LIMIT 20",
            [],
        );

        return [
            'klSamples' => $kl,
            'note' => 'Compare ct=p vs ct=a on same style/xa — physical correlation only',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectRatingCtEncoding(callable $query): array
    {
        try {
            $tables = $query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rating'
                 LIMIT 1",
                [],
            );
            if ($tables === []) {
                return ['tableExists' => false, 'note' => 'rating table not found in tc schema'];
            }
            $dist = $query(
                "SELECT TRIM(CAST(ct AS CHAR)) AS ct, COUNT(*) AS cnt
                 FROM rating
                 WHERE ct IS NOT NULL AND TRIM(CAST(ct AS CHAR)) <> ''
                 GROUP BY TRIM(CAST(ct AS CHAR))
                 ORDER BY cnt DESC
                 LIMIT 10",
                [],
            );

            return [
                'tableExists' => true,
                'ctDistribution' => $dist,
                'note' => 'rating.ct encoding may differ from cable_specs.ct (C/A vs p/a)',
            ];
        } catch (\Throwable $e) {
            return ['tableExists' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectWaluWbnzUnitAnalysis(callable $query, string $in): array
    {
        $ratio = $query(
            "SELECT cable_style, mt, wght, walu, wbnz,
                    CASE WHEN walu > 0 AND wght > 0 THEN wght / walu ELSE NULL END AS wght_per_walu
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND mt = 1302
               AND walu IS NOT NULL AND walu > 0
               AND wght IS NOT NULL AND wght > 0
             ORDER BY cable_style ASC
             LIMIT 30",
            [],
        );

        $byMt = $query(
            "SELECT TRIM(CAST(mt AS CHAR)) AS mt,
                    AVG(walu) AS avg_walu,
                    AVG(wbnz) AS avg_wbnz,
                    AVG(wght) AS avg_wght,
                    COUNT(*) AS rows_cnt
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND (walu > 0 OR wbnz > 0)
             GROUP BY TRIM(CAST(mt AS CHAR))",
            [],
        );

        return [
            'imperialRatioSamples' => $ratio,
            'byMt' => $byMt,
            'note' => 'walu/wbnz only on mt=1302; compare wght (kg/km target) ratio for unit inference — MEDIUM not HIGH',
            'proposedUnitCandidate' => 'LB_PER_1000FT',
            'confidence' => 'MEDIUM',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<array<string, mixed>>
     */
    private function collectInsByStyle(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT cable_style AS style,
                    COUNT(*) AS rows_cnt,
                    MIN(ins) AS ins_min,
                    MAX(ins) AS ins_max,
                    MIN(od_ins) AS od_ins_min,
                    MAX(od_ins) AS od_ins_max,
                    MIN(cdia) AS cdia_min,
                    MAX(cdia) AS cdia_max
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND ins IS NOT NULL AND TRIM(CAST(ins AS CHAR)) <> '' AND ins <> 0
             GROUP BY cable_style
             ORDER BY cable_style ASC",
            [],
        );

        return $rows;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectInsDimensionalCheck(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT cable_style, ins, od_ins, cdia,
                    (od_ins - cdia) / 2 AS implied_thickness,
                    ABS(ins - ((od_ins - cdia) / 2)) AS thickness_delta
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND ins IS NOT NULL AND ins <> 0
               AND od_ins IS NOT NULL AND od_ins > 0
               AND cdia IS NOT NULL AND cdia > 0
             ORDER BY thickness_delta ASC
             LIMIT 20",
            [],
        );

        $matches = 0;
        foreach ($rows as $row) {
            $delta = (float) ($row['thickness_delta'] ?? 999);
            if ($delta < 0.15) {
                ++$matches;
            }
        }

        return [
            'samples' => $rows,
            'matchesThicknessFormula' => $matches,
            'totalWithCdiaOdIns' => count($rows),
            'note' => 'ins ≈ (od_ins - cdia)/2 — supporting evidence only; TYPE_AMBIGUITY if styles disagree',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectB1FieldSamples(callable $query, string $in): array
    {
        $row = $query(
            "SELECT MIN(Af) AS af_min, MAX(Af) AS af_max,
                    MIN(r) AS r_min, MAX(r) AS r_max,
                    MIN(x50) AS x50_min, MAX(x50) AS x50_max,
                    MIN(x60) AS x60_min, MAX(x60) AS x60_max,
                    MIN(tension) AS tension_min, MAX(tension) AS tension_max,
                    COUNT(*) AS rows_cnt
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'B1'",
            [],
        );

        $samples = $query(
            "SELECT cable_style, Af, r, x50, x60, tension, xa, od
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'B1'
               AND (Af IS NOT NULL OR r IS NOT NULL OR x50 IS NOT NULL OR x60 IS NOT NULL OR tension IS NOT NULL)
             LIMIT 15",
            [],
        );

        return [
            'aggregate' => $row[0] ?? [],
            'samples' => $samples,
            'style' => 'B1',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, int>
     */
    private function distributionForField(callable $query, string $in, string $filterField, string $filterValue, string $targetField): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $filterField) || !preg_match('/^[A-Za-z0-9_]+$/', $targetField)) {
            return [];
        }
        $escaped = str_replace("'", "''", $filterValue);
        $rows = $query(
            "SELECT TRIM(CAST(`$targetField` AS CHAR)) AS v, COUNT(*) AS cnt
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND TRIM(CAST(`$filterField` AS CHAR)) = '$escaped'
               AND `$targetField` IS NOT NULL AND TRIM(CAST(`$targetField` AS CHAR)) <> ''
             GROUP BY TRIM(CAST(`$targetField` AS CHAR))
             ORDER BY cnt DESC
             LIMIT 8",
            [],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($row['v'] ?? '')] = (int) ($row['cnt'] ?? 0);
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<string>
     */
    private function sampleDistinct(callable $query, string $in, string $filterField, string $filterValue, string $col, int $limit): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $filterField) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            return [];
        }
        $escaped = str_replace("'", "''", $filterValue);
        $rows = $query(
            "SELECT DISTINCT TRIM(CAST(`$col` AS CHAR)) AS v
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND TRIM(CAST(`$filterField` AS CHAR)) = '$escaped'
               AND `$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR)) <> ''
             ORDER BY v ASC
             LIMIT $limit",
            [],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string) ($row['v'] ?? '');
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return list<string>
     */
    private function stylesForFieldValue(callable $query, string $in, string $field, string $value, int $limit): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            return [];
        }
        $escaped = str_replace("'", "''", $value);
        $rows = $query(
            "SELECT DISTINCT cable_style AS style
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND TRIM(CAST(`$field` AS CHAR)) = '$escaped'
             ORDER BY cable_style ASC
             LIMIT $limit",
            [],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = strtoupper(trim((string) ($row['style'] ?? '')));
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
}
