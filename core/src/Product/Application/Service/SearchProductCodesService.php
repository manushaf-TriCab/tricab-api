<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchListResult;
use TriCab\Core\Product\Contract\ProductCodeSearchRepository;

final class SearchProductCodesService
{
    public function __construct(
        private readonly ProductCodeSearchRepository $search,
    ) {
    }

    public function execute(ProductCodeSearchCriteria $criteria): ProductCodeSearchListResult
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
