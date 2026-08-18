<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Identity;

use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;

/**
 * Decides Product vs ProductVariant vs Separate Product vs Ignored vs ManualReview.
 * Initial rule validated against dual-source model: WP cable/accessory → Product;
 * tc rows sharing style_code → Variants. Ambiguity → ManualReview.
 */
final class ProductVariantClassifier
{
    public const PRODUCT = 'product';
    public const VARIANT = 'product-variant';
    public const SEPARATE_PRODUCT = 'separate-product';
    public const IGNORED = 'ignored';
    public const MANUAL_REVIEW = 'manual-review';

    /**
     * @return array{decision: string, reason: string}
     */
    public function classify(CanonicalProduct $product, ?string $sourceRecordType = null): array
    {
        $type = $sourceRecordType ?? $product->legacyPostType;

        if ($type === 'ignored' || $type === 'revision' || $type === 'trash') {
            return ['decision' => self::IGNORED, 'reason' => 'Legacy record type excluded: ' . (string) $type];
        }

        if ($type === 'cable' || $type === 'accessory') {
            return [
                'decision' => self::PRODUCT,
                'reason' => 'WordPress CPT ' . $type . ' is a marketing Product; tc rows sharing style_code become variants',
            ];
        }

        if ($type === 'certificate') {
            return [
                'decision' => self::MANUAL_REVIEW,
                'reason' => 'Certificate may be document-only or product-linked; ambiguous without mapping',
            ];
        }

        if ($type === 'tpro' || $type === 'accessory_specs' || $type === 'tc-variant') {
            if ($product->legacyStyleCode !== null && $product->legacyStyleCode !== '') {
                return [
                    'decision' => self::VARIANT,
                    'reason' => 'Legacy tc row shares style_code ' . $product->legacyStyleCode . ' → ProductVariant under parent Product',
                ];
            }

            return [
                'decision' => self::MANUAL_REVIEW,
                'reason' => 'tc variant without style_code cannot be attached safely',
            ];
        }

        if ($type === 'independent-marketing') {
            return [
                'decision' => self::SEPARATE_PRODUCT,
                'reason' => 'Independent marketing content and lifecycle signals separate Product',
            ];
        }

        return [
            'decision' => self::MANUAL_REVIEW,
            'reason' => 'Ambiguous Product/Variant signals; stop and report',
        ];
    }
}
