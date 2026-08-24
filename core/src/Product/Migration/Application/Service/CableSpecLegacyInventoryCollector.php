<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Phase 12.7 Batch D.1 — build cable_specs inventory from guarded read-only queries.
 *
 * Query callable signature: fn(string $sql, array $params): list<array<string,mixed>>
 */
final class CableSpecLegacyInventoryCollector
{
    private const DISTRIBUTION_FIELDS = ['cc', 'mt', 'ct'];

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $styles
     * @return array<string, mixed>
     */
    public function collect(callable $query, array $styles): array
    {
        $styles = $this->sanitizeStyles($styles);
        if ($styles === []) {
            return $this->emptyInventory();
        }

        $in = "'" . implode("','", $styles) . "'";

        $cols = $query('SHOW COLUMNS FROM `cable_specs`', []);
        $colNames = [];
        foreach ($cols as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $colNames[] = $field;
            }
        }

        $cnt = $query("SELECT COUNT(*) c FROM cable_specs WHERE cable_style IN ($in)", []);
        $st = $query("SELECT COUNT(DISTINCT cable_style) c FROM cable_specs WHERE cable_style IN ($in)", []);
        $per = $query(
            "SELECT cable_style, COUNT(*) c FROM cable_specs WHERE cable_style IN ($in) GROUP BY cable_style ORDER BY cable_style ASC",
            [],
        );

