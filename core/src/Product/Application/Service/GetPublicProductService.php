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
        private readonly ?EnrichPublicProductWithStyleInheritanceService $styleInheritance = null,
    ) {
    }

    /**
     * Resolve a published product from a URL segment.
     *
     * Catalogue cards fall back to the product public id when no primary slug
     * exists, so UUID path segments are looked up as public ids first.
     */
    public function bySlug(string $slug, ?string $regionCode = null): ProductPublicDetail
    {
        $trimmed = trim($slug);
        if (ProductPublicId::isValid($trimmed)) {
            $byPublicId = $this->publicQuery->findByPublicId(
                ProductPublicId::fromString($trimmed),
                $regionCode,
            );
            if ($byPublicId !== null) {
                return $this->enrich($byPublicId);
            }
        }

        $detail = $this->publicQuery->findBySlug(ProductSlug::fromString($trimmed), $regionCode);
        if ($detail === null) {
            throw ProductNotFound::forSlug($slug);
        }

        return $this->enrich($detail);
    }

    public function byPublicId(string $publicId, ?string $regionCode = null): ProductPublicDetail
    {
        $detail = $this->publicQuery->findByPublicId(ProductPublicId::fromString($publicId), $regionCode);
        if ($detail === null) {
            throw ProductNotFound::forPublicId($publicId);
        }

        return $this->enrich($detail);
    }

    private function enrich(ProductPublicDetail $detail): ProductPublicDetail
    {
        return $this->styleInheritance?->enrich($detail) ?? $detail;
    }
}
