<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface LegacyStaffUserMappingRepository
{
    public function schemaReady(): bool;

    public function findByLegacyWordPressUserId(int $legacyWordPressUserId): ?array;

    public function findByNormalizedEmail(string $normalizedEmail): ?array;

    /**
     * @param array{
     *   legacyWordPressUserId: int,
     *   legacyLogin: string,
     *   legacyEmailAddress: string,
     *   normalizedEmailAddress: string,
     *   userId: int,
     *   batchPublicId: string,
     *   sourceFingerprint: string,
     *   migrationStatusCode: string
     * } $data
     */
    public function insertMapping(array $data): void;

    public function countMappings(?string $batchPublicId = null): int;

    /** @return list<array<string, mixed>> */
    public function listByBatchPublicId(string $batchPublicId): array;
}
