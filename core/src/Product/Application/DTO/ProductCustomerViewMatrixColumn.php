<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCustomerViewMatrixColumn
{
    public function __construct(
        public string $definitionPublicId,
        public string $code,
        public string $name,
        public ?string $groupName,
        public ?string $unit,
        public string $dataTypeCode,
        public int $displayOrder,
    ) {
    }
}
