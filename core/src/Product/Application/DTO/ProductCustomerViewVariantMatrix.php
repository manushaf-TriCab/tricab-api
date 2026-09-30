<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCustomerViewVariantMatrix
{
    /** Cable (or no-family) products: the catalogue's size-grouped table (unchanged). */
    public const LAYOUT_GROUPED = 'grouped';
    /** Every other family (accessories, plugs): one flat row per variant with its presentation columns. */
    public const LAYOUT_FLAT = 'flat';

    /**
     * @param list<ProductCustomerViewMatrixColumn> $columns
     * @param list<ProductCustomerViewMatrixRow> $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public string $layout = self::LAYOUT_GROUPED,
        public ?string $familyCode = null,
    ) {
    }
}
