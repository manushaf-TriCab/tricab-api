<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;

/**
 * Product-entity publication checks only.
 *
 * Responsibilities:
 * - Reject soft-deleted products
 * - Reject archived products (restore from archive first)
 * - Assert status transition to published via ProductStatusPolicy
 *
 * Cross-aggregate readiness (specs, media, markets, documents, variants) belongs
 * to ProductPublishReadinessEvaluator (Phase 4), not this policy.
 */
final class ProductPublishPolicy
{
    public function __construct(
        private readonly ProductStatusPolicy $statusPolicy = new ProductStatusPolicy(),
    ) {
    }

    public function assertCanPublish(Product $product): void
    {
        if ($product->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for(
                'Soft-deleted products cannot be published. Restore from deletion first.',
            );
        }

        if ($product->isArchived()) {
            throw InvalidProductLifecycleException::for(
                'Archived products cannot be published. Restore from archive first.',
            );
        }

        $this->statusPolicy->assertTransitionAllowed(
            $product->status(),
            ProductStatusCode::published(),
        );
    }
}
