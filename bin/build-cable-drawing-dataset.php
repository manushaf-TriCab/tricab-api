#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the cable drawing dataset from a CSV export of legacy tc.cable_specs:
 * the columns tricab/cablegen needs to draw a cross-section, as compact rows.
 *
 * Usage:
 *   php bin/build-cable-drawing-dataset.php cable_specs.csv [resources/cable-drawing/v1/cableSpecs.json]
 */

$columns = ['cable_style', 'nc', 'cc', 'xa', 'ne', 'xae', 'cdia', 'ins', 'od_ins', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'od', 'mt', 'ct'];

$csv = $argv[1] ?? '';
$out = $argv[2] ?? dirname(__DIR__) . '/resources/cable-drawing/v1/cableSpecs.json';
$handle = $csv !== '' ? fopen($csv, 'r') : false;
if ($handle === false) {
    fwrite(STDERR, "usage: php bin/build-cable-drawing-dataset.php cable_specs.csv [out.json]\n");
    exit(1);
}

$header = array_map(static fn ($h): string => trim((string) $h), fgetcsv($handle, escape: '') ?: []);
$missing = array_diff($columns, $header);
if ($missing !== []) {
    fwrite(STDERR, 'CSV is missing columns: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$rows = [];
while (($values = fgetcsv($handle, escape: '')) !== false) {
    if (count($values) !== count($header)) {
        continue;
    }
    $row = array_combine($header, $values);
    if (trim((string) $row['cable_style']) === '' || trim((string) $row['od']) === '') {
        continue;
    }
    $rows[] = array_map(static fn (string $c): string => trim((string) $row[$c]), $columns);
}
fclose($handle);

if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0o775, true)) {
    fwrite(STDERR, "Can't create " . dirname($out) . "\n");
    exit(1);
}
file_put_contents($out, json_encode([
    // The export's own name carries its timestamp, e.g. cable_specs_202607291549.csv.
    'source' => 'tc.cable_specs (' . basename($csv) . ')',
    'columns' => $columns,
    'rows' => $rows,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

printf("Wrote %d rows to %s\n", count($rows), $out);
