<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ActivateProduct3DAssetCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $assetPublicId,
        public string $expectedRowVersion,
    ) {
    }
}
