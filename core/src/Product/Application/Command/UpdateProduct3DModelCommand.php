<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpdateProduct3DModelCommand
{
    /**
     * @param array<string, mixed>|null $viewerConfiguration
     */
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $expectedRowVersion,
        public ?string $name = null,
        public ?array $viewerConfiguration = null,
    ) {
    }
}
