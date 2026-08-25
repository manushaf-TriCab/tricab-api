<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;

final class InMemoryProductStyleMappingIdentitySource implements ProductStyleMappingIdentitySource
{
    /** @var list<ProductStyleMappingSnapshot> */
    private array $snapshots = [];

    /** @var list<UnresolvedLegacyStyleProduct> */
    private array $unresolved = [];

    public int $listCalls = 0;

    public function __construct(
        private int $wordpressReadCount = 0,
        private int $tcReadCount = 0,
        private int $filesystemReadCount = 0,
        private int $azureReadCount = 0,
    ) {
    }

    public function seed(ProductStyleMappingSnapshot $snapshot): void
    {
        $this->snapshots[] = $snapshot;
    }

    public function seedUnresolved(UnresolvedLegacyStyleProduct $row): void
    {
        $this->unresolved[] = $row;
    }

    public function listProductSnapshots(): array
    {
        ++$this->listCalls;

        return $this->snapshots;
    }

    public function listTargetProducts(): array
    {
        return $this->listProductSnapshots();
    }

    public function unresolvedLegacyProducts(): array
    {
        return $this->unresolved;
    }

    public function wordpressReads(): int
    {
        return $this->wordpressReadCount;
    }

    public function tcReads(): int
    {
        return $this->tcReadCount;
    }

    public function filesystemReads(): int
    {
        return $this->filesystemReadCount;
    }

    public function azureReads(): int
    {
        return $this->azureReadCount;
    }
}
