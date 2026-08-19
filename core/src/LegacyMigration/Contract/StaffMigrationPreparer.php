<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * Fresh read-only staff candidate preparation for dry-run and execute.
 */
interface StaffMigrationPreparer
{
    /**
     * @return array{
     *   analysis: array<string, mixed>,
     *   candidates: list<\TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationCandidate>,
     *   dryRun: array<string, mixed>,
     *   legacyAudit: \TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit
     * }
     */
    public function prepare(): array;
}
