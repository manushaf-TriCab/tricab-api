#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Extract calculator product codes from engineering dataset and optionally
 * compare against Product Module (exact CodeValue lookup).
 *
 * Usage:
 *   php bin/cable-calculator-product-code-coverage.php
 *   CABLE_CALCULATOR_COVERAGE_PDO_DSN=... php bin/cable-calculator-product-code-coverage.php
 */

use TriCab\Core\CableCalculator\Engine\CableProductCodeGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$datasetDir = getenv('CABLE_CALCULATOR_DATASET_DIR') ?: (dirname(__DIR__) . '/resources/cable-calculator/v1');
$specsPath = rtrim($datasetDir, '/') . '/cableSpecs.json';
$manifestPath = rtrim($datasetDir, '/') . '/manifest.json';

$specsPayload = json_decode((string) file_get_contents($specsPath), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$rows = $specsPayload['rows'] ?? [];

/** @var array<string, array{role:string,style:string,size:string,code:string}> $codes */
$codes = [];

foreach ($rows as $row) {
    $style = isset($row['cable_style']) ? trim((string) $row['cable_style']) : '';
    if ($style === '') {
        continue;
    }

    $xa = (string) ($row['xa'] ?? '');
    if ($xa === '' || $xa === '0') {
        continue;
    }

    $nc = $row['nc'] ?? 1;
    $ne = (int) ($row['ne'] ?? 0);
    $cc = (string) ($row['cc'] ?? 'c');
    $core = is_numeric($nc) ? ((string) ((int) $nc) . $cc) : (string) $nc;

    foreach ([0 => 'OR', 1 => 'BK'] as $isAl => $colour) {
        $active = CableProductCodeGenerator::generate(
            $style,
            $xa,
            false,
            $core,
            $ne,
            $isAl,
            $isAl,
            $colour,
            $row['xae'] ?? '',
        );
        $codes[$active] = [
            'role' => 'ACTIVE',
            'style' => $style,
            'size' => $xa,
            'code' => $active,
        ];
    }

    // Earth / separate earth styles commonly emitted by the calculator.
    if (in_array($style, ['LP', 'LS', 'UP'], true)) {
        $earth = CableProductCodeGenerator::generate(
            $style,
            $xa,
            false,
            1,
            0,
            0,
            false,
            'EA',
            $xa,
        );
        $codes[$earth] = [
            'role' => 'EARTH',
            'style' => $style,
            'size' => $xa,
            'code' => $earth,
        ];
    }
}

ksort($codes);

$pdo = null;
$dsn = getenv('CABLE_CALCULATOR_COVERAGE_PDO_DSN') ?: '';
if ($dsn !== '') {
    try {
        $pdo = new PDO(
            $dsn,
            (string) (getenv('CABLE_CALCULATOR_COVERAGE_PDO_USER') ?: ''),
            (string) (getenv('CABLE_CALCULATOR_COVERAGE_PDO_PASS') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "PDO unavailable: {$e->getMessage()}\n");
        $pdo = null;
    }
}

$summary = [
    'generatedAtUtc' => gmdate('c'),
    'datasetVersion' => $manifest['datasetVersion'] ?? null,
    'baselineLabel' => $manifest['baselineLabel'] ?? null,
    'lookupMode' => $pdo ? 'azure-sql-exact-code' : 'no-product-db',
    'totalCalculatorProductCodes' => count($codes),
    'activeCableCodes' => 0,
    'earthCableCodes' => 0,
    'resolved' => 0,
    'notMigrated' => 0,
    'ambiguous' => 0,
    'inactive' => 0,
    'error' => 0,
    'codes' => [],
];

foreach ($codes as $code => $meta) {
    if ($meta['role'] === 'ACTIVE') {
        $summary['activeCableCodes']++;
    } else {
        $summary['earthCableCodes']++;
    }

    $status = 'NOT_MIGRATED';
    $productPublicId = null;
    if ($pdo === null) {
        $status = 'NOT_MIGRATED';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT CONVERT(nvarchar(36), p.[PublicId]) AS [ProductPublicId], ps.[StatusCode] AS [StatusCode]
                 FROM [product].[ProductCodes] pc
                 INNER JOIN [product].[Products] p ON p.[ProductId] = pc.[ProductId] AND p.[DeletedAtUtc] IS NULL
                 INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
                 WHERE pc.[CodeValue] = :code AND pc.[DeletedAtUtc] IS NULL',
            );
            $stmt->execute(['code' => $code]);
            $found = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($found === []) {
                $status = 'NOT_MIGRATED';
            } else {
                $ids = array_unique(array_map(static fn (array $r): string => (string) ($r['ProductPublicId'] ?? ''), $found));
                if (count(array_filter($ids)) > 1) {
                    $status = 'AMBIGUOUS';
                } else {
                    $productPublicId = $ids[0] ?? null;
                    $st = strtoupper((string) ($found[0]['StatusCode'] ?? ''));
                    $status = in_array($st, ['INACTIVE', 'ARCHIVED', 'DELETED'], true) ? 'INACTIVE' : 'RESOLVED';
                }
            }
        } catch (Throwable) {
            $status = 'ERROR';
        }
    }

    match ($status) {
        'RESOLVED' => $summary['resolved']++,
        'AMBIGUOUS' => $summary['ambiguous']++,
        'INACTIVE' => $summary['inactive']++,
        'ERROR' => $summary['error']++,
        default => $summary['notMigrated']++,
    };

    $summary['codes'][] = [
        'productCode' => $code,
        'role' => $meta['role'],
        'style' => $meta['style'],
        'size' => $meta['size'],
        'resolutionStatus' => $status,
        'resolvedProductPublicId' => $productPublicId,
    ];
}

