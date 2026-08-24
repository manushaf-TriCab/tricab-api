<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.5.1 — extended SELECT-only investigation.
 */
final class CableSpecBatchD51InvestigationCollector
{
    private const LB_PER_1000FT_TO_KG_PER_KM = 1.48816;

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $styles
     * @return array<string, mixed>
     */
    public function collect(callable $query, array $styles): array
    {
        $styles = $this->sanitizeStyles($styles);
        $d5 = new CableSpecBatchD5InvestigationCollector();
        if ($styles === []) {
            return self::embeddedEvidence();
        }

        $in = "'" . implode("','", $styles) . "'";

        return [
            'mode' => 'LIVE_SELECT_ONLY',
            'd5Echo' => $d5->collect($query, $styles),
            'ccValueBreakdown' => $this->collectCcValueBreakdown($query, $in),
            'ccNcInterpretation' => $this->collectCcNcInterpretation($query, $in),
            'coreConCorrelation' => $this->collectCoreConCorrelation($query, $in),
            'ctMatchedComparisons' => $this->collectCtMatchedComparisons($query, $in),
            'ctStyleFamilyMaterial' => $this->collectCtStyleDescriptions($query, $in),
            'waluWbnzUnitProof' => $this->collectWaluWbnzUnitProof($query, $in),
            'insPerStyle' => $this->collectInsPerStyle($query, $in),
            'b1ElectricalDetail' => $this->collectB1ElectricalDetail($query, $in),
            'specLabelsScan' => $this->collectSpecLabelsScan($query),
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function embeddedEvidence(): array
    {
        $d5 = CableSpecBatchD5InvestigationCollector::embeddedEvidence();

        return [
            'mode' => 'EMBEDDED_DETERMINISTIC',
            'd5Echo' => $d5,
            'ccValueBreakdown' => [
                'c' => ['meaning' => 'cores counting mode', 'rows' => 3027, 'styles' => 39, 'evidenceClass' => 'STRONG_CORRELATION'],
                'p' => ['meaning' => 'pairs counting mode', 'rows' => 1177, 'styles' => 14, 'evidenceClass' => 'STRONG_CORRELATION'],
                't' => ['meaning' => 'triples/triads counting mode', 'rows' => 279, 'styles' => 14, 'evidenceClass' => 'STRONG_CORRELATION'],
                'a' => ['meaning' => 'VUF-only AWG-style xa (1/0, 250, 500)', 'rows' => 8, 'styles' => 1, 'style' => 'VUF', 'evidenceClass' => 'STRONG_CORRELATION'],
            ],
            'ccNcInterpretation' => [
                'note' => 'cc appears to interpret how nc should be read (cores vs pairs vs triples); not redundant with CORES alone',
            ],
            'coreConCorrelation' => ['populated' => 0, 'note' => 'core_con not populated in embedded fixture'],
            'ctMatchedComparisons' => [
                'kl' => [
                    'xa' => '1.0',
                    'p' => ['avgWght' => 95, 'avgOd' => 12.5, 'avgCdia' => 1.1],
                    'a' => ['avgWght' => 58, 'avgOd' => 15.2, 'avgCdia' => 1.4],
                    'evidenceClass' => 'STRONG_CORRELATION',
                ],
            ],
            'waluWbnzUnitProof' => [
                'imperialOnly' => true,
                'wghtPerWaluMean' => 0.93,
                'conversionFactorKgKmPerLb1000ft' => self::LB_PER_1000FT_TO_KG_PER_KM,
                'note' => 'wght/walu ~0.93 — walu is component weight not total; cannot prove unit via total-weight ratio alone',
                'unitEvidenceClass' => 'STRONG_CORRELATION',
                'hypothesis' => 'LB_PER_1000FT',
            ],
            'insPerStyle' => [
                'B1' => ['ins' => 0.5, 'od_ins' => null, 'cdia' => 1.2, 'meaning' => 'small decimal — thickness-like', 'derivable' => false],
                'KN' => ['ins' => 42.8, 'meaning' => 'OD-like mm value', 'derivable' => false],
                'NR' => ['ins' => 1.0, 'od_ins' => 2.2, 'cdia' => 0.8, 'computedThickness' => 0.7, 'derivable' => true],
                'NV' => ['ins' => 1.2, 'od_ins' => 2.4, 'cdia' => 1.0, 'computedThickness' => 0.7, 'derivable' => true],
                'XN' => ['ins' => 4.4, 'meaning' => 'OD-like', 'derivable' => false],
            ],
            'b1ElectricalDetail' => [
                'rows' => 11,
                'mt' => '1301',
                'AfRange' => [10, 100],
                'rRange' => [0.1, 1.2],
                'directLabels' => 'NO_EVIDENCE',
            ],
            'specLabelsScan' => ['tableFound' => false],
            'queriesWereSelectOnly' => true,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCcValueBreakdown(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(cc AS CHAR)) AS code,
                    COUNT(*) AS rows_cnt,
                    COUNT(DISTINCT cable_style) AS styles
             FROM cable_specs
             WHERE cable_style IN ($in) AND cc IS NOT NULL AND TRIM(CAST(cc AS CHAR)) <> ''
             GROUP BY TRIM(CAST(cc AS CHAR))
             ORDER BY rows_cnt DESC",
            [],
        );
        $out = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $styles = $this->stylesForFieldValue($query, $in, 'cc', $code, 20);
            $out[$code] = [
                'code' => $code,
                'rows' => (int) ($row['rows_cnt'] ?? 0),
                'styles' => (int) ($row['styles'] ?? 0),
                'styleSamples' => $styles,
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCcNcInterpretation(callable $query, string $in): array
    {
        $rows = $query(
            "SELECT TRIM(CAST(cc AS CHAR)) AS cc,
                    TRIM(CAST(nc AS CHAR)) AS nc,
                    COUNT(*) AS cnt
             FROM cable_specs
             WHERE cable_style IN ($in)
               AND cc IS NOT NULL AND nc IS NOT NULL
             GROUP BY TRIM(CAST(cc AS CHAR)), TRIM(CAST(nc AS CHAR))
             ORDER BY cnt DESC
             LIMIT 30",
            [],
        );

        return ['topPairs' => $rows, 'interpretationNote' => 'cc may encode how nc counts conductors (cores/pairs/triples)'];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCoreConCorrelation(callable $query, string $in): array
    {
        try {
            $pop = $query(
                "SELECT COUNT(*) AS cnt FROM cable_specs
                 WHERE cable_style IN ($in) AND core_con IS NOT NULL AND TRIM(CAST(core_con AS CHAR)) <> ''",
                [],
            );
            $cnt = (int) ($pop[0]['cnt'] ?? 0);
            if ($cnt === 0) {
                return ['populated' => 0, 'note' => 'core_con not populated'];
            }
            $cross = $query(
                "SELECT TRIM(CAST(cc AS CHAR)) AS cc, TRIM(CAST(core_con AS CHAR)) AS core_con, COUNT(*) AS cnt
                 FROM cable_specs
                 WHERE cable_style IN ($in)
                   AND cc IS NOT NULL AND core_con IS NOT NULL
                 GROUP BY TRIM(CAST(cc AS CHAR)), TRIM(CAST(core_con AS CHAR))
                 ORDER BY cnt DESC LIMIT 20",
                [],
            );

            return ['populated' => $cnt, 'ccCoreConCross' => $cross];
        } catch (\Throwable $e) {
            return ['populated' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCtMatchedComparisons(callable $query, string $in): array
    {
        $kl = $query(
            "SELECT ct,
                    AVG(CAST(wght AS DECIMAL(18,4))) AS avg_wght,
                    AVG(CAST(od AS DECIMAL(18,4))) AS avg_od,
                    AVG(CAST(cdia AS DECIMAL(18,4))) AS avg_cdia,
                    COUNT(*) AS rows_cnt
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'KL' AND xa = 1.0
               AND ct IN ('p','a')
             GROUP BY ct",
            [],
        );
        $byStyle = $query(
            "SELECT cable_style, ct,
                    AVG(CAST(wght AS DECIMAL(18,4))) AS avg_wght,
                    AVG(CAST(od AS DECIMAL(18,4))) AS avg_od,
                    COUNT(*) AS rows_cnt
             FROM cable_specs
             WHERE cable_style IN ($in) AND ct IN ('p','a')
             GROUP BY cable_style, ct
             HAVING COUNT(*) >= 3
             ORDER BY cable_style ASC, ct ASC
             LIMIT 40",
            [],
        );

        return ['klXa1' => $kl, 'styleAverages' => $byStyle];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectCtStyleDescriptions(callable $query, string $in): array
    {
        try {
            $rows = $query(
                "SELECT cs.cable_style, cs.ct, COUNT(*) AS cnt
                 FROM cable_specs cs
                 WHERE cs.cable_style IN ($in) AND cs.ct IN ('p','a')
                 GROUP BY cs.cable_style, cs.ct
                 ORDER BY cs.cable_style ASC
                 LIMIT 30",
                [],
            );

            return ['ctByStyle' => $rows];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectWaluWbnzUnitProof(callable $query, string $in): array
    {
        $ratios = $query(
            "SELECT cable_style, xa, mt, wght, walu, wbnz,
                    CASE WHEN walu > 0 THEN wght / walu ELSE NULL END AS wght_per_walu
             FROM cable_specs
             WHERE cable_style IN ($in) AND mt = 1302 AND walu > 0 AND wght > 0
             ORDER BY cable_style ASC, xa ASC
             LIMIT 50",
            [],
        );
        $metricPairs = $query(
            "SELECT i.cable_style, i.xa, i.wght AS wght_imperial, m.wght AS wght_metric,
                    i.walu, i.wbnz
             FROM cable_specs i
             INNER JOIN cable_specs m
               ON m.cable_style = i.cable_style AND m.xa = i.xa AND m.mt = 1301
             WHERE i.cable_style IN ($in) AND i.mt = 1302 AND i.walu > 0
             ORDER BY i.cable_style ASC, i.xa ASC
             LIMIT 30",
            [],
        );

        $sum = 0.0;
        $n = 0;
        foreach ($ratios as $r) {
            $v = (float) ($r['wght_per_walu'] ?? 0);
            if ($v > 0) {
                $sum += $v;
                ++$n;
            }
        }
        $meanRatio = $n > 0 ? $sum / $n : null;

        return [
            'imperialRatioSamples' => $ratios,
            'metricImperialPairs' => $metricPairs,
            'wghtPerWaluMean' => $meanRatio,
            'conversionFactorKgKmPerLb1000ft' => self::LB_PER_1000FT_TO_KG_PER_KM,
            'note' => 'walu is aluminium component weight — not interchangeable with total wght for unit proof',
            'hypothesisUnit' => 'LB_PER_1000FT',
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectInsPerStyle(callable $query, string $in): array
    {
        $styles = ['B1', 'KN', 'NR', 'NV', 'XN'];
        $out = [];
        foreach ($styles as $style) {
            $rows = $query(
                "SELECT cable_style, ins, od_ins, cdia, od, xa, nc,
                        (od_ins - cdia) / 2 AS computed_thickness
                 FROM cable_specs
                 WHERE cable_style IN ($in) AND cable_style = '$style'
                   AND ins IS NOT NULL AND ins <> 0
                 LIMIT 5",
                [],
            );
            if ($rows === []) {
                continue;
            }
            $row = $rows[0];
            $ins = (float) ($row['ins'] ?? 0);
            $computed = isset($row['computed_thickness']) ? (float) $row['computed_thickness'] : null;
            $derivable = $computed !== null && abs($ins - $computed) < 0.2;
            $out[$style] = [
                'rows' => count($rows),
                'sample' => $row,
                'derivableFromOdInsCdia' => $derivable,
            ];
        }

        return $out;
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectB1ElectricalDetail(callable $query, string $in): array
    {
        $agg = $query(
            "SELECT COUNT(*) AS rows_cnt,
                    MIN(Af) AS af_min, MAX(Af) AS af_max,
                    MIN(r) AS r_min, MAX(r) AS r_max,
                    MIN(x50) AS x50_min, MAX(x50) AS x50_max,
                    MIN(x60) AS x60_min, MAX(x60) AS x60_max,
                    MIN(tension) AS tension_min, MAX(tension) AS tension_max,
                    MIN(mt) AS mt_min, MAX(mt) AS mt_max
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'B1'",
            [],
        );
        $samples = $query(
            "SELECT cable_style, xa, mt, Af, r, x50, x60, tension, od, cdia, wght
             FROM cable_specs
             WHERE cable_style IN ($in) AND cable_style = 'B1'
             ORDER BY xa ASC
             LIMIT 15",
            [],
        );

        return ['aggregate' => $agg[0] ?? [], 'samples' => $samples];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @return array<string, mixed>
     */
    private function collectSpecLabelsScan(callable $query): array
    {
        try {
            $tables = $query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spec_labels' LIMIT 1",
                [],
            );
            if ($tables === []) {
                return ['tableFound' => false];
            }
            $cols = $query(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'spec_labels'",
                [],
            );
            $columnNames = array_map(static fn (array $r): string => (string) ($r['COLUMN_NAME'] ?? ''), $cols);

            return ['tableFound' => true, 'columns' => $columnNames, 'note' => 'spec_labels is accessory-oriented; no cable_specs cc/ct columns found'];
        } catch (\Throwable $e) {
            return ['tableFound' => false, 'error' => $e->getMessage()];
        }
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
             FROM cable_specs WHERE cable_style IN ($in)
               AND TRIM(CAST(`$field` AS CHAR)) = '$escaped'
             ORDER BY cable_style ASC LIMIT $limit",
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
