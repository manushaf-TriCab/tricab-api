<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class DeleteProductDocumentFileCommand
{
    public function __construct(
        public string $documentPublicId,
    ) {
    }
}
