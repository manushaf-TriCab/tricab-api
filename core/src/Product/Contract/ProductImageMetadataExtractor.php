<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

interface ProductImageMetadataExtractor
{
    /**
     * @return array{widthPx: ?int, heightPx: ?int}
     */
    public function extractFromBinary(string $binary): array;
}
