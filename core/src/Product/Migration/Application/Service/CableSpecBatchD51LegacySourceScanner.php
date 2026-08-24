<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

/**
 * Deterministic legacy source scan for cable_specs semantic fields (read-only file walk).
 */
final class CableSpecBatchD51LegacySourceScanner
{
    /** @var list<string> */
    private const FIELD_TERMS = ['cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'];

    /** @var list<string> */
    private const CONTEXT_PATTERNS = [
        'cable_specs.cc',
        'cable_specs.ct',
        "['cc']",
        "['ct']",
        '["cc"]',
        '["ct"]',
        'name="cc"',
        'name="ct"',
        'lb/1000',
        'lb/1000ft',
        'lbs/1000',
        'reactance',
        '50Hz',
        '60Hz',
        'ohm/km',
        'Ω/km',
        'pulling tension',
        'maximum tension',
    ];

    /**
     * @param list<string> $roots
     * @return array<string, mixed>
     */
    public function scan(array $roots): array
    {
        if ($roots === []) {
            return self::embeddedScan();
        }

        $hits = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['php', 'js', 'ts', 'sql', 'md', 'html', 'vb', 'cs'], true)) {
                    continue;
                }
                if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                    continue;
                }
                if (str_contains($path, '/Product/Migration/') || str_contains($path, 'InvestigateCableSpec')) {
                    continue;
                }
                $content = @file_get_contents($path);
                if ($content === false || $content === '') {
                    continue;
                }
                $lines = explode("\n", $content);
                foreach ($lines as $i => $line) {
                    $lower = strtolower($line);
                    foreach (self::FIELD_TERMS as $term) {
                        if (!preg_match('/\b' . preg_quote($term, '/') . '\b/', $line)) {
                            continue;
                        }
                        $isCableContext = str_contains($lower, 'cable_specs')
                            || str_contains($lower, 'cable_spec')
                            || in_array($term, ['walu', 'wbnz'], true);
                        if (!$isCableContext && in_array($term, ['cc', 'ct', 'r'], true)) {
                            if (str_contains($lower, 'credit') || str_contains($lower, 'async')) {
                                continue;
                            }
                        }
                        $hits[] = [
                            'file' => $this->relativePath($path, $roots),
                            'line' => $i + 1,
                            'term' => $term,
                            'snippet' => trim(substr($line, 0, 200)),
                        ];
                        if (count($hits) >= 120) {
                            break 3;
                        }
                    }
                    foreach (self::CONTEXT_PATTERNS as $pattern) {
                        if (stripos($line, $pattern) !== false) {
                            $hits[] = [
                                'file' => $this->relativePath($path, $roots),
                                'line' => $i + 1,
                                'term' => $pattern,
                                'snippet' => trim(substr($line, 0, 200)),
                            ];
                            if (count($hits) >= 120) {
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return [
            'mode' => 'FILE_SCAN',
            'roots' => array_map(fn (string $r): string => $this->relativePath($r, $roots), $roots),
            'hitCount' => count($hits),
            'hits' => $hits,
            'directCableSpecsFieldLabels' => $this->extractDirectLabels($hits),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function embeddedScan(): array
    {
        return [
            'mode' => 'EMBEDDED_DETERMINISTIC',
            'roots' => ['tricab-api/core/src/Product/Migration', 'tricab-api/src'],
            'hitCount' => 0,
            'hits' => [],
            'directCableSpecsFieldLabels' => [
                'cc' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'ct' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'walu' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'wbnz' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'ins' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'Af' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'r' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'x50' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'x60' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
                'tension' => 'NO_DIRECT_LABEL_IN_SCANNED_REPO',
            ],
            'note' => 'No WordPress theme/plugin or archived calculator sources in workspace; scan limited to tricab-api migration and product code.',
            'migrationCodeReferences' => [
                'ResolveCableSpecFieldMappingService' => 'cc/ct REVIEW UNKNOWN_SEMANTICS',
                'Cable3DSpecificationMapping' => 'conductor material via CODE_CONDUCTOR not ct',
                'rating.ct' => 'C/A encoding differs from cable_specs.ct p/a',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return array<string, string>
     */
    private function extractDirectLabels(array $hits): array
    {
        $out = [];
        foreach (self::FIELD_TERMS as $term) {
            $out[$term] = 'NO_DIRECT_LABEL_IN_SCANNED_REPO';
        }
        foreach ($hits as $hit) {
            $term = (string) ($hit['term'] ?? '');
            $snippet = strtolower((string) ($hit['snippet'] ?? ''));
            if ($term === '' || !isset($out[$term])) {
                continue;
            }
            if (str_contains($snippet, 'label') || str_contains($snippet, 'title') || str_contains($snippet, 'heading')) {
                if (!str_contains($snippet, '=>') && !str_contains($snippet, 'function ')) {
                    $out[$term] = (string) ($hit['snippet'] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $roots
     */
    private function relativePath(string $path, array $roots): string
    {
        foreach ($roots as $root) {
            if (str_starts_with($path, $root)) {
                return ltrim(substr($path, strlen($root)), '/\\');
            }
        }

        return $path;
    }
}
