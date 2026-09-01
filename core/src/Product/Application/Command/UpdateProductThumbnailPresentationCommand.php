<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

final readonly class UpdateProductThumbnailPresentationCommand
{
    public function __construct(
        public string $productPublicId,
        public ?ThumbnailBackgroundColor $thumbnailBackgroundColor,
    ) {
    }
}
