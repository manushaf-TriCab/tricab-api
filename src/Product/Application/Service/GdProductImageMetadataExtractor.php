<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductImageMetadataExtractor;

final class GdProductImageMetadataExtractor implements ProductImageMetadataExtractor
{
    public function extractFromBinary(string $binary): array
    {
        if ($binary === '' || !function_exists('getimagesizefromstring')) {
            return ['widthPx' => null, 'heightPx' => null];
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            return ['widthPx' => null, 'heightPx' => null];
        }

        return [
            'widthPx' => $info[0],
            'heightPx' => $info[1],
        ];
    }
}
