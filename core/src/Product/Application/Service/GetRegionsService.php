<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\DTO\RegionListResult;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\Exception\RegionNotFound;
use TriCab\Core\Product\Contract\RegionRepository;

final class GetRegionsService
{
    public function __construct(
        private readonly RegionRepository $regions,
    ) {
    }

    /** @return list<RegionRecord> */
    public function publicActive(): array
    {
        return $this->regions->listPublicActive();
    }

    public function adminList(RegionListCriteria $criteria): RegionListResult
    {
        return $this->regions->listAdmin($criteria);
    }

    public function get(string $publicId, bool $includeDeleted = false): RegionRecord
    {
        $region = $this->regions->findByPublicId($publicId, $includeDeleted);
        if ($region === null || (!$includeDeleted && $region->isDeleted)) {
            throw RegionNotFound::forPublicId($publicId);
        }

        return $region;
    }
}
