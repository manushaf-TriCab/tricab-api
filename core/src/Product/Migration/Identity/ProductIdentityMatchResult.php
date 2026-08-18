<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Identity;

final class ProductIdentityMatchResult
{
    public const CONFIDENCE_EXACT = 'Exact';
    public const CONFIDENCE_HIGH = 'High';
    public const CONFIDENCE_MEDIUM = 'Medium';
    public const CONFIDENCE_LOW = 'Low';
    public const CONFIDENCE_MANUAL_REVIEW = 'ManualReview';

    public function __construct(
        public readonly ?string $targetProductPublicId,
        public readonly ?string $targetVariantPublicId,
        public readonly string $matchMethod,
        public readonly string $matchConfidence,
        public readonly string $reason,
        public readonly bool $requiresReview,
    ) {
    }

    public static function none(string $reason = 'No identity match; create new Draft product'): self
    {
        // Unmatched is not ambiguous: import creates a new Draft product. Ambiguous multi-hits use ambiguous().
        return new self(null, null, 'none', self::CONFIDENCE_LOW, $reason, false);
    }

    public static function ambiguous(string $reason): self
    {
        return new self(null, null, 'ambiguous', self::CONFIDENCE_MANUAL_REVIEW, $reason, true);
    }
}
