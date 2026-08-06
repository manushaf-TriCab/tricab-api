<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompleteProductMediaUploadCommand
{
    public function __construct(
        public string $mediaPublicId,
        /** Optional binary for dimension extraction (dev/InMemory); production may omit. */
        public ?string $binaryContent = null,
    ) {
    }
}
