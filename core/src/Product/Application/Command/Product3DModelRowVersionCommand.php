<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class Product3DModelRowVersionCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $expectedRowVersion,
        public ?bool $approveApproximation = null,
        public ?string $approvalNote = null,
    ) {
    }
}