$webRoot = dirname(__DIR__, 3) . '/tricab-web';
$jsonTargets = [
    dirname(__DIR__) . '/data/cable-calculator/product-code-coverage.json',
    $webRoot . '/data/cable-calculator/product-code-coverage.json',
];
$mdTargets = [
    dirname(__DIR__) . '/docs/cable-calculator/product-code-coverage-report.md',
    $webRoot . '/docs/cable-calculator/product-code-coverage-report.md',
    dirname(__DIR__, 2) . '/docs/cable-calculator/product-code-coverage-report.md',
];

foreach ($jsonTargets as $path) {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

$md = <<<MD
# Cable Calculator Product Code Coverage

Generated: {$summary['generatedAtUtc']}

## Dataset

- datasetVersion: `{$summary['datasetVersion']}`
- baselineLabel: `{$summary['baselineLabel']}`
- lookupMode: `{$summary['lookupMode']}`

## Summary

| Metric | Count |
| --- | ---: |
| Total calculator product codes | {$summary['totalCalculatorProductCodes']} |
| Active cable codes | {$summary['activeCableCodes']} |
| Earth cable codes | {$summary['earthCableCodes']} |
| Resolved in Product Module | {$summary['resolved']} |
| Not migrated | {$summary['notMigrated']} |
| Ambiguous | {$summary['ambiguous']} |
| Inactive | {$summary['inactive']} |
| Error | {$summary['error']} |

## Notes

- Codes are derived from `tc.cable_specs` snapshot rows via `CableProductCodeGenerator`.
- Lookup uses exact `product.ProductCodes.CodeValue` identity — no nearest-size substitution.
- When Product Module DB is unavailable (`lookupMode=no-product-db`), all codes are reported as `NOT_MIGRATED`.
- Engineering calculation remains valid regardless of these resolution statuses.

## Sample not-migrated codes

MD;

$samples = array_slice(array_values(array_filter(
    $summary['codes'],
    static fn (array $c): bool => $c['resolutionStatus'] === 'NOT_MIGRATED',
)), 0, 25);

foreach ($samples as $sample) {
    $md .= sprintf("- `%s` (%s %s)\n", $sample['productCode'], $sample['role'], $sample['style']);
}

foreach ($mdTargets as $path) {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $md);
}

echo json_encode([
    'total' => $summary['totalCalculatorProductCodes'],
    'active' => $summary['activeCableCodes'],
    'earth' => $summary['earthCableCodes'],
    'resolved' => $summary['resolved'],
    'notMigrated' => $summary['notMigrated'],
    'ambiguous' => $summary['ambiguous'],
    'inactive' => $summary['inactive'],
    'lookupMode' => $summary['lookupMode'],
    'json' => $jsonTargets[0],
    'md' => $mdTargets[0],
], JSON_PRETTY_PRINT) . "\n";
