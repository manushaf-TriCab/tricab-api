<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * @param array<string, string> $requiredHeaders
 */
final readonly class ProductDocumentDownloadResult
{
    /**
     * @param array<string, string> $requiredHeaders
     */
    public function __construct(
        public string $documentPublicId,
        public string $downloadUrl,
        public string $httpMethod,
        public array $requiredHeaders,
        public ?string $expiresAtUtc,
        public string $accessMode,
        public ?string $contentType = null,
        public ?string $originalFileName = null,
    ) {
    }
}
