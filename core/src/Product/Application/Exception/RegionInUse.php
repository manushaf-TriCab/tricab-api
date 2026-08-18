<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;
use TriCab\Core\Product\Application\DTO\RegionUsage;

final class RegionInUse extends RuntimeException
{
    public function __construct(
        public readonly RegionUsage $usage,
    ) {
        parent::__construct(sprintf(
            'Region cannot be deleted while in use (%d product regions, %d markets, %d product documents, %d catalogue mappings).',
            $usage->productRegionCount,
            $usage->marketCount,
            $usage->productDocumentCount,
            $usage->catalogueMappingCount,
        ));
    }
}
