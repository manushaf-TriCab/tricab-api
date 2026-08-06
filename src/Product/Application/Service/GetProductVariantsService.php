<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductVariantsService
{
    public function __construct(
        private readonly ProductAdminQueryRepository $adminQuery,
    ) {
    }

    /**
     * @return list<ProductVariantSummary>
     */
    public function execute(
        string $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array {
        return $this->adminQuery->listVariantSummariesByProduct(
            ProductPublicId::fromString($productPublicId),
            $includeArchived,
            $includeDeleted,
        );
    }
}
