<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductListResult
{
    /**
     * @param list<ProductAdminListItem> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }
}
