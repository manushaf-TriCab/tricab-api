<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductPageContentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductPageContentRepository implements ProductPageContentRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function find(ProductPublicId $productPublicId): ProductPageContent
    {
        return $this->store->pageContentForProduct($productPublicId) ?? ProductPageContent::defaults();
    }

    public function save(ProductPublicId $productPublicId, ProductPageContent $content, UserId $actorUserId, ?string $expectedVersion): void
    {
        unset($actorUserId);
        $current = $this->store->pageContentForProduct($productPublicId)?->version();
        if ($current !== $expectedVersion) {
            throw ProductConcurrencyConflict::forPublicId($productPublicId->toString());
        }
        $this->store->setPageContent($productPublicId, $content->withVersion('v' . (((int) ltrim((string) $current, 'v')) + 1)));
    }
}
