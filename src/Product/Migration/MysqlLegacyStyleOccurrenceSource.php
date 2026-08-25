<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Contract\LegacyStyleOccurrenceSource;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only WP + tc style-code extraction. Never issues INSERT/UPDATE/DELETE.
 */
final class MysqlLegacyStyleOccurrenceSource implements LegacyStyleOccurrenceSource
{
    private const PRODUCT_POST_TYPES = ['cable', 'accessory'];

    private int $wordpressReads = 0;
    private int $tcReads = 0;

    public function __construct(
        private readonly LiveLegacySourceConfig $config,
        private readonly LegacyWordPressRepository $wordpress,
        private readonly LegacyTcRepository $tc,
    ) {
    }

    public function listOccurrences(): array
    {
        $out = [];
        if ($this->config->wordpressConfigured()) {
            try {
                $rows = $this->wordpress->discoverStyleCodes(self::PRODUCT_POST_TYPES);
                ++$this->wordpressReads;
                foreach ($rows as $row) {
                    $raw = MigrationPayload::string($row['style_code'] ?? '');
                    $samples = $this->splitSamples((string) ($row['sample_products'] ?? ''));
                    $out[] = new LegacyStyleOccurrence(
                        source: 'wordpress',
                        tableEntity: 'wp_postmeta',
                        field: 'style_code',
                        rawStyleCode: $raw,
                        sourceOccurrences: (int) ($row['product_count'] ?? 0),
                        productCount: (int) ($row['product_count'] ?? 0),
                        sampleProducts: $samples,
                        familyEvidence: null,
                    );
                }
            } catch (\Throwable) {
                // Availability is reported in sourceStats().
            }
        }
        if ($this->config->tcConfigured()) {
            try {
                $variantCounts = [];
                $variantRows = $this->tc->discoverVariantCountsByStyle();
                ++$this->tcReads;
                foreach ($variantRows as $row) {
                    $code = MigrationPayload::string($row['style_code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $variantCounts[$code] = [
                        'count' => (int) ($row['variant_count'] ?? 0),
                        'samples' => $this->splitSamples((string) ($row['example_codes'] ?? ''), ','),
                    ];
                }
                $styleRows = $this->tc->discoverStyleCodes();
                ++$this->tcReads;
                foreach ($styleRows as $row) {
                    $raw = MigrationPayload::string($row['style_code'] ?? '');
                    $source = MigrationPayload::string($row['source'] ?? 'tpro_ext.style');
                    [$table, $field] = $this->splitSource($source);
                    $counts = $variantCounts[$raw] ?? ['count' => 1, 'samples' => []];
                    $out[] = new LegacyStyleOccurrence(
                        source: 'tc-mysql',
                        tableEntity: $table,
                        field: $field,
                        rawStyleCode: $raw,
                        sourceOccurrences: max(1, (int) $counts['count']),
                        productCount: (int) $counts['count'],
                        sampleProducts: $counts['samples'],
                        familyEvidence: null,
                    );
                }
            } catch (\Throwable) {
                // Availability is reported in sourceStats().
            }
        }

        return $out;
    }

    public function sourceStats(): array
    {
        $stats = [];
        $stats[] = $this->wordpressStat();
        foreach ([
            ['tpro', 'style', 'tpro.style'],
            ['tpro_ext', 'style', 'tpro_ext.style'],
            ['cable_specs', 'cable_style', 'cable_specs.cable_style'],
        ] as [$table, $field, $sourceLabel]) {
            $stats[] = $this->tcTableStat($table, $field, $sourceLabel);
        }
        $stats[] = new StyleInventorySourceStat(
            source: 'canonical-derived',
            tableEntity: 'CanonicalProduct',
            field: 'legacyStyleCode',
            rowCount: 0,
            nonEmptyStyleCount: 0,
            distinctRawStyleCount: 0,
            readOnly: true,
            available: true,
            notes: 'Derived from WordPress style_code / tc style during extraction. Not an independent live table.',
        );

        return $stats;
    }

    public function wordpressReads(): int
    {
        return $this->wordpressReads;
    }

    public function tcReads(): int
    {
        return $this->tcReads;
    }

    public function filesystemReads(): int
    {
        return 0;
    }

    public function inspectMembership(array $styleCodes): array
    {
        $codes = [];
        foreach ($styleCodes as $code) {
            $normalized = strtoupper(trim((string) $code));
            if ($normalized !== '') {
                $codes[$normalized] = true;
            }
        }
        $list = array_map('strval', array_keys($codes));
        $out = [];
        foreach ($list as $code) {
            $out[$code] = new StyleMembershipEvidence(
                styleCode: (string) $code,
                wordpressProductCount: 0,
                tcVariantCount: 0,
                sampleTitles: [],
                sampleProducts: [],
                terms: [],
                postTypes: [],
                attachmentFiles: [],
                specificationValues: [],
                descriptionSnippets: [],
                sourcesAvailable: [],
                wordpressAvailable: $this->config->wordpressConfigured(),
                tcAvailable: $this->config->tcConfigured(),
            );
        }
        if ($list === []) {
            return $out;
        }

        if ($this->config->wordpressConfigured()) {
            try {
                $placements = $this->wordpress->discoverCataloguePlacements($list);
                ++$this->wordpressReads;
                $postIds = [];
                $byStylePosts = [];
                foreach ($placements as $row) {
                    $code = strtoupper(trim((string) ($row['styleCode'] ?? '')));
                    if (!isset($out[$code])) {
                        continue;
                    }
                    $byStylePosts[$code][] = $row;
                    $postIds[] = (int) ($row['wordpressPostId'] ?? 0);
                }
                $postIds = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));
                $categories = $postIds === [] ? [] : $this->wordpress->findCategories($postIds);
                if ($postIds !== []) {
                    ++$this->wordpressReads;
                }
                $attachments = $postIds === [] ? [] : $this->wordpress->findAttachments($postIds);
                if ($postIds !== []) {
                    ++$this->wordpressReads;
                }
                $specs = $postIds === [] ? [] : $this->wordpress->findSpecifications($postIds);
                if ($postIds !== []) {
                    ++$this->wordpressReads;
                }
                $termsByPost = [];
                foreach ($categories as $cat) {
                    $pid = (int) ($cat['post_id'] ?? 0);
                    $name = trim((string) ($cat['name'] ?? ''));
                    if ($pid > 0 && $name !== '') {
                        $termsByPost[$pid][] = $name;
                    }
                }
                $filesByPost = [];
                foreach ($attachments as $att) {
                    $pid = (int) ($att['post_parent'] ?? $att['linked_post_id'] ?? 0);
                    $file = trim((string) ($att['file'] ?? $att['post_title'] ?? ''));
                    if ($pid > 0 && $file !== '') {
                        $filesByPost[$pid][] = $file;
                    }
                }
                $specByPost = [];
                foreach ($specs as $spec) {
                    $pid = (int) ($spec['post_id'] ?? 0);
                    $field = (string) ($spec['field'] ?? $spec['meta_key'] ?? '');
                    $value = trim((string) ($spec['value'] ?? $spec['meta_value'] ?? ''));
                    if ($pid > 0 && $field !== '' && $value !== '' && !in_array($field, ['style_code', 'small_desc'], true)) {
                        $specByPost[$pid][$field][] = $value;
                    }
                }
                foreach ($byStylePosts as $code => $rows) {
                    $titles = [];
                    $types = [];
                    $terms = [];
                    $files = [];
                    $specValues = [];
                    foreach ($rows as $row) {
                        $title = trim((string) ($row['postTitle'] ?? ''));
                        if ($title !== '' && count($titles) < 5) {
                            $titles[] = $title;
                        }
                        $type = trim((string) ($row['postType'] ?? ''));
                        if ($type !== '') {
                            $types[$type] = true;
                        }
                        foreach ($row['terms'] ?? [] as $term) {
                            if (is_array($term)) {
                                $name = trim((string) ($term['name'] ?? $term['path'] ?? ''));
                                if ($name !== '') {
                                    $terms[$name] = true;
                                }
                            }
                        }
                        $pid = (int) ($row['wordpressPostId'] ?? 0);
                        foreach ($termsByPost[$pid] ?? [] as $name) {
                            $terms[$name] = true;
                        }
                        foreach ($filesByPost[$pid] ?? [] as $file) {
                            $files[$file] = true;
                        }
                        foreach ($specByPost[$pid] ?? [] as $field => $values) {
                            foreach ($values as $value) {
                                $specValues[$field][$value] = true;
                            }
                        }
                    }
                    $normalizedSpecs = [];
                    foreach ($specValues as $field => $values) {
                        $normalizedSpecs[$field] = array_slice(array_keys($values), 0, 8);
                    }
                    $previous = $out[$code];
                    $out[$code] = new StyleMembershipEvidence(
                        styleCode: (string) $code,
                        wordpressProductCount: count($rows),
                        tcVariantCount: $previous->tcVariantCount,
                        sampleTitles: $titles,
                        sampleProducts: $previous->sampleProducts,
                        terms: array_slice(array_keys($terms), 0, 12),
                        postTypes: array_keys($types),
                        attachmentFiles: array_slice(array_keys($files), 0, 12),
                        specificationValues: $normalizedSpecs,
                        descriptionSnippets: $previous->descriptionSnippets,
                        sourcesAvailable: array_values(array_unique(array_merge($previous->sourcesAvailable, ['wordpress']))),
                        wordpressAvailable: true,
                        tcAvailable: $previous->tcAvailable,
                    );
                }
            } catch (\Throwable) {
                // Availability remains as configured; membership stays empty for WP.
            }
        }

