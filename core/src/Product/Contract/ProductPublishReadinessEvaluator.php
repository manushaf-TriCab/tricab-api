<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;
use TriCab\Core\Product\Domain\Entity\Product;

/**
 * Cross-aggregate publication readiness checks (specifications, media, markets,
 * variants, required fields). Documents are optional and are not checked.
 *
 * Implemented in Phase 4 application services. Must not be injected into Product
 * entities. ProductPublishPolicy covers product-entity status checks only.
 */
interface ProductPublishReadinessEvaluator
{
    /**
     * @throws \TriCab\Core\Product\Application\Exception\ProductNotReadyForPublication when blocking issues exist
     */
    public function assertReadyToPublish(Product $product): void;

    public function evaluate(Product $product): ProductPublishReadinessReport;
}
