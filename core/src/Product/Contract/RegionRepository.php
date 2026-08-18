<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\DTO\RegionListResult;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\DTO\RegionUsage;
use TriCab\Core\Product\Application\DTO\RegionWriteData;

interface RegionRepository
{
    /** @return list<RegionRecord> */
    public function listPublicActive(): array;

    public function listAdmin(RegionListCriteria $criteria): RegionListResult;

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?RegionRecord;

    public function codeExists(string $code, ?string $excludePublicId = null): bool;

    public function anotherDefaultExists(?string $excludePublicId = null): bool;

    public function anotherDefaultWebsiteRegionExists(?string $excludePublicId = null): bool;

    public function create(RegionWriteData $data, UserId $actorUserId): RegionRecord;

    public function update(
        string $publicId,
        RegionWriteData $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord;

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord;

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): RegionRecord;

    public function usage(string $publicId): RegionUsage;
}