        if ($this->config->tcConfigured()) {
            foreach ($list as $code) {
                try {
                    $variants = $this->tc->findVariants(['styleCode' => $code, 'maxRecords' => 20]);
                    ++$this->tcReads;
                    $specs = $this->tc->findSpecifications(['styleCode' => $code, 'maxRecords' => 50]);
                    ++$this->tcReads;
                    $descriptions = $this->tc->findDescriptions(['styleCode' => $code, 'maxRecords' => 10]);
                    ++$this->tcReads;
                    $samples = [];
                    foreach ($variants as $row) {
                        $ida = trim((string) ($row['ida'] ?? $row['product_code'] ?? $row['sku'] ?? ''));
                        if ($ida !== '' && count($samples) < 5) {
                            $samples[] = $ida;
                        }
                    }
                    $specValues = $out[$code]->specificationValues;
                    foreach ($specs as $spec) {
                        $field = (string) ($spec['field'] ?? '');
                        $value = trim((string) ($spec['value'] ?? ''));
                        if ($field === '' || $value === '') {
                            continue;
                        }
                        if (!isset($specValues[$field])) {
                            $specValues[$field] = [];
                        }
                        if (!in_array($value, $specValues[$field], true) && count($specValues[$field]) < 8) {
                            $specValues[$field][] = $value;
                        }
                    }
                    $snippets = [];
                    foreach ($descriptions as $row) {
                        $text = trim((string) ($row['description'] ?? $row['descript'] ?? $row['text'] ?? ''));
                        if ($text !== '' && count($snippets) < 3) {
                            $snippets[] = substr($text, 0, 180);
                        }
                    }
                    $previous = $out[$code];
                    $out[$code] = new StyleMembershipEvidence(
                        styleCode: (string) $code,
                        wordpressProductCount: $previous->wordpressProductCount,
                        tcVariantCount: count($variants),
                        sampleTitles: $previous->sampleTitles,
                        sampleProducts: $samples !== [] ? $samples : $previous->sampleProducts,
                        terms: $previous->terms,
                        postTypes: $previous->postTypes,
                        attachmentFiles: $previous->attachmentFiles,
                        specificationValues: $specValues,
                        descriptionSnippets: $snippets !== [] ? $snippets : $previous->descriptionSnippets,
                        sourcesAvailable: array_values(array_unique(array_merge($previous->sourcesAvailable, ['tc-mysql']))),
                        wordpressAvailable: $previous->wordpressAvailable,
                        tcAvailable: true,
                    );
                } catch (\Throwable) {
                    // Keep prior snapshot for this code.
                }
            }
        }

