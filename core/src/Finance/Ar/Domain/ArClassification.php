<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** The Jev decisions for one email (`ar_classify`): the category and method values, the confidence, and where it is routed. */
final readonly class ArClassification
{
    public function __construct(
        public string $followUpCategory,
        public string $proposedResponseType,
        public bool $needsReview,
        public float $confidence,
        public string $followUpStatus,
    ) {
    }
}
