<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

interface ProductPageContentRepository
{
    public function find(ProductPublicId $productPublicId): ProductPageContent;

    /**
     * @param string|null $expectedVersion the `version` the administrator last loaded (null = they saw no settings row);
     *                                      a different stored version means another administrator saved meanwhile
     *
     * @throws \TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict
     */
    public function save(ProductPublicId $productPublicId, ProductPageContent $content, UserId $actorUserId, ?string $expectedVersion): void;
}
