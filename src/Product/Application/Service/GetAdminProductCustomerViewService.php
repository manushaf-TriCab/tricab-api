<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductCustomerViewQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetAdminProductCustomerViewService
{
    public function __construct(
        private readonly ProductCustomerViewQueryRepository $customerViewQuery,
    ) {
    }

    public function execute(string $publicId, ?string $regionCode = null): ProductCustomerViewDetail
    {
        $detail = $this->customerViewQuery->findByPublicId(
            ProductPublicId::fromString($publicId),
            $regionCode,
        );
        if ($detail === null) {
            throw ProductNotFound::forPublicId($publicId);
        }

        return $detail;
    }
}
