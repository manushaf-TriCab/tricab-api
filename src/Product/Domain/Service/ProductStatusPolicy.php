<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductStatusTransition;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;

/**
 * Valid product status transitions only.
 *
 * Does not evaluate publication readiness (see ProductPublishPolicy and
 * ProductPublishReadinessEvaluator).
 *
 * Allowed product status transitions (seeded codes).
 *
 * draft -> review, published, archived
 * review -> draft, published, unpublished, archived
 * published -> unpublished, discontinued, archived
 * unpublished -> draft, review, published, archived
 * discontinued -> archived, unpublished
 * archived -> draft (via restore only; policy allows archived -> draft)
 */
final class ProductStatusPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        ProductStatusCode::DRAFT => [
            ProductStatusCode::REVIEW,
            ProductStatusCode::PUBLISHED,
            ProductStatusCode::ARCHIVED,
        ],
        ProductStatusCode::REVIEW => [
            ProductStatusCode::DRAFT,
            ProductStatusCode::PUBLISHED,
            ProductStatusCode::UNPUBLISHED,
            ProductStatusCode::ARCHIVED,
        ],
        ProductStatusCode::PUBLISHED => [
            ProductStatusCode::UNPUBLISHED,
            ProductStatusCode::DISCONTINUED,
            ProductStatusCode::ARCHIVED,
        ],
        ProductStatusCode::UNPUBLISHED => [
            ProductStatusCode::DRAFT,
            ProductStatusCode::REVIEW,
            ProductStatusCode::PUBLISHED,
            ProductStatusCode::ARCHIVED,
        ],
        ProductStatusCode::DISCONTINUED => [
            ProductStatusCode::UNPUBLISHED,
            ProductStatusCode::ARCHIVED,
        ],
        ProductStatusCode::ARCHIVED => [
            ProductStatusCode::DRAFT,
        ],
    ];

    public function isTransitionAllowed(ProductStatusCode $from, ProductStatusCode $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }

        $allowed = self::ALLOWED[$from->toString()] ?? [];

        return in_array($to->toString(), $allowed, true);
    }

    public function assertTransitionAllowed(ProductStatusCode $from, ProductStatusCode $to): void
    {
        if (!$this->isTransitionAllowed($from, $to)) {
            throw InvalidProductStatusTransition::from($from->toString(), $to->toString());
        }
    }
}
