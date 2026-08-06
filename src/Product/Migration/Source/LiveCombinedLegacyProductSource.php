<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Source;

use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only live extract: WordPress + tc → LegacySourceRecord stream.
 * Never writes to legacy databases.
 */
final class LiveCombinedLegacyProductSource implements LegacyProductSourceInterface, LiveLegacySourceMarker
{
    public function __construct(
        private readonly LegacyWordPressRepository $wordpress,
        private readonly LegacyTcRepository $tc,
    ) {
    }

    public function sourceSystemCode(): string
    {
        return 'combined';
    }

    public function isLiveReadOnly(): bool
    {
        return true;
    }

    public function extract(array $filter = []): \Generator
    {
        $products = $this->wordpress->findProducts($filter);
        $postIds = [];
        foreach ($products as $product) {
            $id = MigrationPayload::string($product['ID'] ?? $product['id'] ?? '');
            if ($id !== '') {
                $postIds[] = $id;
            }
            yield new LegacySourceRecord(
                sourceSystem: 'wordpress',
                sourceTable: 'wp_posts',
                sourceIdentifier: $id,
                recordType: 'product',
                payload: $product,
            );
        }

        if ($postIds !== []) {
            foreach ($this->wordpress->findCategories($postIds) as $row) {
                yield new LegacySourceRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: 'wp_terms',
                    sourceIdentifier: 'cat:' . MigrationPayload::string($row['slug'] ?? $row['term_id'] ?? ''),
                    recordType: 'classification',
                    payload: $row,
                    parentSourceIdentifier: isset($row['post_id'])
                        ? 'wordpress:wp_posts:' . MigrationPayload::string($row['post_id'])
                        : null,
                );
            }
            foreach ($this->wordpress->findSpecifications($postIds) as $row) {
                $postId = MigrationPayload::string($row['post_id'] ?? '');
                $key = MigrationPayload::string($row['meta_key'] ?? $row['field'] ?? 'meta');
                yield new LegacySourceRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: 'wp_postmeta',
                    sourceIdentifier: $postId . ':' . $key,
                    recordType: 'specification',
                    payload: $row,
                    parentSourceIdentifier: $postId !== '' ? 'wordpress:wp_posts:' . $postId : null,
                );
            }
            foreach ($this->wordpress->findAttachments($postIds) as $row) {
                $postType = strtolower(MigrationPayload::string($row['post_type'] ?? ''));
                if (in_array($postType, ['cable', 'accessory', 'certificate'], true)) {
                    continue;
                }
                $attId = MigrationPayload::string($row['ID'] ?? $row['id'] ?? '');
                $isDoc = str_contains(strtolower(MigrationPayload::string($row['mime'] ?? $row['post_mime_type'] ?? '')), 'pdf')
                    || str_ends_with(strtolower(MigrationPayload::string($row['file'] ?? '')), '.pdf');
                $parentPost = MigrationPayload::string($row['post_parent'] ?? '');
                $linkedPost = MigrationPayload::string($row['linked_post_id'] ?? '');
                yield new LegacySourceRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: 'wp_posts',
                    sourceIdentifier: $attId,
                    recordType: $isDoc ? 'document' : 'media',
                    payload: array_merge($row, [
                        'exists' => true,
                        'fileName' => MigrationPayload::string($row['file'] ?? $row['guid'] ?? $attId),
                        'path' => MigrationPayload::string($row['file'] ?? $row['guid'] ?? ''),
                        'url' => MigrationPayload::string($row['guid'] ?? ''),
                        'role' => 'attachment',
                    ]),
                    parentSourceIdentifier: $parentPost !== '' && $parentPost !== '0'
                        ? 'wordpress:wp_posts:' . $parentPost
                        : ($linkedPost !== '' ? 'wordpress:wp_posts:' . $linkedPost : null),
                );
            }
            foreach ($this->wordpress->findRelationships($postIds) as $row) {
                $relId = array_key_exists('sourceIdentifier', $row)
                    ? MigrationPayload::string($row['sourceIdentifier'])
                    : (MigrationPayload::string($row['post_id'] ?? '') . ':rel');
                yield new LegacySourceRecord(
                    sourceSystem: 'wordpress',
                    sourceTable: 'wp_postmeta',
                    sourceIdentifier: $relId,
                    recordType: 'relationship',
                    payload: $row,
                    parentSourceIdentifier: isset($row['from'])
                        ? MigrationPayload::string($row['from'])
                        : (isset($row['post_id']) ? 'wordpress:wp_posts:' . MigrationPayload::string($row['post_id']) : null),
                );
            }
        }

        $styleToPost = [];
        foreach ($products as $product) {
            $style = strtoupper(MigrationPayload::string($product['style_code'] ?? $product['styleCode'] ?? ''));
            $id = MigrationPayload::string($product['ID'] ?? $product['id'] ?? '');
            if ($style !== '' && $id !== '') {
                $styleToPost[$style] = 'wordpress:wp_posts:' . $id;
            }
        }

        foreach ($this->tc->findVariants($filter) as $row) {
            $ida = MigrationPayload::string($row['ida'] ?? $row['product_code'] ?? $row['sku'] ?? '');
            $style = strtoupper(MigrationPayload::string($row['style'] ?? $row['style_code'] ?? $row['cable_style'] ?? ''));
            if ($style === '' && $ida !== '') {
                $style = strtoupper(substr($ida, 0, 3));
            }
            $payload = array_merge($row, [
                'style_code' => $style,
                'product_code' => $ida,
                'sku' => $ida,
            ]);
            yield new LegacySourceRecord(
                sourceSystem: 'tc',
                sourceTable: 'tpro',
                sourceIdentifier: $ida,
                recordType: 'variant',
                payload: $payload,
                parentSourceIdentifier: $styleToPost[$style] ?? null,
            );
        }

        foreach ($this->tc->findSpecifications($filter) as $row) {
            $style = strtoupper(MigrationPayload::string($row['style_code'] ?? $row['cable_style'] ?? $row['style'] ?? ''));
            $field = MigrationPayload::string($row['field'] ?? 'spec');
            $parent = MigrationPayload::string($row['parent'] ?? $row['product_code'] ?? $row['ida'] ?? $style);
            yield new LegacySourceRecord(
                sourceSystem: 'tc',
                sourceTable: MigrationPayload::string($row['_table'] ?? 'cable_specs'),
                sourceIdentifier: $style . ':' . $field . ':' . $parent,
                recordType: 'specification',
                payload: array_merge($row, ['style_code' => $style, 'field' => $field, 'parent' => $parent]),
                parentSourceIdentifier: $parent !== '' ? 'tc:tpro:' . $parent : ($styleToPost[$style] ?? null),
            );
        }

        foreach ($this->tc->findAccessories($filter) as $row) {
            $code = MigrationPayload::string($row['product_code'] ?? $row['ida'] ?? '');
            yield new LegacySourceRecord(
                sourceSystem: 'tc',
                sourceTable: 'accessory_specs',
                sourceIdentifier: $code,
                recordType: 'variant',
                payload: array_merge($row, ['style_code' => MigrationPayload::string($row['style_code'] ?? $row['style'] ?? '')]),
            );
        }

        foreach ($this->tc->findDescriptions($filter) as $row) {
            $id = MigrationPayload::string($row['id'] ?? $row['style'] ?? $row['style_code'] ?? '');
            yield new LegacySourceRecord(
                sourceSystem: 'tc',
                sourceTable: 'descript',
                sourceIdentifier: $id,
                recordType: 'specification',
                payload: array_merge($row, ['field' => 'description']),
            );
        }
    }
}