        $stats = [];
        foreach ($colNames as $col) {
            if ($col === 'my_row_id') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
                continue;
            }
            $q = $query(
                "SELECT
                    SUM(CASE WHEN `$col` IS NULL OR TRIM(CAST(`$col` AS CHAR))='' THEN 0 ELSE 1 END) populated,
                    SUM(CASE WHEN `$col` IS NULL OR TRIM(CAST(`$col` AS CHAR)) IN ('','0') THEN 0 ELSE 1 END) populatedNonZero,
                    COUNT(DISTINCT NULLIF(TRIM(CAST(`$col` AS CHAR)),'')) distinctVals,
                    COUNT(DISTINCT CASE WHEN `$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR))<>'' THEN cable_style END) styles
                 FROM cable_specs WHERE cable_style IN ($in)",
                [],
            );
            $samp = $query(
                "SELECT DISTINCT TRIM(CAST(`$col` AS CHAR)) v FROM cable_specs
                 WHERE cable_style IN ($in) AND `$col` IS NOT NULL AND TRIM(CAST(`$col` AS CHAR))<>''
                 ORDER BY v ASC
                 LIMIT 12",
                [],
            );
            $samples = [];
            foreach ($samp as $r) {
                $v = (string) ($r['v'] ?? '');
                if ($v !== '') {
                    $samples[] = $v;
                }
            }
            $stats[$col] = [
                'populated' => (int) ($q[0]['populated'] ?? 0),
                'populatedNonZero' => (int) ($q[0]['populatedNonZero'] ?? 0),
                'distinct' => (int) ($q[0]['distinctVals'] ?? 0),
                'styles' => (int) ($q[0]['styles'] ?? 0),
                'samples' => $samples,
            ];
        }

        $fieldDistributions = $this->collectFieldDistributions($query, $in, $colNames);

        return [
            'cableProductCount' => count($styles),
            'stylesWithCableSpecs' => (int) ($st[0]['c'] ?? 0),
            'stylesWithData' => (int) ($st[0]['c'] ?? 0),
            'rowCount' => (int) ($cnt[0]['c'] ?? 0),
            'columns' => $colNames,
            'perStyleRows' => $per,
            'stats' => $stats,
            'fieldDistributions' => $fieldDistributions,
        ];
    }

    /**
     * Build inventory from already-fetched rows (offline / unit tests).
     *
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     * @param list<string> $styles
     * @return array<string, mixed>
     */
    public static function buildFromRows(array $columns, array $rows, array $styles = []): array
    {
        $styles = array_values(array_unique(array_filter(array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            $styles,
        ))));

        $perStyle = [];
        foreach ($rows as $row) {
            $style = strtoupper(trim((string) ($row['cable_style'] ?? '')));
            if ($style === '') {
                continue;
            }
            $perStyle[$style] = ($perStyle[$style] ?? 0) + 1;
        }
        ksort($perStyle);
        $perStyleRows = [];
        foreach ($perStyle as $style => $c) {
            $perStyleRows[] = ['cable_style' => $style, 'c' => $c];
        }

        $stats = [];
        foreach ($columns as $col) {
            if ($col === 'my_row_id' || $col === 'cable_style') {
                continue;
            }
            $values = [];
            $stylesTouched = [];
            foreach ($rows as $row) {
                if (!array_key_exists($col, $row)) {
                    continue;
                }
                $raw = $row[$col];
                if ($raw === null) {
                    continue;
                }
                $v = trim((string) $raw);
                if ($v === '') {
                    continue;
                }
                $values[] = $v;
                $style = strtoupper(trim((string) ($row['cable_style'] ?? '')));
                if ($style !== '') {
                    $stylesTouched[$style] = true;
                }
            }
            $nonZero = array_values(array_filter($values, static fn (string $v): bool => $v !== '0'));
            $distinct = array_values(array_unique($values));
            sort($distinct);
            $stats[$col] = [
                'populated' => count($values),
                'populatedNonZero' => count($nonZero),
                'distinct' => count($distinct),
                'styles' => count($stylesTouched),
                'samples' => array_slice($distinct, 0, 12),
            ];
        }

        $fieldDistributions = [];
        foreach (self::DISTRIBUTION_FIELDS as $field) {
            $buckets = [];
            foreach ($rows as $row) {
                if (!array_key_exists($field, $row)) {
                    continue;
                }
                $raw = $row[$field];
                if ($raw === null) {
                    continue;
                }
                $v = trim((string) $raw);
                if ($v === '') {
                    continue;
                }
                $style = strtoupper(trim((string) ($row['cable_style'] ?? '')));
                if (!isset($buckets[$v])) {
                    $buckets[$v] = ['value' => $v, 'count' => 0, 'styles' => []];
                }
                ++$buckets[$v]['count'];
                if ($style !== '') {
                    $buckets[$v]['styles'][$style] = true;
                }
            }
            $items = [];
            foreach ($buckets as $b) {
                $items[] = [
                    'value' => $b['value'],
                    'count' => $b['count'],
                    'styles' => count($b['styles']),
                ];
            }
            usort(
                $items,
                static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp((string) $a['value'], (string) $b['value']),
            );
            $fieldDistributions[$field] = $items;
        }

        return [
            'cableProductCount' => $styles !== [] ? count($styles) : count($perStyle),
            'stylesWithCableSpecs' => count($perStyle),
            'stylesWithData' => count($perStyle),
            'rowCount' => count($rows),
            'columns' => $columns,
            'perStyleRows' => $perStyleRows,
            'stats' => $stats,
            'fieldDistributions' => $fieldDistributions,
        ];
    }

    /**
     * @param callable(string, array<string, mixed>): list<array<string, mixed>> $query
     * @param list<string> $colNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function collectFieldDistributions(callable $query, string $in, array $colNames): array
    {
        $out = [];
        foreach (self::DISTRIBUTION_FIELDS as $field) {
            if (!in_array($field, $colNames, true)) {
                $out[$field] = [];
                continue;
            }
            $rows = $query(
                "SELECT TRIM(CAST(`$field` AS CHAR)) AS value,
                        COUNT(*) AS cnt,
                        COUNT(DISTINCT cable_style) AS styles
                 FROM cable_specs
                 WHERE cable_style IN ($in)
                   AND `$field` IS NOT NULL
                   AND TRIM(CAST(`$field` AS CHAR)) <> ''
                 GROUP BY TRIM(CAST(`$field` AS CHAR))
                 ORDER BY cnt DESC, value ASC",
                [],
            );
            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'value' => (string) ($row['value'] ?? ''),
                    'count' => (int) ($row['cnt'] ?? 0),
                    'styles' => (int) ($row['styles'] ?? 0),
                ];
            }
            $out[$field] = $items;
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
    private function emptyInventory(): array
    {
        return [
            'cableProductCount' => 0,
            'stylesWithCableSpecs' => 0,
            'stylesWithData' => 0,
            'rowCount' => 0,
            'columns' => [],
            'perStyleRows' => [],
            'stats' => [],
            'fieldDistributions' => ['cc' => [], 'mt' => [], 'ct' => []],
        ];
    }
}
