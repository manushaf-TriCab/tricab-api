<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/** In-memory base-cost store for unit tests. */
final class InMemoryVariantBaseCostRepository implements VariantBaseCostRepository
{
    /** @param array<string, array<string, mixed>> $byVariantPublicId */
    public function __construct(private array $byVariantPublicId = [])
    {
    }

    public function seed(string $variantPublicId, array $row): void
    {
        $this->byVariantPublicId[$variantPublicId] = $row;
    }

    public function findActiveBaseCost(string $variantPublicId, string $asOfDateUtc): ?array
    {
        unset($asOfDateUtc);

        return $this->byVariantPublicId[$variantPublicId] ?? null;
    }

    public function findActiveBaseCosts(array $variantPublicIds, string $asOfDateUtc): array
    {
        $out = [];
        foreach ($variantPublicIds as $id) {
            $row = $this->findActiveBaseCost($id, $asOfDateUtc);
            if ($row !== null) {
                $out[$id] = $row;
            }
        }

        return $out;
    }
}