        return $out;
    }

    private function wordpressStat(): StyleInventorySourceStat
    {
        if (!$this->config->wordpressConfigured()) {
            return new StyleInventorySourceStat(
                source: 'wordpress',
                tableEntity: 'wp_postmeta',
                field: 'style_code',
                rowCount: 0,
                nonEmptyStyleCount: 0,
                distinctRawStyleCount: 0,
                readOnly: true,
                available: false,
                notes: 'LEGACY_WP_* not configured.',
            );
        }
        try {
            $schema = $this->wordpress->inspectSchema();
            ++$this->wordpressReads;
            $rowCount = (int) (($schema['productCounts']['cable'] ?? 0) + ($schema['productCounts']['accessory'] ?? 0));
            $styles = $this->wordpress->discoverStyleCodes(self::PRODUCT_POST_TYPES);
            ++$this->wordpressReads;
            $nonEmpty = 0;
            foreach ($styles as $row) {
                $nonEmpty += (int) ($row['product_count'] ?? 0);
            }

            return new StyleInventorySourceStat(
                source: 'wordpress',
                tableEntity: 'wp_postmeta',
                field: 'style_code',
                rowCount: $rowCount,
                nonEmptyStyleCount: $nonEmpty,
                distinctRawStyleCount: count($styles),
                readOnly: true,
                available: true,
                notes: 'ACF/meta_key=style_code on cable and accessory posts. Read-only.',
            );
        } catch (\Throwable $exception) {
            return new StyleInventorySourceStat(
                source: 'wordpress',
                tableEntity: 'wp_postmeta',
                field: 'style_code',
                rowCount: 0,
                nonEmptyStyleCount: 0,
                distinctRawStyleCount: 0,
                readOnly: true,
                available: false,
                notes: 'WordPress read failed: ' . $exception->getMessage(),
            );
        }
    }

    private function tcTableStat(string $table, string $field, string $sourceLabel): StyleInventorySourceStat
    {
        if (!$this->config->tcConfigured()) {
            return new StyleInventorySourceStat(
                source: 'tc-mysql',
                tableEntity: $table,
                field: $field,
                rowCount: 0,
                nonEmptyStyleCount: 0,
                distinctRawStyleCount: 0,
                readOnly: true,
                available: false,
                notes: 'LEGACY_TC_* not configured.',
            );
        }
        try {
            $schema = $this->tc->inspectSchema();
            ++$this->tcReads;
            $rowCount = (int) ($schema['rowCounts'][$table] ?? 0);
            $columns = $schema['columns'][$table] ?? [];
            if (!in_array($field, $columns, true)) {
                return new StyleInventorySourceStat(
                    source: 'tc-mysql',
                    tableEntity: $table,
                    field: $field,
                    rowCount: $rowCount,
                    nonEmptyStyleCount: 0,
                    distinctRawStyleCount: 0,
                    readOnly: true,
                    available: true,
                    notes: 'Column ' . $field . ' is not present. Read-only inspect only.',
                );
            }
            $values = $this->tc->discoverColumnValueCounts($table, $field, 500);
            ++$this->tcReads;
            $nonEmpty = 0;
            foreach ($values as $row) {
                $nonEmpty += (int) ($row['usage_count'] ?? 0);
            }

            return new StyleInventorySourceStat(
                source: 'tc-mysql',
                tableEntity: $table,
                field: $field,
                rowCount: $rowCount,
                nonEmptyStyleCount: $nonEmpty,
                distinctRawStyleCount: count($values),
                readOnly: true,
                available: true,
                notes: $sourceLabel . ' read-only. Distinct count is capped at 500 if more values exist.',
            );
        } catch (\Throwable $exception) {
            return new StyleInventorySourceStat(
                source: 'tc-mysql',
                tableEntity: $table,
                field: $field,
                rowCount: 0,
                nonEmptyStyleCount: 0,
                distinctRawStyleCount: 0,
                readOnly: true,
                available: false,
                notes: 'tc read failed: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function splitSamples(string $raw, string $separator = '|'): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $parts = array_map(static fn (string $p): string => trim($p), explode($separator, $raw));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSource(string $source): array
    {
        $parts = explode('.', $source, 2);
        if (count($parts) !== 2) {
            return ['tpro_ext', 'style'];
        }

        return [$parts[0], $parts[1]];
    }
}
