<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCustomerViewVariantMatrix
{
    /**
     * @param list<ProductCustomerViewMatrixColumn> $columns
     * @param list<ProductCustomerViewMatrixRow> $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
    ) {
    }
}
