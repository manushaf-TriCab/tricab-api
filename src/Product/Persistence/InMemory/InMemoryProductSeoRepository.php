<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

final class InMemoryProductSeoRepository implements ProductSeoRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findPrimarySlug(ProductPublicId $productPublicId): ?ProductSlug
    {
        foreach ($this->store->slugs() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId->toString()
                && ($row['isPrimary'] ?? false)
                && ($row['regionPublicId'] ?? null) === null
            ) {
                return ProductSlug::fromString(AzureSqlValueMapper::stringValue($row['slug'] ?? ''));
            }
        }

        return null;
    }

    public function findSeo(ProductPublicId $productPublicId, ?string $regionPublicId = null): ?array
    {
        $key = $this->store->slugKey($productPublicId->toString(), $regionPublicId);
        $row = $this->store->seoEntries()[$key] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'pageTitle' => AzureSqlValueMapper::nullableString($row['pageTitle'] ?? null),
            'metaDescription' => AzureSqlValueMapper::nullableString($row['metaDescription'] ?? null),
            'canonicalUrl' => AzureSqlValueMapper::nullableString($row['canonicalUrl'] ?? null),
            'openGraphTitle' => AzureSqlValueMapper::nullableString($row['openGraphTitle'] ?? null),
            'openGraphDescription' => AzureSqlValueMapper::nullableString($row['openGraphDescription'] ?? null),
            'openGraphImageUrl' => AzureSqlValueMapper::nullableString($row['openGraphImageUrl'] ?? null),
            'searchKeywords' => AzureSqlValueMapper::nullableString($row['searchKeywords'] ?? null),
            'regionPublicId' => AzureSqlValueMapper::nullableString($row['regionPublicId'] ?? null),
        ];
    }

    public function upsertSlug(
        ProductPublicId $productPublicId,
        ProductSlug $slug,
        bool $isPrimary,
        ?string $regionPublicId,
        UserId $actorUserId,
    ): void {
        $key = $this->store->slugKey($productPublicId->toString(), $regionPublicId);
        $this->store->setSlug($key, [
            'productPublicId' => $productPublicId->toString(),
            'regionPublicId' => $regionPublicId,
            'slug' => $slug->toString(),
            'isPrimary' => $isPrimary,
        ]);
    }

    public function upsertSeo(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): void {
        $regionPublicId = $data['regionPublicId'] ?? null;
        $key = $this->store->slugKey($productPublicId->toString(), $regionPublicId);
        $this->store->setSeo($key, $data);
    }

    public function createRedirect(
        ProductPublicId $productPublicId,
        string $fromSlug,
        string $toSlug,
        ?string $regionPublicId,
        int $httpStatusCode,
        UserId $actorUserId,
    ): void {
        $this->store->addRedirect([
            'productPublicId' => $productPublicId->toString(),
            'fromSlug' => $fromSlug,
            'toSlug' => $toSlug,
            'regionPublicId' => $regionPublicId,
            'httpStatusCode' => $httpStatusCode,
        ]);
    }
}
