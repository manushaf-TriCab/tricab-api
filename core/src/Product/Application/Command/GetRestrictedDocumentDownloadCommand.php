<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class GetRestrictedDocumentDownloadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $documentPublicId,
        public bool $actorHasDocumentsManagePermission = false,
        public int $downloadExpiresInSeconds = 300,
    ) {
    }
}
