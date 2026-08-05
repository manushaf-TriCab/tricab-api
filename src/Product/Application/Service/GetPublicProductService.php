<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductPublicQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

final class GetPublicProductService
{
    public function __construct(
        private readonly ProductPublicQueryRepository $publicQuery,
    ) {
    }

    public function bySlug(string $slug, ?string $regionCode = null): ProductPublicDetail
    {
        $detail = $this->publicQuery->findBySlug(ProductSlug::fromString($slug), $regionCode);
        if ($detail === null) {
            throw ProductNotFound::forSlug($slug);
        }

        return $detail;
    }

    public function byPublicId(string $publicId, ?string $regionCode = null): ProductPublicDetail
    {
        $detail = $this->publicQuery->findByPublicId(ProductPublicId::fromString($publicId), $regionCode);
        if ($detail === null) {
            throw ProductNotFound::forPublicId($publicId);
        }

        return $detail;
    }
}
