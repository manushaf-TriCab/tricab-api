<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompleteProductDocumentUploadCommand
{
    public function __construct(
        public string $documentPublicId,
        /** Optional binary for InMemory/dev put when browser cannot reach memory:// URLs. */
        public ?string $binaryContent = null,
    ) {
    }
}
