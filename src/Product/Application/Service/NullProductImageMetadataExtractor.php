<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductImageMetadataExtractor;

final class NullProductImageMetadataExtractor implements ProductImageMetadataExtractor
{
    public function extractFromBinary(string $binary): array
    {
        return ['widthPx' => null, 'heightPx' => null];
    }
}
