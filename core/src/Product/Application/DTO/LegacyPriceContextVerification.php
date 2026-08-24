<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class LegacyPriceContextVerification
{
    public function __construct(
        public bool $connectionOk,
        public string $legacySourceCode,
        public string $legacyContextType,
        public string $legacyContextCode,
        public ?int $priceRowCount,
        public ?int $distinctCodeCount,
        public bool $valid,
        public ?string $message = null,
    ) {
    }
}
