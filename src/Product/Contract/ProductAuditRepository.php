<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Future Azure SQL: product.ProductAuditEvents
 * Sensitive pricing values must be masked by the application service before recording.
 */
interface ProductAuditRepository
{
    /**
     * @return list<ProductAuditEntry>
     */
    public function listByProduct(ProductPublicId $productPublicId, int $limit = 50): array;

    /**
     * @param array<string, mixed>|null $previousValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        ?ProductPublicId $productPublicId,
        string $entityTypeCode,
        ?int $entityId,
        string $actionCode,
        ?UserId $actorUserId,
        ?string $correlationId,
        ?string $sourceCode,
        ?array $previousValues,
        ?array $newValues,
    ): void;
}
