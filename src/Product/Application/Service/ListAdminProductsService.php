<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;

final class ListAdminProductsService
{
    public function __construct(
        private readonly ProductAdminQueryRepository $adminQuery,
    ) {
    }

    public function execute(ProductListCriteria $criteria): ProductListResult
    {
        return $this->adminQuery->list($criteria);
    }
}
