<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class Product360SetSummary
{
    /**
     * @param list<Product360FrameSummary> $frames
     */
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $title,
        public int $expectedFrameCount,
        public int $uploadedFrameCount,
        public string $statusCode,
        public bool $isActive,
        public array $frames = [],
    ) {
    }
}
