<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Ai;

/**
 * What a classifier says about one message. `category` is one of `Ap2Mail::CATEGORIES` or null (no opinion);
 * `supplierId` is one of the ids it was given or null; `confidence` is 0 to 1 and is about the category.
 */
final readonly class Ap2MailClassification
{
    public function __construct(
        public ?string $category,
        public ?string $supplierId,
        public float $confidence,
        public ?string $summary,
    ) {
    }

    /** "AI not connected": no category, no supplier, no confidence, no summary. */
    public static function none(): self
    {
        return new self(null, null, 0.0, null);
    }
}
