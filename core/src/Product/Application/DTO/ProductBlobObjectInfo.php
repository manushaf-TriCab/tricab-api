<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductBlobObjectInfo
{
    public function __construct(
        public bool $exists,
        public ?string $contentType,
        public ?int $contentLength,
        public ?string $etag,
        public ?string $contentMd5 = null,
    ) {
    }
}
