<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompleteStyleMediaUploadCommand
{
    public function __construct(
        public string $stylePublicId,
        public string $assetPublicId,
        public ?string $binaryContent = null,
    ) {
    }
}
