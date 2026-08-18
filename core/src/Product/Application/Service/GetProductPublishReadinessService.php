<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductPublishReadinessEvaluator;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductPublishReadinessService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductPublishReadinessEvaluator $readiness,
    ) {
    }

    public function execute(string $publicId): ProductPublishReadinessReport
    {
        $product = $this->products->findByPublicId(ProductPublicId::fromString($publicId));
        if ($product === null) {
            throw ProductNotFound::forPublicId($publicId);
        }

        return $this->readiness->evaluate($product);
    }
}
