<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchListResult;

/**
 * Structured search over the nine-segment product-code universe.
 *
 * Deliberately a separate seam from ProductSearchRepository: that one answers
 * "which product", at product granularity and over the loaded catalogue only.
 * This one answers "which code", including the ~27,000 codes that have no
 * ProductVariant behind them. Same boundary shape, so a later Azure AI Search
 * backend can replace either without touching domain services.
 */
interface ProductCodeSearchRepository
{
    public function search(ProductCodeSearchCriteria $criteria): ProductCodeSearchListResult;

    /**
     * Code-value completions for a partially typed code.
     *
     * @return list<string>
     */
    public function suggest(string $query, int $limit = 10): array;
}
