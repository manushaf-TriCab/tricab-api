<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use LogicException;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;

final class DisabledLegacyStaffUserMappingRepository implements LegacyStaffUserMappingRepository
{
    public function schemaReady(): bool
    {
        return false;
    }

    public function findByLegacyWordPressUserId(int $legacyWordPressUserId): ?array
    {
        unset($legacyWordPressUserId);

        return null;
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?array
    {
        unset($normalizedEmail);

        return null;
    }

    public function insertMapping(array $data): void
    {
        unset($data);
        throw new LogicException('Staff migration execution requires USER_REPOSITORY=azure-sql.');
    }

    public function countMappings(?string $batchPublicId = null): int
    {
        unset($batchPublicId);

        return 0;
    }

    public function listByBatchPublicId(string $batchPublicId): array
    {
        unset($batchPublicId);

        return [];
    }
}
