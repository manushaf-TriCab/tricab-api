<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;

/**
 * Future Azure SQL full-text / filtered search.
 * Boundary allows later Azure AI Search without changing domain services.
 */
interface ProductSearchRepository
{
    public function search(ProductSearchCriteria $criteria): ProductSearchListResult;

    /**
     * @return list<string>
     */
    public function suggest(string $query, int $limit = 10): array;
}
