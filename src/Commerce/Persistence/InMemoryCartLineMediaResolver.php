<?php

declare(strict_types=1);

namespace TriCab\Api\Commerce\Persistence;

use TriCab\Core\Commerce\Application\DTO\CartLineMedia;
use TriCab\Core\Commerce\Contract\CartLineMediaResolver;

final class InMemoryCartLineMediaResolver implements CartLineMediaResolver
{
    /** @var array<string, CartLineMedia> */
    private array $byVariantPublicId = [];

    public function seed(
        string $variantPublicId,
        ?string $productPublicId,
        ?string $imageUrl,
    ): void {
        $this->byVariantPublicId[$variantPublicId] = new CartLineMedia($productPublicId, $imageUrl);
    }

    public function resolveForVariantPublicIds(array $variantPublicIds): array
    {
        $out = [];
        foreach (array_values(array_unique($variantPublicIds)) as $id) {
            if ($id === '') {
                continue;
            }
            if (isset($this->byVariantPublicId[$id])) {
                $out[$id] = $this->byVariantPublicId[$id];
            }
        }

        return $out;
    }
}
