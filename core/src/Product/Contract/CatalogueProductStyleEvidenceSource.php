<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

/**
 * Read-only snapshot for CatalogueNode → ProductStyle dry-run discovery.
 * Never writes catalogue, product, style, or region data.
 */
interface CatalogueProductStyleEvidenceSource
{
    /**
     * @return array{
     *   nodes: list<array<string, mixed>>,
     *   directProducts: list<array<string, mixed>>,
     *   styleAssignments: list<array<string, mixed>>,
     *   productStyles: list<array<string, mixed>>,
     *   products: list<array<string, mixed>>,
     *   productRegions: list<array<string, mixed>>,
     *   regions: list<string>
     * }
     */
    public function loadSnapshot(): array;
}
