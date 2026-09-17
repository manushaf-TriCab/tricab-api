<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class QuoteItemDetails
{
    /**
     * @param list<QuoteItemPackDetails> $packs
     */
    public function __construct(
        public string $id,
        public ?string $itemNo,
        public ?string $productCode,
        public ?string $quantity,
        public ?string $packCount,
        public ?string $unitPrice,
        public ?string $reference,
        public array $packs = [],
    ) {
    }
}
