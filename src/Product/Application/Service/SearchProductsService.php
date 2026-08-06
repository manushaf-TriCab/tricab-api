<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Contract\ProductSearchRepository;

final class SearchProductsService
{
    public function __construct(
        private readonly ProductSearchRepository $search,
    ) {
    }

    public function execute(ProductSearchCriteria $criteria): ProductSearchListResult
    {
        return $this->search->search($criteria);
    }

    /**
     * @return list<string>
     */
    public function suggest(string $query, int $limit = 10): array
    {
        return $this->search->suggest($query, $limit);
    }
}
