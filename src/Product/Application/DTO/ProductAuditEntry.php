<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductAuditEntry
{
    public function __construct(
        public string $publicId,
        public ?string $productPublicId,
        public string $entityTypeCode,
        public ?int $entityId,
        public string $actionCode,
        public ?string $actorDisplayName,
        public ?string $correlationId,
        public ?string $sourceCode,
        public ?string $previousValuesJson,
        public ?string $newValuesJson,
        public string $createdAtUtc,
    ) {
    }
}
