<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Disposable non-production fixture source for tests and pilot dry-runs.
 */
final class FixtureLegacyProductSource implements LegacyProductSourceInterface
{
    /** @param list<LegacySourceRecord> $records */
    public function __construct(
        private readonly array $records,
        private readonly string $systemCode = 'combined',
    ) {
    }

    public function sourceSystemCode(): string
    {
        return $this->systemCode;
    }

    public function extract(array $filter = []): \Generator
    {
        $max = isset($filter['maxRecords']) && (is_int($filter['maxRecords']) || is_string($filter['maxRecords']))
            ? (int) $filter['maxRecords']
            : null;
        $style = isset($filter['styleCode']) ? strtoupper(MigrationPayload::string($filter['styleCode'])) : null;
        $n = 0;
        foreach ($this->records as $record) {
            if ($style !== null) {
                $payloadStyle = strtoupper(MigrationPayload::string(
                    $record->payload['style_code'] ?? $record->payload['styleCode'] ?? $record->payload['style'] ?? '',
                ));
                if ($payloadStyle !== '' && $payloadStyle !== $style) {
                    continue;
                }
            }
            yield $record;
            ++$n;
            if ($max !== null && $n >= $max) {
                return;
            }
        }
    }

    public static function pilotSample(): self
    {
        return new self([
            new LegacySourceRecord('wordpress', 'wp_posts', '3812', 'product', [
                'post_type' => 'cable',
                'post_title' => 'FLEXI-TUFF',
                'post_name' => 'flexi-tuff',
                'post_status' => 'publish',
                'style_code' => 'FT',
                'small_desc' => 'Flexible industrial cable',
                'post_content' => 'Full marketing description',
                'categories' => ['industrial', 'flexible-power'],
                'regions' => ['AU'],
            ]),
            new LegacySourceRecord('tc', 'tpro', 'FT-2X1.5BK', 'variant', [
                'ida' => 'FT-2X1.5BK',
                'style' => 'FT',
                'style_code' => 'FT',
                'product_code' => 'FT-2X1.5BK',
                'sku' => 'FT-2X1.5BK',
                'cores' => '2',
                'conductor_size' => '1.5',
                'od' => '25.4',
                'voltage' => '0.6/1kV',
            ], 'wordpress:wp_posts:3812'),
            new LegacySourceRecord('tc', 'tpro', 'FT-3X2.5BK', 'variant', [
                'ida' => 'FT-3X2.5BK',
                'style' => 'FT',
                'style_code' => 'FT',
                'product_code' => 'FT-3X2.5BK',
                'sku' => 'FT-3X2.5BK',
                'cores' => '3',
                'conductor_size' => '2.5',
                'od' => '28.0',
                'voltage' => '0.6/1kV',
            ], 'wordpress:wp_posts:3812'),
            new LegacySourceRecord('tc', 'cable_specs', 'FT:od:FT-2X1.5BK', 'specification', [
                'style_code' => 'FT',
                'field' => 'od',
                'value' => '25.4',
                'unit' => 'mm',
                'parent' => 'FT-2X1.5BK',
            ], 'tc:tpro:FT-2X1.5BK'),
            new LegacySourceRecord('files', 'thumbs', 'FT-gr.jpg', 'media', [
                'path' => 'thumbs/FT-gr.jpg',
                'url' => '/thumbs/FT-gr.jpg',
                'fileName' => 'FT-gr.jpg',
                'role' => 'primary',
                'style_code' => 'FT',
                'exists' => true,
                'checksum' => hash('sha256', 'fixture-ft-image'),
            ], 'wordpress:wp_posts:3812'),
            new LegacySourceRecord('files', 'thumbs/data_sheet', 'FT-datasheet.pdf', 'document', [
                'path' => 'thumbs/data_sheet/FT-datasheet.pdf',
                'url' => '/thumbs/data_sheet/FT-datasheet.pdf',
                'fileName' => 'FT-datasheet.pdf',
                'title' => 'FLEXI-TUFF Datasheet',
                'documentType' => 'Datasheet',
                'style_code' => 'FT',
                'exists' => true,
                'checksum' => hash('sha256', 'fixture-ft-datasheet'),
            ], 'wordpress:wp_posts:3812'),
            new LegacySourceRecord('wordpress', 'wp_postmeta', '3812:accessory:J09', 'relationship', [
                'relationshipType' => 'accessory',
                'from' => 'wordpress:wp_posts:3812',
                'to' => 'wordpress:wp_posts:9001',
                'style_code' => 'FT',
            ], 'wordpress:wp_posts:3812'),
            new LegacySourceRecord('wordpress', 'wp_terms', 'cat:flexible-power', 'classification', [
                'term_id' => '55',
                'slug' => 'flexible-power',
                'name' => 'Flexible Power',
                'taxonomy' => 'category',
            ]),
        ]);
    }
}
