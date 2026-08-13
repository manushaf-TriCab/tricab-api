<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompleteProduct3DModelUploadCommand
{
    public function __construct(
        public string $modelPublicId,
        public ?string $binaryBase64 = null,
    ) {
    }
}
