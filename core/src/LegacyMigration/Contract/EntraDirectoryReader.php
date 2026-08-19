<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * Read-only Microsoft Entra / Graph directory access.
 */
interface EntraDirectoryReader
{
    public function isConfigured(): bool;

    /**
     * @return list<array{
     *   objectId: string,
     *   userPrincipalName: string,
     *   mail: string,
     *   displayName: string,
     *   accountEnabled: bool,
     *   tenantId: string
     * }>
     */
    public function listStaffDirectoryUsers(string $expectedTenantId): array;

    /** @return array{readOperations: int, writeOperations: int} */
    public function auditSummary(): array;

    public function clearTokenCache(): void;

    /**
     * Minimal read-only directory probe. Never writes to Graph.
     *
     * @return array{
     *   configured: bool,
     *   authenticated: bool,
     *   directoryRead: bool,
     *   httpStatus: int|null,
     *   graphErrorCode: string|null,
     *   writeOperations: int
     * }
     */
    public function verifyHealth(string $expectedTenantId): array;
}
