<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * @param array<string, string> $requiredHeaders
 */
final readonly class ProductUploadSessionResult
{
    /**
     * @param array<string, string> $requiredHeaders
     */
    public function __construct(
        public string $entityPublicId,
        public string $uploadUrl,
        public string $httpMethod,
        public array $requiredHeaders,
        public string $expiresAtUtc,
        public string $storagePath,
        public string $storageContainer,
        public string $fileKind,
    ) {
    }
}
