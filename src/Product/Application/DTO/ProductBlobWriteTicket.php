<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Short-lived blob upload or download ticket (SAS-shaped; no Azure SDK).
 *
 * @param array<string, string> $requiredHeaders
 */
final readonly class ProductBlobWriteTicket
{
    /**
     * @param array<string, string> $requiredHeaders
     */
    public function __construct(
        public string $uploadUrl,
        public string $httpMethod,
        public array $requiredHeaders,
        public string $expiresAtUtc,
        public string $container,
        public string $blobPath,
    ) {
    }
}
