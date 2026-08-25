<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Product\Contract\ProductStyleFamilyEvidenceSource;

final class InMemoryProductStyleFamilyEvidenceSource implements ProductStyleFamilyEvidenceSource
{
    /** @var array<string, array<string, mixed>> */
    private array $byProduct = [];

    /**
     * @param array<string, mixed> $evidence
     */
    public function seed(string $productPublicId, array $evidence): void
    {
        $this->byProduct[$productPublicId] = $evidence;
    }

    public function load(array $productPublicIds): array
    {
        $out = [];
        foreach ($productPublicIds as $id) {
            if (isset($this->byProduct[$id])) {
                $out[$id] = $this->byProduct[$id];
            }
        }

        return $out;
    }
}
