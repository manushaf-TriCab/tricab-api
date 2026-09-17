<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class QuoteItemPackDetails
{
    public function __construct(
        public string $id,
        public ?string $unitsPerPack,
        public ?string $packCount,
        public ?string $comments,
    ) {
    }
}
