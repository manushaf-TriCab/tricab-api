<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\PriceListLookupItem;
use TriCab\Core\Product\Application\DTO\PriceListRecord;
use TriCab\Core\Product\Application\DTO\PriceListUsage;

interface PriceListRepository
{
    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters */
    public function listAdmin(array $filters = []): array;

    public function getByPublicId(string $publicId, bool $includeDeleted = false): ?PriceListRecord;

    public function resolveByLegacyContext(string $sourceCode, string $contextType, string $contextCode): ?PriceListRecord;

    /** @return list<PriceListLookupItem> */
    public function listActiveLookups(): array;

    public function getUsage(string $publicId): PriceListUsage;

    public function codeExists(string $code, ?string $excludePublicId = null): bool;

    public function legacyContextExists(
        string $sourceCode,
        string $contextType,
        string $contextCode,
        ?string $excludePublicId = null,
    ): bool;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, UserId $actorUserId): PriceListRecord;

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $publicId, array $data, UserId $actorUserId): PriceListRecord;

    public function setActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): PriceListRecord;

    public function archive(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord;

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord;
}
