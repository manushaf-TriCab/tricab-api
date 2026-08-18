<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

/**
 * Future Azure SQL: ProductSEO / ProductSlugs / ProductRedirects / ProductRegionalContent / ProductContentSections
 *
 * @phpstan-type SeoPayload array{
 *   pageTitle?: ?string,
 *   metaDescription?: ?string,
 *   canonicalUrl?: ?string,
 *   openGraphTitle?: ?string,
 *   openGraphDescription?: ?string,
 *   openGraphImageUrl?: ?string,
 *   searchKeywords?: ?string,
 *   regionPublicId?: ?string
 * }
 */
interface ProductSeoRepository
{
    public function findPrimarySlug(ProductPublicId $productPublicId): ?ProductSlug;

    /**
     * @return SeoPayload|null
     */
    public function findSeo(ProductPublicId $productPublicId, ?string $regionPublicId = null): ?array;

    public function upsertSlug(
        ProductPublicId $productPublicId,
        ProductSlug $slug,
        bool $isPrimary,
        ?string $regionPublicId,
        UserId $actorUserId,
    ): void;

    /**
     * @param SeoPayload $data
     */
    public function upsertSeo(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): void;

    /**
     * Records a redirect from a previous slug to a new one (Phase 3 ProductRedirects table).
     */
    public function createRedirect(
        ProductPublicId $productPublicId,
        string $fromSlug,
        string $toSlug,
        ?string $regionPublicId,
        int $httpStatusCode,
        UserId $actorUserId,
    ): void;
}
