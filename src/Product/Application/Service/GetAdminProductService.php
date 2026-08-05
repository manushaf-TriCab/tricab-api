<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetAdminProductService
{
    public function __construct(
        private readonly ProductAdminQueryRepository $adminQuery,
    ) {
    }

    public function execute(string $publicId): ProductAdminDetail
    {
        $detail = $this->adminQuery->findDetailByPublicId(ProductPublicId::fromString($publicId));
        if ($detail === null) {
            throw ProductNotFound::forPublicId($publicId);
        }

        return $detail;
    }
}
