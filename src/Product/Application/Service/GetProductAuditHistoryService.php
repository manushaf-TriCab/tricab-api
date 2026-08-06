<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductAuditHistoryService
{
    public function __construct(
        private readonly ProductAuditRepository $audit,
    ) {
    }

    /**
     * @return list<ProductAuditEntry>
     */
    public function execute(string $publicId, int $limit = 50): array
    {
        return $this->audit->listByProduct(ProductPublicId::fromString($publicId), $limit);
    }
}
