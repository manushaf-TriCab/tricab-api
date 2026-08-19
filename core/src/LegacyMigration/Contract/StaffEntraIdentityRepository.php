<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface StaffEntraIdentityRepository
{
    /** @return list<string> */
    public function listProviderCodes(): array;

    /** @return list<array<string, mixed>> */
    public function listAllStaffMappings(): array;

    /** @return array<int, array<string, mixed>> keyed by UserId */
    public function loadTriCabUsersByIds(array $userIds): array;

    /**
     * @param list<string> $normalizedEmails
     * @return array<string, list<array<string, mixed>>> email => users
     */
    public function loadTriCabUsersByEmails(array $normalizedEmails): array;

    /** @return array<string, array<string, mixed>> oid => identity row */
    public function loadEntraIdentitiesBySubject(): array;

    /** @return array<int, list<array<string, mixed>>> userId => identities */
    public function loadEntraIdentitiesByUserId(): array;

    /** @return array<int, array<string, mixed>> keyed by UserId — all @tricab.com users */
    public function loadCorporateStaffUsers(): array;
}
